<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Services;

use DateTime;
use RuntimeException;

/**
 * Leitura dos CSVs e dedução da janela de cobertura.
 *
 * Aceita dois formatos:
 *
 * 1. Relatório de Disponibilidade — uma linha por equipamento, com grupo,
 *    período, situação, SLA e indisponibilidade em segundos.
 * 2. Planilha em formato largo — uma linha por unidade e uma coluna por mês
 *    (JAN..DEZ, 2026-01, 01/2026), com coluna opcional de peso.
 *
 * A janela de cobertura de cada equipamento é deduzida de
 * indisponibilidade / ((100 - sla) / 100). Isso respeita 24x7 e expediente
 * comercial sem configuração, e mantém o consolidado coerente com o SLA que o
 * próprio relatório informou. Onde não dá para deduzir (SLA de 100% ou sem
 * tempo parado), entra a mediana das janelas do arquivo e, por último, o
 * intervalo entre as datas do período.
 */
final class Parser {

	/** Situações que não entram no cálculo. */
	private const FORA_DO_CALCULO = ['expurg', 'sem trigger'];

	private const MESES_PT = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
	private const MESES_EN = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

	/**
	 * @throws RuntimeException  Quando o arquivo não pode ser interpretado.
	 *
	 * @return array{medicoes: array<int, array<string, mixed>>, formato: string}
	 */
	public static function ler(string $nomeArquivo, string $conteudo): array {
		$linhas = self::linhasDoCsv($conteudo);

		if (count($linhas) < 2) {
			throw new RuntimeException(_('Arquivo sem linhas de dados.'));
		}

		$cabecalhos = $linhas[0];
		$indiceSla = self::indice($cabecalhos, '/^sla|sla \(%\)|disponibilidade|availability|^sli/');

		$colunasMes = [];

		foreach ($cabecalhos as $indice => $cabecalho) {
			$mes = self::mesDeCabecalho($cabecalho);

			if ($mes !== null) {
				$colunasMes[$indice] = $mes;
			}
		}

		if ($indiceSla < 0 && count($colunasMes) >= 2) {
			return self::lerFormatoLargo($cabecalhos, array_slice($linhas, 1), $colunasMes);
		}

		if ($indiceSla < 0) {
			throw new RuntimeException(_('Coluna de SLA não encontrada.'));
		}

		return self::lerRelatorio($nomeArquivo, $cabecalhos, array_slice($linhas, 1), $indiceSla);
	}

	// ── Normalizações ───────────────────────────────────────────────────────

	public static function semAcento(string $texto): string {
		$texto = trim($texto);
		$transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

		return mb_strtolower($transliterado !== false ? $transliterado : $texto);
	}

	/**
	 * Aceita 99,81 / 1.234,56 / 99.81% / N/D.
	 */
	public static function numero($valor): ?float {
		if ($valor === null) {
			return null;
		}

		if (is_int($valor) || is_float($valor)) {
			return (float) $valor;
		}

		$texto = trim(str_replace(["%", " ", "\xc2\xa0"], '', (string) $valor));

		if ($texto === '' || preg_match('/^n\/?d$|^n\/?a$|^-{1,2}$|^null$/i', $texto) === 1) {
			return null;
		}

		$temVirgula = strpos($texto, ',') !== false;
		$temPonto = strpos($texto, '.') !== false;

		if ($temVirgula && $temPonto) {
			// "1.234,56": remove primeiro o ponto de milhar, só depois troca a
			// vírgula decimal por ponto — na ordem contrária, o replace do ponto
			// apagaria também o ponto decimal recém-criado.
			$texto = strrpos($texto, ',') > strrpos($texto, '.')
				? str_replace(',', '.', str_replace('.', '', $texto))
				: str_replace(',', '', $texto);
		}
		elseif ($temVirgula) {
			$texto = str_replace(',', '.', $texto);
		}

		return is_numeric($texto) ? (float) $texto : null;
	}

	/**
	 * Aceita dd/mm/aaaa e aaaa-mm-dd, com hora opcional.
	 */
	public static function data(?string $texto): ?DateTime {
		if ($texto === null || trim($texto) === '') {
			return null;
		}

		$valor = trim($texto);

		if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})(?:[ T](\d{1,2}):(\d{2}))?#', $valor, $m) === 1) {
			$ano = (int) $m[3];
			$ano += $ano < 100 ? 2000 : 0;

			return (new DateTime())->setDate($ano, (int) $m[2], (int) $m[1])
				->setTime((int) ($m[4] ?? 0), (int) ($m[5] ?? 0));
		}

		if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?#', $valor, $m) === 1) {
			return (new DateTime())->setDate((int) $m[1], (int) $m[2], (int) $m[3])
				->setTime((int) ($m[4] ?? 0), (int) ($m[5] ?? 0));
		}

		return null;
	}

	/**
	 * Semana-calendário dentro do mês, de 1 a 6, com semanas de domingo a sábado.
	 *
	 * A semana 1 é a que contém o dia 1, ainda que comece no mês anterior; a
	 * troca de semana acontece todo domingo. Setembro/2026 começa numa
	 * terça: dias 1–5 são a semana 1, 6–12 a semana 2, e assim por diante.
	 * É a mesma convenção da planilha da diretoria (1 SEM … 6 SEM).
	 */
	public static function semanaDoMes(DateTime $data): int {
		$dia = (int) $data->format('j');
		$domingoZero = (int) (clone $data)->setDate((int) $data->format('Y'), (int) $data->format('n'), 1)->format('w');

		return intdiv($dia + $domingoZero - 1, 7) + 1;
	}

	/**
	 * Devolve 1..12 quando o cabeçalho é um mês.
	 */
	public static function mesDeCabecalho(string $cabecalho): ?int {
		$texto = self::semAcento($cabecalho);

		foreach (self::MESES_PT as $indice => $prefixo) {
			if (strpos($texto, $prefixo) === 0) {
				return $indice + 1;
			}
		}

		if (mb_strlen($texto) <= 10) {
			foreach (self::MESES_EN as $indice => $prefixo) {
				if (strpos($texto, $prefixo) === 0) {
					return $indice + 1;
				}
			}
		}

		if (preg_match('/^(\d{4})[-\/](\d{1,2})$/', $texto, $m) === 1) {
			return (int) $m[2];
		}

		if (preg_match('/^(\d{1,2})[-\/](\d{4})$/', $texto, $m) === 1) {
			return (int) $m[1];
		}

		return null;
	}

	/**
	 * @param array<int, string> $cabecalhos
	 */
	private static function indice(array $cabecalhos, string $padrao): int {
		foreach ($cabecalhos as $indice => $cabecalho) {
			if (preg_match($padrao.'i', self::semAcento($cabecalho)) === 1) {
				return $indice;
			}
		}

		return -1;
	}

	private static function delimitador(string $primeiraLinha): string {
		$candidatos = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
		$dentroDeAspas = false;

		foreach (str_split($primeiraLinha) as $caractere) {
			if ($caractere === '"') {
				$dentroDeAspas = !$dentroDeAspas;
			}
			elseif (!$dentroDeAspas && array_key_exists($caractere, $candidatos)) {
				$candidatos[$caractere]++;
			}
		}

		arsort($candidatos);

		return (string) array_key_first($candidatos) ?: ';';
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	private static function linhasDoCsv(string $conteudo): array {
		$conteudo = ltrim($conteudo, "\xEF\xBB\xBF");
		$linhaBruta = explode("\n", $conteudo, 2)[0] ?? '';
		$delimitador = self::delimitador($linhaBruta);

		$identificador = fopen('php://temp', 'r+');
		fwrite($identificador, $conteudo);
		rewind($identificador);

		$linhas = [];

		while (($linha = fgetcsv($identificador, 0, $delimitador, '"', '\\')) !== false) {
			if (array_filter($linha, static fn($c) => trim((string) $c) !== '') !== []) {
				$linhas[] = $linha;
			}
		}

		fclose($identificador);

		return $linhas;
	}

	// ── Leitura ──────────────────────────────────────────────────────────────

	/**
	 * @param array<int, string> $cabecalhos
	 * @param array<int, array<int, string>> $linhas
	 *
	 * @return array{medicoes: array<int, array<string, mixed>>, formato: string}
	 */
	private static function lerRelatorio(string $nomeArquivo, array $cabecalhos, array $linhas, int $indiceSla): array {
		$idx = [
			'grupo' => self::indice($cabecalhos, '/grupo/'),
			'ini' => self::indice($cabecalhos, '/periodo inicial|data inicial|inicio|^de$|^from$/'),
			'fim' => self::indice($cabecalhos, '/periodo final|data final|^fim$|^ate$|^till$/'),
			'equip' => self::indice($cabecalhos, '/equipamento|^host|nome do host|dispositivo|ativo/'),
			'sit' => self::indice($cabecalhos, '/situacao|^status/'),
			'meta' => self::indice($cabecalhos, '/meta|slo/'),
			'down' => self::indice($cabecalhos, '/indisponibilidade \(segundos\)|segundos|downtime/'),
			'inc' => self::indice($cabecalhos, '/incidente/'),
			'obs' => self::indice($cabecalhos, '/observ/')
		];

		$campo = static function (array $linha, string $chave) use ($idx): string {
			$posicao = $idx[$chave];

			return ($posicao >= 0 && array_key_exists($posicao, $linha)) ? (string) $linha[$posicao] : '';
		};

		[$mesDoNome, $anoDoNome] = self::periodoDoNome($nomeArquivo);
		$brutas = [];

		foreach ($linhas as $linha) {
			$grupo = trim($campo($linha, 'grupo'));
			$equipamento = trim($campo($linha, 'equip'));

			if ($grupo === '' && $equipamento === '') {
				continue;
			}

			$inicio = self::data($campo($linha, 'ini'));
			$fim = self::data($campo($linha, 'fim'));

			if ($inicio !== null) {
				$mes = (int) $inicio->format('n');
				$ano = (int) $inicio->format('Y');
			}
			elseif ($mesDoNome !== null) {
				$mes = $mesDoNome;
				$ano = $anoDoNome;
			}
			else {
				continue; // sem período não há como colocar no quadro mensal
			}

			$situacao = trim($campo($linha, 'sit'));
			$sla = self::numero($indiceSla >= 0 && array_key_exists($indiceSla, $linha) ? $linha[$indiceSla] : null);
			$down = self::numero($campo($linha, 'down'));
			$fora = false;

			foreach (self::FORA_DO_CALCULO as $marca) {
				if (strpos(self::semAcento($situacao), $marca) !== false) {
					$fora = true;
					break;
				}
			}

			$janelaPeriodo = null;

			if ($inicio !== null && $fim !== null && $fim >= $inicio) {
				$janelaPeriodo = (float) ($fim->getTimestamp() - $inicio->getTimestamp());

				if ((int) $inicio->format('G') === 0 && (int) $fim->format('G') === 0) {
					$janelaPeriodo += 86400;
				}
			}

			$brutas[] = [
				'medicao' => [
					'grupo' => $grupo !== '' ? $grupo : $equipamento,
					'equipamento' => $equipamento !== '' ? $equipamento : $grupo,
					'situacao' => $situacao,
					'ano' => $ano,
					'mes' => $mes,
					'periodo_inicio' => $inicio !== null ? $inicio->format('Y-m-d') : null,
					'periodo_fim' => $fim !== null ? $fim->format('Y-m-d') : null,
					'semana' => $inicio !== null ? self::semanaDoMes($inicio) : null,
					'sla' => $sla,
					'meta_slo' => self::numero($campo($linha, 'meta')),
					'indisponibilidade_s' => $down,
					'janela_s' => null,
					'incidentes' => (int) (self::numero($campo($linha, 'inc')) ?? 0),
					'peso' => 1.0,
					'calculavel' => !$fora && $sla !== null,
					'observacao' => trim($campo($linha, 'obs'))
				],
				'janela_periodo' => $janelaPeriodo
			];
		}

		if ($brutas === []) {
			throw new RuntimeException(_('Nenhuma linha de dados válida.'));
		}

		self::deduzirJanelas($brutas);

		return ['medicoes' => array_column($brutas, 'medicao'), 'formato' => 'relatorio'];
	}

	/**
	 * @param array<int, array{medicao: array<string, mixed>, janela_periodo: ?float}> $brutas
	 */
	private static function deduzirJanelas(array &$brutas): void {
		$deduzidas = [];

		foreach ($brutas as &$item) {
			$m = &$item['medicao'];

			if ($m['sla'] !== null && $m['sla'] < 100 && $m['indisponibilidade_s']) {
				$janela = $m['indisponibilidade_s'] / ((100 - $m['sla']) / 100);

				if ($janela > 0) {
					$m['janela_s'] = round($janela, 2);
					$deduzidas[] = $janela;
				}
			}
		}
		unset($item, $m);

		sort($deduzidas);
		$mediana = self::mediana($deduzidas);

		foreach ($brutas as &$item) {
			$m = &$item['medicao'];

			if ($m['janela_s'] === null) {
				$base = $mediana ?? $item['janela_periodo'];
				$m['janela_s'] = $base ? round($base, 2) : null;
			}

			if ($m['indisponibilidade_s'] === null && $m['sla'] !== null && $m['janela_s']) {
				$m['indisponibilidade_s'] = round($m['janela_s'] * (100 - $m['sla']) / 100, 2);
			}
		}
		unset($item, $m);
	}

	/**
	 * @param array<int, float> $valores  Já ordenados.
	 */
	private static function mediana(array $valores): ?float {
		$total = count($valores);

		if ($total === 0) {
			return null;
		}

		$meio = intdiv($total, 2);

		return $total % 2 === 0 ? ($valores[$meio - 1] + $valores[$meio]) / 2 : $valores[$meio];
	}

	/**
	 * @param array<int, string> $cabecalhos
	 * @param array<int, array<int, string>> $linhas
	 * @param array<int, int> $colunasMes  posição => mês (1..12)
	 *
	 * @return array{medicoes: array<int, array<string, mixed>>, formato: string}
	 */
	private static function lerFormatoLargo(array $cabecalhos, array $linhas, array $colunasMes): array {
		$posicoesMes = array_keys($colunasMes);
		$indiceNome = 0;

		foreach ($cabecalhos as $indice => $cabecalho) {
			if (!in_array($indice, $posicoesMes, true) && self::semAcento($cabecalho) !== '') {
				$indiceNome = $indice;
				break;
			}
		}

		$indicePeso = self::indice($cabecalhos, '/peso|qtd|quantidade|hosts|sites|ponder/');
		$indiceAno = self::indice($cabecalhos, '/^ano$|year/');
		$anoPadrao = (int) date('Y');
		$medicoes = [];

		foreach ($linhas as $linha) {
			$nome = trim($linha[$indiceNome] ?? '');

			if ($nome === '' || preg_match('/^(meta|desafio|geral|total|acum)/', self::semAcento($nome)) === 1) {
				continue;
			}

			$peso = ($indicePeso >= 0 ? self::numero($linha[$indicePeso] ?? null) : null) ?? 1.0;
			$ano = (int) (($indiceAno >= 0 ? self::numero($linha[$indiceAno] ?? null) : null) ?? $anoPadrao);

			foreach ($colunasMes as $posicao => $mes) {
				$valor = self::numero($linha[$posicao] ?? null);

				if ($valor === null) {
					continue;
				}

				$medicoes[] = [
					'grupo' => $nome, 'equipamento' => $nome, 'situacao' => '',
					'ano' => $ano, 'mes' => $mes, 'periodo_inicio' => null, 'periodo_fim' => null, 'semana' => null,
					'sla' => $valor <= 1 ? $valor * 100 : $valor,
					'meta_slo' => null, 'indisponibilidade_s' => null, 'janela_s' => null,
					'incidentes' => 0, 'peso' => $peso, 'calculavel' => true, 'observacao' => ''
				];
			}
		}

		if ($medicoes === []) {
			throw new RuntimeException(_('Nenhum valor mensal reconhecido.'));
		}

		return ['medicoes' => $medicoes, 'formato' => 'largo'];
	}

	/**
	 * @return array{0: ?int, 1: ?int}  [mês, ano]
	 */
	private static function periodoDoNome(string $nomeArquivo): array {
		if (preg_match('/(20\d{2})[-_.]?(0[1-9]|1[0-2])/', $nomeArquivo, $m) === 1) {
			return [(int) $m[2], (int) $m[1]];
		}

		if (preg_match('/(0[1-9]|1[0-2])[-_.]?(20\d{2})/', $nomeArquivo, $m) === 1) {
			return [(int) $m[1], (int) $m[2]];
		}

		return [null, null];
	}
}
