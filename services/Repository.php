<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Regras de negócio do SLA Executivo.
 *
 * Concentra o que antes vivia em duas peças separadas (app.py + analytics.py da
 * versão em stack): leitura e gravação de parâmetros, importação de CSV,
 * consolidação do quadro executivo e exportações. Tudo em cima de Db, que fala
 * com o mesmo Postgres do Zabbix.
 */
final class Repository {

	private const MESES = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

	private const AGREGADOS = "
		sum(indisponibilidade_s) FILTER (WHERE janela_s > 0 AND indisponibilidade_s IS NOT NULL) AS down,
		sum(janela_s)            FILTER (WHERE janela_s > 0 AND indisponibilidade_s IS NOT NULL) AS janela,
		sum(sla * peso) AS sla_peso,
		sum(peso)       AS peso,
		sum(incidentes) AS incidentes,
		count(*)        AS linhas
	";

	private const FILTRO = 'calculavel AND sla IS NOT NULL AND ano = ?';

	/**
	 * Data/hora do Postgres (ex.: "2026-09-08 14:01:31.238176+00") convertida
	 * para ISO 8601 de verdade. O offset do Postgres não tem dois-pontos
	 * ("+00"), e o Date() do JavaScript recusa esse formato — troca de espaço
	 * por "T" não bastava; sem passar por DateTime, a tela mostrava
	 * "Invalid Date" no rodapé.
	 */
	private static function iso(?string $timestamp): ?string {
		if ($timestamp === null || $timestamp === '') {
			return null;
		}

		try {
			return (new \DateTime($timestamp))->format(\DateTime::ATOM);
		}
		catch (\Exception $e) {
			return $timestamp;
		}
	}

	// ── Saúde ────────────────────────────────────────────────────────────────

	/**
	 * @return array{ok: bool, medicoes: int, usando_ambiente: bool, erro: string}
	 */
	public static function saude(): array {
		try {
			$linha = Db::consultarUm('SELECT count(*) AS medicoes FROM sla_medicao');

			return [
				'ok' => true,
				'medicoes' => (int) ($linha['medicoes'] ?? 0),
				'usando_ambiente' => Db::usandoAmbiente(),
				'erro' => ''
			];
		}
		catch (RuntimeException $e) {
			return ['ok' => false, 'medicoes' => 0, 'usando_ambiente' => Db::usandoAmbiente(), 'erro' => $e->getMessage()];
		}
	}

	// ── Parâmetros ───────────────────────────────────────────────────────────

	/**
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$linha = Db::consultarUm('SELECT * FROM sla_config WHERE id = 1');

		if ($linha === null) {
			throw new RuntimeException(_('Configuração do SLA Executivo não encontrada.'));
		}

		return [
			'titulo' => $linha['titulo'],
			'meta' => (float) $linha['meta'],
			'desafio' => (float) $linha['desafio'],
			'metodo' => $linha['metodo'],
			'ranking' => $linha['ranking'],
			'decimais' => (int) $linha['decimais'],
			'logo_url' => $linha['logo_url'],
			'atualizado_em' => self::iso($linha['atualizado_em']),
			'atualizado_por' => $linha['atualizado_por']
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function categorias(): array {
		$linhas = Db::consultar('SELECT * FROM sla_categoria ORDER BY ordem, nome');

		return array_map(static fn(array $c) => [
			'id' => $c['id'], 'nome' => $c['nome'], 'sigla' => $c['sigla'],
			'cor' => $c['cor'], 'padrao' => $c['padrao'], 'ordem' => (int) $c['ordem'],
			'peso' => (float) ($c['peso_negocio'] ?? 0)
		], $linhas);
	}

	/**
	 * @return array<int, int>
	 */
	public static function anosDisponiveis(): array {
		$linhas = Db::consultar('SELECT DISTINCT ano FROM sla_medicao ORDER BY ano DESC');

		return array_map(static fn(array $l) => (int) $l['ano'], $linhas);
	}

	/**
	 * @param array<string, mixed> $corpo
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return array<string, mixed>
	 */
	public static function salvarConfig(array $corpo, string $usuario): array {
		$titulo = mb_substr(trim((string) ($corpo['titulo'] ?? '')), 0, 34);

		if ($titulo === '') {
			throw new InvalidArgumentException(_('Informe o nome do indicador.'));
		}

		$meta = self::percentual($corpo['meta'] ?? null, _('Meta'));
		$desafio = self::percentual($corpo['desafio'] ?? null, _('Desafio'));

		if ($desafio < $meta) {
			throw new InvalidArgumentException(_('O desafio precisa ser igual ou maior que a meta.'));
		}

		$metodo = (string) ($corpo['metodo'] ?? 'ponderada');
		$metodo = in_array($metodo, ['ponderada', 'media'], true) ? $metodo : 'ponderada';

		$ranking = (string) ($corpo['ranking'] ?? 'grupo');
		$ranking = in_array($ranking, ['grupo', 'equip', 'cat'], true) ? $ranking : 'grupo';

		$decimais = (int) ($corpo['decimais'] ?? 2);
		$decimais = min(max($decimais, 2), 4);

		$logo = trim((string) ($corpo['logo_url'] ?? ''));

		if ($logo !== '' && (preg_match('#^[A-Za-z0-9._/-]+$#', $logo) !== 1 || strpos($logo, '..') !== false)) {
			throw new InvalidArgumentException(_('Caminho de logotipo inválido.'));
		}

		Db::executar(
			'UPDATE sla_config SET titulo = ?, meta = ?, desafio = ?, metodo = ?,
				ranking = ?, decimais = ?, logo_url = ?, atualizado_em = now(), atualizado_por = ?
			 WHERE id = 1',
			[$titulo, $meta, $desafio, $metodo, $ranking, $decimais, $logo, $usuario]
		);
		Db::auditar($usuario, 'config.update', ['meta' => $meta, 'desafio' => $desafio, 'metodo' => $metodo]);

		return self::config();
	}

	private static function percentual($valor, string $rotulo): float {
		if (is_string($valor)) {
			$valor = str_replace(',', '.', trim($valor));
		}

		if (!is_numeric($valor)) {
			throw new InvalidArgumentException(sprintf(_('%1$s: informe um número entre 0 e 100.'), $rotulo));
		}

		$numero = (float) $valor;

		if ($numero < 0 || $numero > 100) {
			throw new InvalidArgumentException(sprintf(_('%1$s: informe um número entre 0 e 100.'), $rotulo));
		}

		return round($numero, 4);
	}

	/**
	 * @param array<int, array<string, mixed>> $itens
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function salvarCategorias(array $itens, string $usuario): array {
		if ($itens === []) {
			throw new InvalidArgumentException(_('Envie ao menos uma categoria.'));
		}

		$limpos = [];
		$vistos = [];

		foreach (array_slice($itens, 0, 12) as $ordem => $item) {
			$nome = mb_substr(trim((string) ($item['nome'] ?? '')), 0, 40);

			if ($nome === '') {
				continue;
			}

			$id = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($item['id'] ?? ''));
			$id = mb_substr((string) $id, 0, 16);

			if ($id === '' || in_array($id, $vistos, true)) {
				$id = 'C'.$ordem;
			}

			$vistos[] = $id;
			$cor = (string) ($item['cor'] ?? '');
			$cor = preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) === 1 ? $cor : '#5B6879';
			$padrao = mb_substr(trim((string) ($item['padrao'] ?? '')), 0, 200);

			if ($padrao !== '' && @preg_match('/'.str_replace('/', '\/', $padrao).'/iu', '') === false) {
				throw new InvalidArgumentException(sprintf(_('Padrão de classificação inválido: %1$s'), $padrao));
			}

			$limpos[] = [$id, $nome, mb_strtoupper(mb_substr((string) ($item['sigla'] ?? $nome), 0, 4)), $cor, $padrao, ($ordem + 1) * 10];
		}

		if ($limpos === []) {
			throw new InvalidArgumentException(_('Nenhuma categoria válida.'));
		}

		$pdo = Db::conexao();
		$pdo->beginTransaction();

		try {
			$ids = array_column($limpos, 0);
			$marcadores = implode(',', array_fill(0, count($ids), '?'));
			$pdo->prepare("DELETE FROM sla_categoria WHERE id NOT IN ($marcadores)")->execute($ids);

			$upsert = $pdo->prepare('
				INSERT INTO sla_categoria (id, nome, sigla, cor, padrao, ordem) VALUES (?, ?, ?, ?, ?, ?)
				ON CONFLICT (id) DO UPDATE SET nome = EXCLUDED.nome, sigla = EXCLUDED.sigla,
					cor = EXCLUDED.cor, padrao = EXCLUDED.padrao, ordem = EXCLUDED.ordem
			');

			foreach ($limpos as $categoria) {
				$upsert->execute($categoria);
			}

			$pdo->commit();
		}
		catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}

		Db::auditar($usuario, 'categorias.update', ['total' => count($limpos)]);

		return self::categorias();
	}

	/**
	 * @param array<string, string> $mapa
	 */
	public static function salvarMapa(array $mapa, string $usuario): void {
		$validos = array_column(self::categorias(), 'id');
		$pdo = Db::conexao();
		$comando = $pdo->prepare('
			INSERT INTO sla_grupo_categoria (grupo, categoria_id, definido_por) VALUES (?, ?, ?)
			ON CONFLICT (grupo) DO UPDATE SET categoria_id = EXCLUDED.categoria_id,
				definido_em = now(), definido_por = EXCLUDED.definido_por
		');

		foreach ($mapa as $grupo => $categoria) {
			$grupo = mb_substr(trim((string) $grupo), 0, 255);

			if ($grupo === '' || !in_array($categoria, $validos, true)) {
				continue;
			}

			$comando->execute([$grupo, $categoria, $usuario]);
		}

		Db::auditar($usuario, 'mapa.update', ['grupos' => count($mapa)]);
	}

	// ── Consolidado ──────────────────────────────────────────────────────────

	/**
	 * @return array<string, mixed>
	 */
	public static function consolidado(?int $ano): array {
		$parametros = self::config();
		$metodo = $parametros['metodo'];
		$meta = $parametros['meta'];
		$todas = self::categorias();
		$anos = self::anosDisponiveis();
		$ano = $ano ?? ($anos[0] ?? (int) date('Y'));

		$valor = static function (?array $linha) use ($metodo): ?float {
			return self::valorAgregado($linha, $metodo);
		};

		// ── Série mensal geral ─────────────────────────────────────────────
		$porMes = [];

		foreach (Db::consultar('SELECT mes, '.self::AGREGADOS.' FROM sla_medicao_classificada WHERE '.self::FILTRO.' GROUP BY mes', [$ano]) as $l) {
			$porMes[(int) $l['mes']] = $l;
		}

		$geral = [];

		for ($m = 1; $m <= 12; $m++) {
			$geral[] = $valor($porMes[$m] ?? null);
		}

		// ── Série mensal por categoria ─────────────────────────────────────
		$linhasCat = Db::consultar(
			'SELECT categoria_id, mes, '.self::AGREGADOS.' FROM sla_medicao_classificada WHERE '.self::FILTRO.' GROUP BY categoria_id, mes',
			[$ano]
		);
		$presentes = array_unique(array_column($linhasCat, 'categoria_id'));
		$cats = array_values(array_filter($todas, static fn($c) => in_array($c['id'], $presentes, true)));
		$porMesCat = [];

		foreach ($cats as $c) {
			$porMesCat[$c['id']] = array_fill(0, 12, null);
		}

		foreach ($linhasCat as $l) {
			if (array_key_exists($l['categoria_id'], $porMesCat)) {
				$porMesCat[$l['categoria_id']][(int) $l['mes'] - 1] = $valor($l);
			}
		}

		// ── Acumulados ───────────────────────────────────────────────────────
		$acumCatLinhas = Db::consultar(
			'SELECT categoria_id, '.self::AGREGADOS.' FROM sla_medicao_classificada WHERE '.self::FILTRO.' GROUP BY categoria_id',
			[$ano]
		);
		$acumCat = [];

		foreach ($acumCatLinhas as $l) {
			if (array_key_exists($l['categoria_id'], $porMesCat)) {
				$acumCat[$l['categoria_id']] = $valor($l);
			}
		}

		$totaisLinha = Db::consultar(
			'SELECT '.self::AGREGADOS.', count(DISTINCT equipamento) AS equipamentos, count(DISTINCT grupo) AS grupos
			 FROM sla_medicao_classificada WHERE '.self::FILTRO,
			[$ano]
		)[0];
		$acumGeral = $valor($totaisLinha);

		// ── Ranking ──────────────────────────────────────────────────────────
		$chaveCol = ['grupo' => 'grupo', 'equip' => 'equipamento', 'cat' => 'categoria_id'][$parametros['ranking']];
		$ranking = [];

		foreach (Db::consultar(
			"SELECT $chaveCol AS chave, ".self::AGREGADOS.", count(DISTINCT mes) AS meses
			 FROM sla_medicao_classificada WHERE ".self::FILTRO." GROUP BY $chaveCol",
			[$ano]
		) as $l) {
			$resultado = $valor($l);

			if ($resultado === null) {
				continue;
			}

			$nome = $l['chave'];

			if ($chaveCol === 'categoria_id') {
				foreach ($todas as $c) {
					if ($c['id'] === $nome) {
						$nome = $c['nome'];
						break;
					}
				}
			}

			$ranking[] = [
				'nome' => $nome, 'sla' => $resultado, 'down' => (float) ($l['down'] ?? 0),
				'inc' => (int) ($l['incidentes'] ?? 0), 'meses' => (int) $l['meses']
			];
		}

		usort($ranking, static fn($a, $b) => $b['sla'] <=> $a['sla']);

		// ── Mapa de calor ────────────────────────────────────────────────────
		$heat = [];

		foreach (Db::consultar(
			'SELECT grupo, categoria_id, mes, '.self::AGREGADOS.'
			 FROM sla_medicao_classificada WHERE '.self::FILTRO.' GROUP BY grupo, categoria_id, mes ORDER BY grupo',
			[$ano]
		) as $l) {
			if (!isset($heat[$l['grupo']])) {
				$heat[$l['grupo']] = ['grupo' => $l['grupo'], 'cat' => $l['categoria_id'], 'valores' => array_fill(0, 12, null), 'acum' => null];
			}

			$heat[$l['grupo']]['valores'][(int) $l['mes'] - 1] = $valor($l);
		}

		foreach (Db::consultar('SELECT grupo, '.self::AGREGADOS.' FROM sla_medicao_classificada WHERE '.self::FILTRO.' GROUP BY grupo', [$ano]) as $l) {
			if (isset($heat[$l['grupo']])) {
				$heat[$l['grupo']]['acum'] = $valor($l);
			}
		}

		// ── Concentração do tempo parado (Pareto) ───────────────────────────
		$pareto = [];

		foreach (Db::consultar(
			'SELECT grupo, sum(indisponibilidade_s) AS down FROM sla_medicao_classificada
			 WHERE '.self::FILTRO.' AND indisponibilidade_s IS NOT NULL
			 GROUP BY grupo HAVING sum(indisponibilidade_s) > 0 ORDER BY 2 DESC',
			[$ano]
		) as $l) {
			$pareto[] = ['grupo' => $l['grupo'], 'down' => (float) $l['down']];
		}

		// ── Orçamento de erro ────────────────────────────────────────────────
		$tolerancia = (100 - $meta) / 100;
		$orcamento = [];

		foreach ($acumCatLinhas as $l) {
			if (!array_key_exists($l['categoria_id'], $porMesCat)) {
				continue;
			}

			$janela = (float) ($l['janela'] ?? 0);

			if ($janela <= 0) {
				continue;
			}

			$down = (float) ($l['down'] ?? 0);
			$limite = $janela * $tolerancia;

			foreach ($todas as $categoria) {
				if ($categoria['id'] === $l['categoria_id']) {
					$orcamento[] = [
						'id' => $categoria['id'], 'nome' => $categoria['nome'], 'cor' => $categoria['cor'],
						'down' => $down, 'orcamento' => $limite, 'pct' => $limite > 0 ? $down / $limite * 100 : null
					];
					break;
				}
			}
		}

		// ── Fora do cálculo e detalhamento ──────────────────────────────────
		$fora = Db::consultar(
			"SELECT
				count(*) FILTER (WHERE lower(situacao) LIKE '%expurg%')      AS expurgados,
				count(*) FILTER (WHERE lower(situacao) LIKE '%sem trigger%') AS sem_trigger
			 FROM sla_medicao WHERE ano = ?",
			[$ano]
		)[0];

		$detalheLinhas = Db::consultar(
			'SELECT mes, grupo, categoria_id, equipamento, sla, indisponibilidade_s, incidentes, situacao
			 FROM sla_medicao_classificada WHERE '.self::FILTRO.' ORDER BY grupo, mes, equipamento LIMIT 800',
			[$ano]
		);
		$detalhe = array_map(static fn(array $l) => [
			'mes' => (int) $l['mes'] - 1, 'grupo' => $l['grupo'], 'cat' => $l['categoria_id'],
			'equip' => $l['equipamento'], 'sla' => (float) $l['sla'],
			'down' => $l['indisponibilidade_s'] !== null ? (float) $l['indisponibilidade_s'] : null,
			'inc' => (int) $l['incidentes'], 'sit' => $l['situacao']
		], $detalheLinhas);

		$janelaTotal = (float) ($totaisLinha['janela'] ?? 0);
		$downTotal = (float) ($totaisLinha['down'] ?? 0);

		$grupos = array_map(static fn(array $l) => [
			'grupo' => $l['grupo'], 'cat' => $l['categoria_id'], 'registros' => (int) $l['registros']
		], Db::consultar('SELECT grupo, categoria_id, count(*) AS registros FROM sla_medicao_classificada GROUP BY grupo, categoria_id ORDER BY grupo'));

		$importacoes = array_map([self::class, 'formatarImportacao'],
			Db::consultar('SELECT * FROM sla_importacao ORDER BY importado_em DESC LIMIT 100'));

		return [
			'ano' => $ano, 'anos' => $anos, 'config' => $parametros, 'meses' => self::MESES,
			'cats' => array_map(static fn($c) => ['id' => $c['id'], 'nome' => $c['nome'], 'sigla' => $c['sigla'], 'cor' => $c['cor']], $cats),
			'categorias' => $todas, 'porMesCat' => $porMesCat, 'geral' => $geral,
			'acumCat' => $acumCat, 'acumGeral' => $acumGeral, 'rank' => $ranking,
			'heat' => array_values($heat), 'pareto' => $pareto, 'budget' => $orcamento, 'detalhe' => $detalhe,
			'totais' => [
				'linhas' => (int) $totaisLinha['linhas'], 'detalhe_exibido' => count($detalhe),
				'downTotal' => $downTotal ?: null, 'janelaTotal' => $janelaTotal ?: null,
				'incTotal' => (int) ($totaisLinha['incidentes'] ?? 0),
				'equipamentos' => (int) $totaisLinha['equipamentos'], 'grupos' => (int) $totaisLinha['grupos'],
				'expurgados' => (int) $fora['expurgados'], 'semTrigger' => (int) $fora['sem_trigger'],
				'orcamentoConsumido' => ($janelaTotal > 0 && $tolerancia > 0) ? $downTotal / ($janelaTotal * $tolerancia) * 100 : null
			],
			'grupos' => $grupos, 'importacoes' => $importacoes
		];
	}

	/**
	 * @param array<string, mixed>|null $linha
	 */
	private static function valorAgregado(?array $linha, string $metodo): ?float {
		if ($linha === null || (int) ($linha['linhas'] ?? 0) === 0) {
			return null;
		}

		$janela = (float) ($linha['janela'] ?? 0);
		$down = (float) ($linha['down'] ?? 0);

		if ($metodo === 'ponderada' && $janela > 0) {
			return 100.0 * (1 - $down / $janela);
		}

		$peso = (float) ($linha['peso'] ?? 0);

		return $peso > 0 ? (float) $linha['sla_peso'] / $peso : null;
	}

	// ── Importação ───────────────────────────────────────────────────────────

	/**
	 * @param array<int, array{nome: string, conteudo: string}> $arquivos
	 *
	 * @return array<string, mixed>
	 */
	public static function importar(array $arquivos, string $usuario): array {
		$resultados = [];
		$importados = 0;

		foreach ($arquivos as $arquivo) {
			$nome = mb_substr($arquivo['nome'] !== '' ? $arquivo['nome'] : 'sem-nome.csv', 0, 200);
			$bruto = $arquivo['conteudo'];
			$texto = self::comoUtf8($bruto);
			$hash = hash('sha256', $bruto);

			if (Db::consultarUm('SELECT id FROM sla_importacao WHERE hash = ?', [$hash]) !== null) {
				$resultados[] = ['arquivo' => $nome, 'status' => 'duplicado', 'mensagem' => _('conteúdo idêntico já importado')];
				continue;
			}

			try {
				$lido = Parser::ler($nome, $texto);
			}
			catch (RuntimeException $e) {
				$resultados[] = ['arquivo' => $nome, 'status' => 'erro', 'mensagem' => $e->getMessage()];
				continue;
			}

			$id = self::gravarImportacao($nome, $hash, strlen($bruto), $lido['medicoes'], $usuario);
			$calculaveis = count(array_filter($lido['medicoes'], static fn($m) => $m['calculavel']));
			$resultados[] = [
				'arquivo' => $nome, 'status' => 'ok', 'id' => $id, 'formato' => $lido['formato'],
				'linhas' => count($lido['medicoes']), 'calculaveis' => $calculaveis
			];
			$importados++;
		}

		Db::auditar($usuario, 'importacao', ['arquivos' => count($arquivos), 'aceitos' => $importados]);

		return ['resultados' => $resultados, 'importados' => $importados];
	}

	private static function comoUtf8(string $bruto): string {
		$semBom = ltrim($bruto, "\xEF\xBB\xBF");

		if (mb_check_encoding($semBom, 'UTF-8')) {
			return $semBom;
		}

		// Exportações antigas do Excel ainda saem em Latin-1.
		$convertido = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $semBom);

		return $convertido !== false ? $convertido : $semBom;
	}

	/**
	 * @param array<int, array<string, mixed>> $medicoes
	 */
	private static function gravarImportacao(string $nome, string $hash, int $bytes, array $medicoes, string $usuario): int {
		$meses = array_count_values(array_column($medicoes, 'mes'));
		$anos = array_count_values(array_column($medicoes, 'ano'));
		$mesPredominante = $meses !== [] ? (int) array_search(max($meses), $meses, true) : null;
		$anoPredominante = $anos !== [] ? (int) array_search(max($anos), $anos, true) : null;
		$calculaveis = count(array_filter($medicoes, static fn($m) => $m['calculavel']));

		$pdo = Db::conexao();
		$pdo->beginTransaction();

		try {
			$comando = $pdo->prepare('
				INSERT INTO sla_importacao (arquivo, hash, ano, mes, linhas, linhas_calculaveis, bytes, importado_por)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
			');
			$comando->execute([$nome, $hash, $anoPredominante, $mesPredominante, count($medicoes), $calculaveis, $bytes, $usuario]);
			$id = (int) $comando->fetchColumn();

			$inserir = $pdo->prepare('
				INSERT INTO sla_medicao (importacao_id, grupo, equipamento, situacao, ano, mes,
					periodo_inicio, periodo_fim, semana,
					sla, meta_slo, indisponibilidade_s, janela_s, incidentes, peso, calculavel, observacao)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			');

			foreach ($medicoes as $m) {
				$inserir->execute([
					$id, $m['grupo'], $m['equipamento'], $m['situacao'], $m['ano'], $m['mes'],
					$m['periodo_inicio'] ?? null, $m['periodo_fim'] ?? null, $m['semana'] ?? null,
					$m['sla'], $m['meta_slo'], $m['indisponibilidade_s'], $m['janela_s'],
					$m['incidentes'], $m['peso'], $m['calculavel'] ? 't' : 'f', $m['observacao']
				]);
			}

			$pdo->commit();
		}
		catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}

		return $id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function listarImportacoes(): array {
		return array_map([self::class, 'formatarImportacao'],
			Db::consultar('SELECT * FROM sla_importacao ORDER BY importado_em DESC LIMIT 200'));
	}

	/**
	 * @param array<string, mixed> $l
	 *
	 * @return array<string, mixed>
	 */
	private static function formatarImportacao(array $l): array {
		return [
			'id' => (int) $l['id'], 'arquivo' => $l['arquivo'],
			'ano' => $l['ano'] !== null ? (int) $l['ano'] : null,
			'mes' => $l['mes'] !== null ? (int) $l['mes'] : null,
			'linhas' => (int) $l['linhas'], 'calculaveis' => (int) $l['linhas_calculaveis'],
			'importado_em' => self::iso($l['importado_em']),
			'importado_por' => $l['importado_por']
		];
	}

	public static function removerImportacao(int $id, string $usuario): bool {
		$linha = Db::consultarUm('SELECT arquivo FROM sla_importacao WHERE id = ?', [$id]);

		if ($linha === null) {
			return false;
		}

		Db::executar('DELETE FROM sla_importacao WHERE id = ?', [$id]);
		Db::auditar($usuario, 'importacao.delete', ['id' => $id, 'arquivo' => $linha['arquivo']]);

		return true;
	}

	public static function limparDados(string $usuario): void {
		Db::executar('TRUNCATE sla_medicao, sla_importacao RESTART IDENTITY');
		Db::auditar($usuario, 'dados.limpar', []);
	}

	// ── Dados de exemplo ─────────────────────────────────────────────────────

	/**
	 * @return array{ok: bool, id: int, linhas: int, ano: int}
	 */
	public static function demo(?int $ano, string $usuario): array {
		$ano = $ano ?? (int) date('Y');

		// Sete unidades, cada uma com as três categorias. O nome do grupo termina
		// no código da unidade, como nos grupos reais — é assim que a unidade é
		// deduzida sem ninguém apontar à mão.
		$unidades = ['AL' => 99.90, 'PI' => 99.88, 'AP' => 99.80, 'RS' => 99.35, 'PA' => 99.30, 'GO' => 99.05, 'MA' => 99.00];
		$categorias = [['Operação', 4, 0.00], ['Escritórios', 3, 0.05], ['Agências', 6, -0.05]];

		mt_srand(20260101);
		$medicoes = [];

		foreach ($unidades as $uf => $baseUnidade) {
			foreach ($categorias as [$categoria, $quantidade, $ajuste]) {
				$grupo = "$categoria $uf";
				$prefixo = preg_replace('/[^A-Z]/', '', mb_strtoupper(mb_substr($categoria, 0, 2))).'-'.$uf;

				for ($mes = 1; $mes <= 7; $mes++) {
					foreach (self::semanasDoMes($ano, $mes) as [$semana, $inicio, $fim]) {
						$dias = $inicio->diff($fim)->days + 1;
						$janela = $dias * 86400;

						for ($i = 1; $i <= $quantidade; $i++) {
							$ruido = (mt_rand(0, 10000) / 10000 - 0.5) * 0.30;
							// Um incidente maior de vez em quando, para o ranking ter variação.
							$incidente = mt_rand(0, 100) < 6 ? -mt_rand(5, 20) / 10 : 0;
							$sla = max(95.0, min(100.0, $baseUnidade + $ajuste + $ruido + $incidente));
							$down = $janela * (100 - $sla) / 100;

							$medicoes[] = [
								'grupo' => $grupo,
								'equipamento' => sprintf('%s-%03d', $prefixo, $i),
								'situacao' => 'Monitorado', 'ano' => $ano, 'mes' => $mes,
								'periodo_inicio' => $inicio->format('Y-m-d'), 'periodo_fim' => $fim->format('Y-m-d'),
								'semana' => $semana,
								'sla' => round($sla, 6), 'meta_slo' => 99.0, 'indisponibilidade_s' => round($down, 2),
								'janela_s' => (float) $janela, 'incidentes' => (int) ($down / 3600 * 1.4) + ($down > 0 ? 1 : 0),
								'peso' => 1.0, 'calculavel' => true, 'observacao' => ''
							];
						}
					}
				}
			}
		}

		$hash = hash('sha256', "demo-$ano");
		Db::executar('DELETE FROM sla_importacao WHERE hash = ?', [$hash]);
		$id = self::gravarImportacao("exemplo-$ano.csv", $hash, 0, $medicoes, $usuario);
		Db::auditar($usuario, 'demo', ['ano' => $ano, 'linhas' => count($medicoes)]);

		return ['ok' => true, 'id' => $id, 'linhas' => count($medicoes), 'ano' => $ano];
	}

	/**
	 * Semanas-calendário de um mês (domingo a sábado), recortadas ao mês.
	 *
	 * @return array<int, array{0: int, 1: \DateTime, 2: \DateTime}>  [semana, início, fim]
	 */
	public static function semanasDoMes(int $ano, int $mes): array {
		$primeiro = new \DateTime(sprintf('%04d-%02d-01', $ano, $mes));
		$ultimo = (clone $primeiro)->modify('last day of this month');
		$saida = [];
		$cursor = clone $primeiro;

		while ($cursor <= $ultimo) {
			$semana = Parser::semanaDoMes($cursor);
			// Fim da semana: o sábado, ou o último dia do mês, o que vier antes.
			$sabado = (clone $cursor)->modify('+'.(6 - (int) $cursor->format('w')).' days');
			$fim = $sabado < $ultimo ? $sabado : clone $ultimo;
			$saida[] = [$semana, clone $cursor, $fim];
			$cursor = (clone $fim)->modify('+1 day');
		}

		return $saida;
	}

	// ── Acompanhamento semanal por unidade ───────────────────────────────────

	private const SEMANAS = ['1 SEM', '2 SEM', '3 SEM', '4 SEM', '5 SEM', '6 SEM'];

	/**
	 * Meses que têm medição com semana (isto é, importada a partir de um
	 * relatório com período — planilha em formato largo não entra aqui).
	 *
	 * @return array<int, array{ano: int, mes: int}>
	 */
	public static function mesesComSemana(): array {
		return array_map(static fn(array $l) => ['ano' => (int) $l['ano'], 'mes' => (int) $l['mes']],
			Db::consultar('SELECT DISTINCT ano, mes FROM sla_medicao WHERE semana IS NOT NULL ORDER BY ano DESC, mes DESC'));
	}

	/**
	 * Quadro semanal de um mês, por unidade — o modelo da diretoria.
	 *
	 * Para cada unidade: uma linha por categoria com 1..6 SEM, ACUM (o mês
	 * inteiro, ponderado por tempo) e a contribuição ponderada
	 * (ACUM × peso de negócio); a linha Geral (todos os equipamentos da
	 * unidade); e a PONDERADA da unidade, que é a soma das contribuições.
	 *
	 * Quando a unidade não tem alguma categoria (um site sem Operação, por
	 * exemplo), a soma é normalizada pelos pesos presentes — senão a unidade
	 * seria punida por não ter a categoria, e não por ter ficado indisponível.
	 *
	 * @return array<string, mixed>
	 */
	public static function semanal(?int $ano, ?int $mes): array {
		$parametros = self::config();
		$metodo = $parametros['metodo'];
		$todas = self::categorias();
		$porId = array_column($todas, null, 'id');
		$meses = self::mesesComSemana();

		if ($ano === null || $mes === null) {
			$ano = $meses[0]['ano'] ?? (int) date('Y');
			$mes = $meses[0]['mes'] ?? (int) date('n');
		}

		$valor = static fn(?array $l): ?float => self::valorAgregado($l, $metodo);
		$filtro = 'calculavel AND sla IS NOT NULL AND semana IS NOT NULL AND ano = ? AND mes = ?';

		// Uma consulta por recorte, agrupadas no banco; a montagem fica aqui.
		$porUnidadeCatSemana = Db::consultar(
			'SELECT unidade, categoria_id, semana, '.self::AGREGADOS."
			 FROM sla_medicao_classificada WHERE $filtro GROUP BY unidade, categoria_id, semana",
			[$ano, $mes]
		);
		$porUnidadeCat = Db::consultar(
			'SELECT unidade, categoria_id, '.self::AGREGADOS."
			 FROM sla_medicao_classificada WHERE $filtro GROUP BY unidade, categoria_id",
			[$ano, $mes]
		);
		$porUnidadeSemana = Db::consultar(
			'SELECT unidade, semana, '.self::AGREGADOS."
			 FROM sla_medicao_classificada WHERE $filtro GROUP BY unidade, semana",
			[$ano, $mes]
		);
		$porUnidade = Db::consultar(
			'SELECT unidade, '.self::AGREGADOS.", count(DISTINCT equipamento) AS equipamentos
			 FROM sla_medicao_classificada WHERE $filtro GROUP BY unidade ORDER BY unidade",
			[$ano, $mes]
		);

		$unidades = [];

		foreach ($porUnidade as $u) {
			$unidades[$u['unidade']] = [
				'unidade' => $u['unidade'],
				'cats' => [],
				'geral' => ['semanas' => array_fill(0, 6, null), 'acum' => $valor($u)],
				'equipamentos' => (int) $u['equipamentos'],
				'down' => (float) ($u['down'] ?? 0),
				'incidentes' => (int) ($u['incidentes'] ?? 0),
				'ponderada' => null,
				'pesos_presentes' => 0.0
			];
		}

		foreach ($porUnidadeSemana as $l) {
			if (isset($unidades[$l['unidade']])) {
				$unidades[$l['unidade']]['geral']['semanas'][(int) $l['semana'] - 1] = $valor($l);
			}
		}

		// Linhas por categoria, na ordem cadastrada (OP, ES, AG…).
		foreach ($porUnidadeCat as $l) {
			$cat = $porId[$l['categoria_id']] ?? null;

			if ($cat === null || !isset($unidades[$l['unidade']])) {
				continue;
			}

			$acum = $valor($l);
			$unidades[$l['unidade']]['cats'][$cat['id']] = [
				'id' => $cat['id'], 'nome' => $cat['nome'], 'sigla' => $cat['sigla'], 'cor' => $cat['cor'],
				'ordem' => $cat['ordem'], 'peso' => $cat['peso'],
				'semanas' => array_fill(0, 6, null),
				'acum' => $acum,
				'contribuicao' => ($acum !== null) ? $acum * $cat['peso'] / 100 : null
			];
		}

		foreach ($porUnidadeCatSemana as $l) {
			if (isset($unidades[$l['unidade']]['cats'][$l['categoria_id']])) {
				$unidades[$l['unidade']]['cats'][$l['categoria_id']]['semanas'][(int) $l['semana'] - 1] = $valor($l);
			}
		}

		// PONDERADA por unidade e ranking.
		foreach ($unidades as &$u) {
			uasort($u['cats'], static fn($a, $b) => $a['ordem'] <=> $b['ordem']);
			$soma = 0.0;
			$pesos = 0.0;

			foreach ($u['cats'] as $c) {
				if ($c['acum'] !== null && $c['peso'] > 0) {
					$soma += $c['acum'] * $c['peso'];
					$pesos += $c['peso'];
				}
			}

			$u['pesos_presentes'] = $pesos;
			$u['ponderada'] = $pesos > 0 ? $soma / $pesos : null;
			$u['cats'] = array_values($u['cats']);
		}
		unset($u);

		$ranking = array_values(array_filter(array_map(static fn($u) => [
			'unidade' => $u['unidade'], 'ponderada' => $u['ponderada'], 'acum' => $u['geral']['acum'],
			'equipamentos' => $u['equipamentos'], 'down' => $u['down']
		], $unidades), static fn($r) => $r['ponderada'] !== null));
		usort($ranking, static fn($a, $b) => $b['ponderada'] <=> $a['ponderada']);

		// Ordem dos quadros: a mesma do ranking; unidades sem ponderada no fim.
		$posicao = array_flip(array_column($ranking, 'unidade'));
		uasort($unidades, static fn($a, $b) => ($posicao[$a['unidade']] ?? PHP_INT_MAX) <=> ($posicao[$b['unidade']] ?? PHP_INT_MAX));

		// Semanas que existem no mês (calendário) e que têm dado.
		$calendario = array_map(static fn($w) => [
			'semana' => $w[0], 'inicio' => $w[1]->format('Y-m-d'), 'fim' => $w[2]->format('Y-m-d')
		], self::semanasDoMes($ano, $mes));
		$comDado = array_fill(0, 6, false);

		foreach ($porUnidadeSemana as $l) {
			$comDado[(int) $l['semana'] - 1] = true;
		}

		// Consolidado da rede: todas as unidades juntas, no mesmo formato.
		$redeSemanas = array_fill(0, 6, null);

		foreach (Db::consultar(
			'SELECT semana, '.self::AGREGADOS." FROM sla_medicao_classificada WHERE $filtro GROUP BY semana",
			[$ano, $mes]
		) as $l) {
			$redeSemanas[(int) $l['semana'] - 1] = $valor($l);
		}

		$redeTotal = Db::consultar(
			'SELECT '.self::AGREGADOS.", count(DISTINCT equipamento) AS equipamentos, count(DISTINCT unidade) AS unidades
			 FROM sla_medicao_classificada WHERE $filtro",
			[$ano, $mes]
		)[0];

		$ponderadas = array_column($ranking, 'ponderada');

		return [
			'ano' => $ano, 'mes' => $mes, 'meses' => self::MESES, 'semanas' => self::SEMANAS,
			'config' => $parametros, 'categorias' => $todas,
			'meses_disponiveis' => $meses,
			'calendario' => $calendario, 'semanas_com_dado' => $comDado,
			'unidades' => array_values($unidades),
			'ranking' => $ranking,
			'rede' => [
				'semanas' => $redeSemanas, 'acum' => $valor($redeTotal),
				'ponderada_media' => $ponderadas !== [] ? array_sum($ponderadas) / count($ponderadas) : null,
				'equipamentos' => (int) $redeTotal['equipamentos'], 'unidades' => (int) $redeTotal['unidades'],
				'down' => (float) ($redeTotal['down'] ?? 0), 'incidentes' => (int) ($redeTotal['incidentes'] ?? 0)
			],
			'grupos' => array_map(static fn(array $l) => [
				'grupo' => $l['grupo'], 'cat' => $l['categoria_id'], 'unidade' => $l['unidade'],
				'manual' => $l['manual'] !== null, 'registros' => (int) $l['registros']
			], Db::consultar(
				'SELECT m.grupo, m.categoria_id, m.unidade, gc.unidade AS manual, count(*) AS registros
				 FROM sla_medicao_classificada m LEFT JOIN sla_grupo_categoria gc ON gc.grupo = m.grupo
				 GROUP BY m.grupo, m.categoria_id, m.unidade, gc.unidade ORDER BY m.unidade, m.grupo'
			))
		];
	}

	/**
	 * Pesos de negócio por categoria. Zero é permitido (categoria fora da
	 * ponderada); a soma não precisa dar 100, porque a ponderada é normalizada.
	 *
	 * @param array<string, mixed> $pesos  id da categoria => peso
	 */
	public static function salvarPesos(array $pesos, string $usuario): array {
		$validos = array_column(self::categorias(), 'id');
		$comando = Db::conexao()->prepare('UPDATE sla_categoria SET peso_negocio = ? WHERE id = ?');
		$gravados = [];

		foreach ($pesos as $id => $peso) {
			if (!in_array($id, $validos, true)) {
				continue;
			}

			$numero = is_string($peso) ? str_replace(',', '.', trim($peso)) : $peso;

			if (!is_numeric($numero) || (float) $numero < 0 || (float) $numero > 100) {
				throw new InvalidArgumentException(sprintf(_('Peso inválido para %1$s: informe um número entre 0 e 100.'), $id));
			}

			$comando->execute([round((float) $numero, 2), $id]);
			$gravados[$id] = round((float) $numero, 2);
		}

		Db::auditar($usuario, 'pesos.update', $gravados);

		return self::categorias();
	}

	/**
	 * Unidade apontada à mão por grupo de hosts. Vazio volta para a dedução
	 * automática (o código no fim do nome).
	 *
	 * @param array<string, string> $unidades  grupo => unidade
	 */
	public static function salvarUnidades(array $unidades, string $usuario): void {
		$pdo = Db::conexao();
		$comando = $pdo->prepare('
			INSERT INTO sla_grupo_categoria (grupo, categoria_id, unidade, definido_por)
			VALUES (?, (SELECT categoria_id FROM sla_medicao_classificada WHERE grupo = ? LIMIT 1), ?, ?)
			ON CONFLICT (grupo) DO UPDATE SET unidade = EXCLUDED.unidade,
				definido_em = now(), definido_por = EXCLUDED.definido_por
		');

		foreach ($unidades as $grupo => $unidade) {
			$grupo = mb_substr(trim((string) $grupo), 0, 255);
			$unidade = mb_substr(trim((string) $unidade), 0, 60);

			if ($grupo === '') {
				continue;
			}

			$comando->execute([$grupo, $grupo, $unidade !== '' ? $unidade : null, $usuario]);
		}

		Db::auditar($usuario, 'unidades.update', ['grupos' => count($unidades)]);
	}

	public static function exportarSemanalCsv(?int $ano, ?int $mes): string {
		$dados = self::semanal($ano, $mes);
		$decimais = $dados['config']['decimais'];

		$n = static fn($v) => $v === null ? '' : str_replace('.', ',', number_format((float) $v, $decimais, '.', ''));

		$linhas = [];
		$linha = static function (array $campos) use (&$linhas): void {
			$linhas[] = implode(';', array_map(static fn($c) => '"'.str_replace('"', '""', (string) $c).'"', $campos));
		};

		$linha(['Indicador', $dados['config']['titulo'], 'Mês', self::MESES[$dados['mes'] - 1].'/'.$dados['ano']]);
		$linha(['Meta (%)', $n($dados['config']['meta']), 'Desafio (%)', $n($dados['config']['desafio'])]);
		$linha(['Pesos', implode(' · ', array_map(static fn($c) => $c['sigla'].' '.$n($c['peso']).'%', array_filter($dados['categorias'], static fn($c) => $c['peso'] > 0)))]);
		$linha([]);
		$linha(['Ranking', 'Unidade', 'Ponderada (%)', 'Geral (%)', 'Equipamentos', 'Tempo parado (s)']);

		foreach ($dados['ranking'] as $i => $r) {
			$linha([$i + 1, $r['unidade'], $n($r['ponderada']), $n($r['acum']), $r['equipamentos'], (int) $r['down']]);
		}

		foreach ($dados['unidades'] as $u) {
			$linha([]);
			$linha([$u['unidade']]);
			$linha(array_merge(['Categoria', 'Sigla'], self::SEMANAS, ['ACUM', 'Peso (%)', 'Ponderada']));

			foreach ($u['cats'] as $c) {
				$linha(array_merge([$c['nome'], $c['sigla']], array_map($n, $c['semanas']), [$n($c['acum']), $n($c['peso']), $n($c['contribuicao'])]));
			}

			$linha(array_merge(['Geral', '—'], array_map($n, $u['geral']['semanas']), [$n($u['geral']['acum']), '', $n($u['ponderada'])]));
			$linha(array_merge(['Meta', 'SLO'], array_fill(0, 6, $n($dados['config']['meta'])), [$n($dados['config']['meta']), '', '']));
			$linha(array_merge(['Desafio', '—'], array_fill(0, 6, $n($dados['config']['desafio'])), [$n($dados['config']['desafio']), '', '']));
		}

		return "\xEF\xBB\xBF".implode("\r\n", $linhas)."\r\n";
	}

	// ── Exportações ──────────────────────────────────────────────────────────

	public static function exportarJson(?int $ano): string {
		return json_encode(self::consolidado($ano), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	}

	public static function exportarCsv(?int $ano): string {
		$dados = self::consolidado($ano);
		$decimais = $dados['config']['decimais'];

		$n = static function ($valor) use ($decimais) {
			return $valor === null ? '' : str_replace('.', ',', number_format((float) $valor, $decimais, '.', ''));
		};

		$saida = fopen('php://temp', 'r+');
		// fputcsv só coloca aspas quando o campo "precisa" (contém o delimitador,
		// aspas ou quebra de linha). O CSV do Relatório de Disponibilidade traz
		// aspas em todo campo, para o Excel em português abrir sem perguntar
		// nada — então a linha é montada à mão para reproduzir isso.
		$linha = static function (array $campos) use ($saida): void {
			$textos = array_map(
				static fn($c) => '"'.str_replace('"', '""', (string) $c).'"',
				$campos
			);
			fwrite($saida, implode(';', $textos)."\r\n");
		};

		$linha(['Indicador', $dados['config']['titulo'], 'Ano', (string) $dados['ano']]);
		$linha(['Meta (%)', $n($dados['config']['meta']), 'Desafio (%)', $n($dados['config']['desafio'])]);
		$linha([]);
		$linha(array_merge(['Categoria', 'Sigla'], $dados['meses'], ['ACUM']));

		foreach ($dados['cats'] as $categoria) {
			$linha(array_merge(
				[$categoria['nome'], $categoria['sigla']],
				array_map($n, $dados['porMesCat'][$categoria['id']]),
				[$n($dados['acumCat'][$categoria['id']] ?? null)]
			));
		}

		$linha(array_merge(['Geral', '—'], array_map($n, $dados['geral']), [$n($dados['acumGeral'])]));
		$linha(array_merge(['Meta', 'SLO'], array_fill(0, 12, $n($dados['config']['meta'])), [$n($dados['config']['meta'])]));
		$linha(array_merge(['Desafio', '—'], array_fill(0, 12, $n($dados['config']['desafio'])), [$n($dados['config']['desafio'])]));
		$linha([]);
		$linha(array_merge(['Grupo de hosts', 'Categoria'], $dados['meses'], ['ACUM', 'Tempo parado (s)']));

		foreach ($dados['heat'] as $item) {
			$nomeCat = $item['cat'];

			foreach ($dados['categorias'] as $categoria) {
				if ($categoria['id'] === $item['cat']) {
					$nomeCat = $categoria['nome'];
					break;
				}
			}

			$parado = 0;

			foreach ($dados['pareto'] as $p) {
				if ($p['grupo'] === $item['grupo']) {
					$parado = $p['down'];
					break;
				}
			}

			$linha(array_merge(
				[$item['grupo'], $nomeCat], array_map($n, $item['valores']),
				[$n($item['acum'] ?? null), (string) (int) $parado]
			));
		}

		rewind($saida);
		$conteudo = stream_get_contents($saida);
		fclose($saida);

		return "\xEF\xBB\xBF".$conteudo;
	}
}
