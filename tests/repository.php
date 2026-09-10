<?php declare(strict_types = 1);

/**
 * Verificações do Repository, Parser e Db contra um Postgres real.
 *
 *     DB_SERVER_HOST=/tmp DB_SERVER_PORT=5433 POSTGRES_DB=sla \
 *     POSTGRES_USER=sla POSTGRES_PASSWORD= php tests/repository.php
 *
 * Sem mock de banco: a consolidação mora em SQL, então testar com banco falso
 * testaria outra coisa. As tabelas de dados são limpas no início e no fim.
 */

if (!function_exists('_')) {
	function _(string $string): string {
		return $string;
	}
}

// ── Stubs mínimos do núcleo do Zabbix, só o suficiente para Config::module() ──
if (!class_exists('CModule')) {
	class CModule {
		public function getConfig() { return []; }
		public function setConfig(array $config) {}
		public function getRelativePath(): string { return 'modules/SlaExecutivo'; }
	}
}

if (!class_exists('APP')) {
	final class APP {
		public static function ModuleManager() { return new class {
			public function getModule($id) { return null; } // sem override: usa o ambiente
		}; }
	}
}

require_once __DIR__.'/../services/Db.php';
require_once __DIR__.'/../services/Config.php';
require_once __DIR__.'/../services/Parser.php';
require_once __DIR__.'/../services/Repository.php';

use Modules\SlaExecutivo\Services\Db;
use Modules\SlaExecutivo\Services\Parser;
use Modules\SlaExecutivo\Services\Repository;

$falhas = 0;

function sxCheck(bool $condicao, string $mensagem): void {
	global $falhas;

	if ($condicao) {
		echo "  ok   ".$mensagem."\n";
	}
	else {
		$falhas++;
		echo "  FALHA ".$mensagem."\n";
	}
}

function sxPerto(?float $a, float $b, float $tolerancia = 0.0005): bool {
	return $a !== null && abs($a - $b) <= $tolerancia;
}

function sxLimpar(): void {
	Db::executar('TRUNCATE sla_medicao, sla_importacao RESTART IDENTITY');
	Db::executar('DELETE FROM sla_grupo_categoria');
}

$cabecalho = '"Grupo de hosts";"Período inicial";"Período final";"Cobertura";"Expurgo";'
	.'"Equipamento";"Situação";"SLA (%)";"Meta SLO (%)";"Indisponibilidade";'
	.'"Indisponibilidade (segundos)";"Incidentes";"Observação"';

$janeiro = implode("\r\n", [
	$cabecalho,
	'"Agências SP";"01/01/2026";"31/01/2026";"24x7";"Sem expurgo";"AG-SP-001";"Monitorado";"99,81";"99,00";"1h 24m";"5040";"3";""',
	'"Agências SP";"01/01/2026";"31/01/2026";"24x7";"Sem expurgo";"AG-SP-002";"Monitorado";"99,95";"99,00";"22m";"1320";"1";""',
	'"Escritórios";"01/01/2026";"31/01/2026";"24x7";"Sem expurgo";"ES-MATRIZ-01";"Monitorado";"99,88";"99,00";"53m";"3180";"2";""',
	'"Operação";"01/01/2026";"31/01/2026";"24x7";"Manutenção";"OP-CORE-01";"Expurgado";"";"99,00";"";"";"0";"Janela"',
	'"Operação";"01/01/2026";"31/01/2026";"24x7";"Sem expurgo";"OP-CORE-02";"Sem trigger de disponibilidade";"";"99,00";"";"";"0";""'
]);

$fevereiro = str_replace(['01/01/2026', '31/01/2026', '99,81'], ['01/02/2026', '28/02/2026', '98,60'], $janeiro);

// ── Conexão e schema ─────────────────────────────────────────────────────────
echo "── Conexão e criação automática do schema ───────────────\n";
Db::conexao();
$existe = Db::consultarUm("SELECT to_regclass('public.sla_config') AS t");
sxCheck($existe !== null && $existe['t'] !== null, 'o schema é criado sozinho na primeira conexão');
sxCheck(Db::usandoAmbiente(), 'sem override gravado, a conexão usa as variáveis de ambiente do Zabbix');

$categoriasPadrao = Repository::categorias();
sxCheck(count($categoriasPadrao) === 4, 'as quatro categorias padrão vêm junto com o schema');

sxLimpar();

echo "\n── Leitura do CSV (Parser) ───────────────────────────────\n";
$lido = Parser::ler('jan.csv', $janeiro);
sxCheck(count($lido['medicoes']) === 5, 'as cinco linhas do arquivo são lidas');
sxCheck($lido['medicoes'][0]['mes'] === 1 && $lido['medicoes'][0]['ano'] === 2026, 'o mês vem do período inicial');
sxCheck(sxPerto($lido['medicoes'][0]['sla'], 99.81), 'SLA com vírgula decimal é convertido');
sxCheck((float) $lido['medicoes'][0]['indisponibilidade_s'] === 5040.0, 'tempo parado em segundos é lido');
sxCheck($lido['medicoes'][3]['sla'] === null, 'linha expurgada fica sem SLA, não com 100%');
sxCheck($lido['medicoes'][0]['janela_s'] > 0, 'a janela do equipamento é deduzida');
sxCheck(sxPerto(100 * (1 - $lido['medicoes'][0]['indisponibilidade_s'] / $lido['medicoes'][0]['janela_s']), 99.81, 0.01),
	'a janela deduzida reproduz o SLA informado pelo relatório');

echo "\n── Importação via Repository ─────────────────────────────\n";
$resultado = Repository::importar([
	['nome' => 'jan-2026.csv', 'conteudo' => $janeiro],
	['nome' => 'fev-2026.csv', 'conteudo' => $fevereiro]
], 'teste');
sxCheck($resultado['importados'] === 2, 'dois arquivos importados');
sxCheck($resultado['resultados'][0]['linhas'] === 5, 'cinco linhas no primeiro arquivo');
sxCheck($resultado['resultados'][0]['calculaveis'] === 3, 'expurgado e sem trigger não contam como calculáveis');

$repetido = Repository::importar([['nome' => 'outro-nome.csv', 'conteudo' => $janeiro]], 'teste');
sxCheck($repetido['resultados'][0]['status'] === 'duplicado', 'o mesmo conteúdo com outro nome é barrado pelo hash');

echo "\n── Consolidado ────────────────────────────────────────────\n";
$dados = Repository::consolidado(2026);
sxCheck($dados['ano'] === 2026, 'ano correto');
sxCheck(count($dados['cats']) === 2, 'só as categorias com dado aparecem');
$ids = array_column($dados['cats'], 'id');
sort($ids);
sxCheck($ids === ['AG', 'ES'], 'a expressão da categoria classificou Agências e Escritórios sozinha');

$janelaJan = 5040 / ((100 - 99.81) / 100) + 1320 / ((100 - 99.95) / 100) + 3180 / ((100 - 99.88) / 100);
$downJan = 5040 + 1320 + 3180;
sxCheck(sxPerto($dados['geral'][0], 100 * (1 - $downJan / $janelaJan), 0.01),
	'janeiro é a ponderação de tempo parado sobre janela');
sxCheck($dados['geral'][1] < $dados['geral'][0], 'fevereiro cai com a piora do equipamento');
sxCheck($dados['geral'][2] === null, 'mês sem importação fica vazio, não zerado');
sxCheck($dados['totais']['expurgados'] === 2 && $dados['totais']['semTrigger'] === 2,
	'expurgados e sem trigger são contados à parte');
sxCheck($dados['totais']['equipamentos'] === 3, 'três equipamentos entram no cálculo');
sxCheck(count($dados['rank']) === 2 && $dados['rank'][0]['sla'] >= $dados['rank'][1]['sla'],
	'ranking ordenado do melhor para o pior');
sxCheck(count($dados['heat']) === 2 && count($dados['heat'][0]['valores']) === 12,
	'mapa de calor com doze meses por grupo');
sxCheck($dados['budget'] !== [] && $dados['budget'][0]['orcamento'] > 0,
	'orçamento de erro calculado por categoria');

echo "\n── Método de cálculo ──────────────────────────────────────\n";
Repository::salvarConfig(['titulo' => 'ENERGIA', 'meta' => '99,00', 'desafio' => '99,70', 'metodo' => 'media'], 'teste');
$media = Repository::consolidado(2026);
sxCheck(sxPerto($media['geral'][0], (99.81 + 99.95 + 99.88) / 3, 0.0001),
	'no modo média simples o mês é a média dos percentuais');
sxCheck($media['config']['titulo'] === 'ENERGIA', 'título gravado no banco');
Repository::salvarConfig(['titulo' => 'ENERGIA', 'meta' => '99,00', 'desafio' => '99,70', 'metodo' => 'ponderada'], 'teste');

sxCheck(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $dados['config']['atualizado_em']) === 1,
	'atualizado_em vem em ISO 8601 com dois-pontos no fuso (o Date() do JS recusa "+00" sem eles)');

echo "\n── Recusa de parâmetro inválido ───────────────────────────\n";
function sxRecusa(callable $fn, string $mensagem): void {
	try {
		$fn();
		sxCheck(false, $mensagem.' (deveria ter sido recusado)');
	}
	catch (InvalidArgumentException $e) {
		sxCheck(true, $mensagem);
	}
}

sxRecusa(fn() => Repository::salvarConfig(['titulo' => 'X', 'meta' => '101', 'desafio' => '99'], 'teste'), 'meta acima de 100');
sxRecusa(fn() => Repository::salvarConfig(['titulo' => 'X', 'meta' => '99,5', 'desafio' => '99'], 'teste'), 'desafio abaixo da meta');
sxRecusa(fn() => Repository::salvarConfig(['titulo' => '', 'meta' => '99', 'desafio' => '99'], 'teste'), 'título vazio');
sxRecusa(fn() => Repository::salvarCategorias([
	['id' => 'AG', 'nome' => 'Agências', 'sigla' => 'ag', 'cor' => '#1E4380', 'padrao' => 'ag(enc']
], 'teste'), 'padrão de classificação que não compila é recusado');

echo "\n── Classificação manual ────────────────────────────────────\n";
Repository::salvarMapa(['Agências SP' => 'OP'], 'teste');
$dados = Repository::consolidado(2026);
sxCheck(in_array('OP', array_column($dados['cats'], 'id'), true), 'a classificação manual vence a expressão da categoria');
Repository::salvarMapa(['Agências SP' => 'AG'], 'teste');

echo "\n── Exportações ──────────────────────────────────────────────\n";
$csv = Repository::exportarCsv(2026);
sxCheck(strpos($csv, "\xEF\xBB\xBF") === 0, 'CSV começa com BOM UTF-8');
$semBom = substr($csv, 3);
sxCheck(strpos($semBom, '"Indicador"') === 0, 'primeira linha identifica o indicador');
sxCheck(strpos($csv, ';') !== false && preg_match('/"99,\d\d"/', $csv) === 1, 'ponto e vírgula com vírgula decimal');

$json = Repository::exportarJson(2026);
$decodificado = json_decode($json, true);
sxCheck($decodificado['ano'] === 2026, 'JSON exportado traz o ano');

echo "\n── Remoção ────────────────────────────────────────────────\n";
$importacoes = Repository::listarImportacoes();
Repository::removerImportacao($importacoes[0]['id'], 'teste');
$restantes = Db::consultarUm('SELECT count(*) AS total FROM sla_medicao');
sxCheck((int) $restantes['total'] === 5, 'apagar a importação leva junto as medições dela');
sxCheck(Repository::removerImportacao(999999, 'teste') === false, 'importação inexistente devolve falso');

echo "\n── Dados de exemplo ─────────────────────────────────────────\n";
$demo = Repository::demo(2026, 'teste');
sxCheck($demo['linhas'] > 300, 'demo carrega o universo de exemplo');
$dados = Repository::consolidado(2026);
sxCheck(count(array_filter($dados['geral'])) >= 7, 'demo preenche sete meses');
sxCheck($dados['acumGeral'] > 99 && $dados['acumGeral'] < 100, 'acumulado do demo fica na faixa esperada');

echo "\n── Formato largo ──────────────────────────────────────────\n";
$largo = Parser::ler('largo.csv', "Unidade;Peso;JAN;FEV;MAR\nEQT-PI;3;99,87;99,80;99,75\nEQT-AL;2;99,83;99,70;99,60");
sxCheck(count($largo['medicoes']) === 6 && $largo['formato'] === 'largo', 'formato largo lido');
sxCheck($largo['medicoes'][0]['peso'] === 3.0, 'peso lido da planilha');

echo "\n── Conversores ────────────────────────────────────────────\n";
sxCheck(Parser::numero('1.234,56') === 1234.56, 'número com separador de milhar');
sxCheck(Parser::numero('N/D') === null, 'N/D não vira zero');
sxCheck(Parser::data('01/02/2026')->format('n') === '2', 'data em dd/mm/aaaa');

echo "\n── Auditoria ──────────────────────────────────────────────\n";
$acoes = array_column(Db::consultar('SELECT DISTINCT acao FROM sla_auditoria'), 'acao');
sxCheck(in_array('importacao', $acoes, true) && in_array('config.update', $acoes, true) && in_array('mapa.update', $acoes, true),
	'importação, alteração de parâmetros e classificação ficam registradas');

sxLimpar();
echo "\n";

if ($falhas > 0) {
	fwrite(STDERR, $falhas." verificação(ões) falharam.\n");
	exit(1);
}

echo "Todas as verificações do Repository passaram.\n";
