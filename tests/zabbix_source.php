<?php declare(strict_types = 1);

/**
 * Verificações da coleta direta do Zabbix (motor interno).
 *
 *     DB_SERVER_HOST=... php tests/zabbix_source.php
 *
 * A API do Zabbix é simulada com um cenário controlado; o banco é real, para
 * conferir que a coleta grava e que repetir a mesma janela substitui em vez
 * de duplicar.
 */

// ── Stubs do núcleo e da API do Zabbix ──────────────────────────────────────
namespace Zabbix\Core {
	class CModule {
		public function getConfig() { return []; }
		public function setConfig(array $c) {}
		public function getRelativePath(): string { return 'modules/SlaExecutivo'; }
		public function getManifest(): array { return ['namespace' => 'SlaExecutivo', 'id' => 'sla_executivo']; }
	}
}

namespace {
	if (!function_exists('_')) {
		function _(string $s): string { return $s; }
	}

	date_default_timezone_set('America/Sao_Paulo');

	final class SxApiStub {
		public static array $dados = [];
		public function __construct(private string $tipo) {}
		public function get(array $o) {
			$d = self::$dados[$this->tipo] ?? [];
			if ($this->tipo === 'event' && isset($o['eventids'])) {
				return array_values(array_filter(self::$dados['recovery'] ?? [], fn($e) => in_array($e['eventid'], $o['eventids'], true)));
			}
			if ($this->tipo === 'hostgroup' && isset($o['groupids'])) {
				return array_values(array_filter($d, fn($g) => in_array($g['groupid'], $o['groupids'], true)));
			}
			return $d;
		}
	}
	final class API {
		public static function HostGroup() { return new SxApiStub('hostgroup'); }
		public static function Host() { return new SxApiStub('host'); }
		public static function Trigger() { return new SxApiStub('trigger'); }
		public static function Event() { return new SxApiStub('event'); }
		public static function Maintenance() { return new SxApiStub('maintenance'); }
	}
	final class APP {
		public static function ModuleManager() {
			return new class {
				public function getModule($id) { return new \Zabbix\Core\CModule(); }
				public function getModules(): array { return []; }   // sem módulo de referência
			};
		}
	}

	require_once __DIR__.'/../services/Db.php';
	require_once __DIR__.'/../services/Config.php';
	require_once __DIR__.'/../services/Parser.php';
	require_once __DIR__.'/../services/Repository.php';
	require_once __DIR__.'/../services/ZabbixSource.php';

	use Modules\SlaExecutivo\Services\Db;
	use Modules\SlaExecutivo\Services\Repository;
	use Modules\SlaExecutivo\Services\ZabbixSource;

	$falhas = 0;
	function sxCheck(bool $c, string $m): void { global $falhas; if ($c) { echo "  ok   $m\n"; } else { $falhas++; echo "  FALHA $m\n"; } }

	echo "── Fusão de intervalos e desconto ─────────────────────────\n";
	// Semana já encerrada (agosto/2026 começa num sábado: 2–8 é a semana 2). A coleta
	// recorta em "agora", então uma semana em curso daria janela parcial — correto, mas
	// não é o que se quer conferir aqui.
	$from = strtotime('2026-08-02 00:00:00'); $till = strtotime('2026-08-09 00:00:00');
	sxCheck(ZabbixSource::downtime([[$from + 100, $from + 400], [$from + 300, $from + 700]], $from, $till) === 600,
		'intervalos sobrepostos de duas triggers contam uma vez só (600 s, não 700)');
	sxCheck(ZabbixSource::downtime([[$from - 3600, $from + 1800]], $from, $till) === 1800,
		'incidente que começou antes do período conta só a parte dentro dele');
	sxCheck(ZabbixSource::downtime([[$till - 900, $till + 5000]], $from, $till) === 900,
		'incidente aberto no fim conta até o fim do período');
	sxCheck(ZabbixSource::downtime([[$from + 1000, $from + 5000]], $from, $till, [[$from + 2000, $from + 3000]]) === 3000,
		'janela de manutenção dentro do incidente é descontada');
	sxCheck(ZabbixSource::downtime([[$from + 1000, $from + 2000]], $from, $till, [[$from + 5000, $from + 6000]]) === 1000,
		'manutenção fora do incidente não desconta nada');
	sxCheck(ZabbixSource::ehDisponibilidade('Host indisponível por ICMP', ['icmp ping', 'indispon']),
		'padrão casa sem acento e sem diferenciar maiúsculas');
	sxCheck(!ZabbixSource::ehDisponibilidade('CPU utilization is high', ZabbixSource::PADROES_PADRAO),
		'trigger de CPU não é de disponibilidade');
	sxCheck(ZabbixSource::listaDePadroes("ICMP Ping,\n is not available ;icmp ping;;") === ['ICMP Ping', 'is not available', 'icmp ping'],
		'lista de padrões separa por vírgula/linha, ignora vazios e preserva a caixa (regex do RD é sensível a ela)');

	echo "\n── Coleta com API simulada ─────────────────────────────────\n";
	Db::conexao();
	Db::executar('TRUNCATE sla_medicao, sla_importacao RESTART IDENTITY');
	Db::executar('DELETE FROM sla_grupo_categoria');

	SxApiStub::$dados = [
		'hostgroup' => [['groupid' => '10', 'name' => 'Agências SP']],
		'host' => [
			['hostid' => '1', 'name' => 'AG-SP-001'],
			['hostid' => '2', 'name' => 'AG-SP-002'],
			['hostid' => '3', 'name' => 'AG-SP-003'],   // sem trigger de disponibilidade
		],
		'trigger' => [
			['triggerid' => '100', 'description' => 'Unavailable by ICMP ping', 'hosts' => [['hostid' => '1']]],
			['triggerid' => '101', 'description' => 'Zabbix agent is not available (for 3m)', 'hosts' => [['hostid' => '1']]],
			['triggerid' => '102', 'description' => 'Unavailable by ICMP ping', 'hosts' => [['hostid' => '2']]],
			['triggerid' => '103', 'description' => 'CPU utilization is high', 'hosts' => [['hostid' => '3']]],
		],
		'event' => [
			// host 1: dois problemas sobrepostos (icmp + agente) → 2 h fundidas
			['eventid' => '500', 'objectid' => '100', 'clock' => (string) ($from + 3600), 'r_eventid' => '600', 'hosts' => [['hostid' => '1']]],
			['eventid' => '501', 'objectid' => '101', 'clock' => (string) ($from + 5400), 'r_eventid' => '601', 'hosts' => [['hostid' => '1']]],
			// host 2: incidente de 1 h totalmente dentro de manutenção de 2 h
			['eventid' => '502', 'objectid' => '102', 'clock' => (string) ($from + 10000), 'r_eventid' => '602', 'hosts' => [['hostid' => '2']]],
		],
		'recovery' => [
			['eventid' => '600', 'clock' => (string) ($from + 3600 + 7200)],
			['eventid' => '601', 'clock' => (string) ($from + 5400 + 3600)],
			['eventid' => '602', 'clock' => (string) ($from + 10000 + 3600)],
		],
		'maintenance' => [[
			'maintenanceid' => '7', 'active_since' => (string) ($from + 9000), 'active_till' => (string) ($from + 20000),
			'hosts' => [['hostid' => '2']], 'hostgroups' => [],
			'timeperiods' => [['timeperiod_type' => '0', 'start_date' => (string) ($from + 9000), 'period' => '7200']]
		]]
	];

	$fonte = ZabbixSource::fonte();
	sxCheck($fonte['origem'] === 'proprio', 'sem o módulo de referência, o motor é o interno');

	$r = ZabbixSource::coletar(['10'], $from, $till, 'teste');
	$g = $r['grupos'][0];
	sxCheck($g['hosts'] === 3 && $g['calculaveis'] === 2 && $g['sem_trigger'] === 1, '3 hosts: 2 calculáveis, 1 sem trigger');

	$linhas = Db::consultar('SELECT * FROM sla_medicao ORDER BY equipamento');
	$h1 = $linhas[0]; $h2 = $linhas[1]; $h3 = $linhas[2];
	$janela = $till - $from;
	sxCheck((float) $h1['indisponibilidade_s'] == 7200.0, 'host 1: 2 h paradas (icmp 2 h e agente 1 h sobrepostos, fundidos)');
	sxCheck((float) $h1['janela_s'] == (float) $janela, 'host 1: janela = a semana inteira (24x7)');
	sxCheck(abs((float) $h1['sla'] - 100 * (1 - 7200 / $janela)) < 0.0001, 'host 1: SLA = 1 − parado/janela');
	sxCheck((int) $h1['incidentes'] === 2, 'host 1: dois incidentes contados');
	sxCheck((float) $h2['indisponibilidade_s'] == 0.0 && (float) $h2['janela_s'] == (float) ($janela - 7200), 'host 2: incidente dentro da manutenção descontado, janela reduzida em 2 h');
	sxCheck($h3['sla'] === null && $h3['calculavel'] === false && stripos($h3['situacao'], 'sem trigger') !== false, 'host 3: sem trigger vira N/D, nunca 100%');
	sxCheck((int) $h1['semana'] === 2 && (int) $h1['mes'] === 8 && $h1['periodo_inicio'] === '2026-08-02', 'período e semana deduzidos da janela coletada');
	sxCheck(strpos($h1['observacao'], 'motor:interno') !== false, 'a linha registra qual motor calculou');

	// Modo exclude no motor interno: o host 2 (com manutenção) sai do cálculo.
	ZabbixSource::coletar(['10'], $from, $till, 'teste', 'exclude');
	$ex = Db::consultarUm("SELECT situacao, calculavel FROM sla_medicao WHERE equipamento = 'AG-SP-002'");
	sxCheck($ex !== null && stripos($ex['situacao'], 'expurg') !== false && $ex['calculavel'] === false,
		'modo exclude: host com manutenção vira Expurgado e sai do cálculo');
	ZabbixSource::coletar(['10'], $from, $till, 'teste', 'discount');

	ZabbixSource::coletar(['10'], $from, $till, 'teste');
	sxCheck((int) Db::consultarUm('SELECT count(*) AS n FROM sla_importacao')['n'] === 1, 'coletar a mesma janela de novo substitui, não duplica');
	sxCheck((int) Db::consultarUm('SELECT count(*) AS n FROM sla_medicao')['n'] === 3, 'as medições também são substituídas');

	$S = Repository::semanal(2026, 8);
	sxCheck(count($S['unidades']) === 1 && $S['unidades'][0]['unidade'] === 'SP', 'o quadro semanal enxerga a coleta (unidade SP)');
	sxCheck($S['unidades'][0]['cats'][0]['semanas'][1] !== null, 'semana 2 preenchida a partir da coleta');

	echo "\n── Motor de referência (RelatorioDisponibilidade) ─────────\n";
	// O módulo de referência é procurado em <raiz>/modules. Aqui a raiz é uma
	// pasta temporária com um stub que expõe a mesma interface do RD
	// (ModuleConfig::get, ReportService::generate/isAvailabilityTrigger).
	$docroot = sys_get_temp_dir().'/sx-docroot-'.getmypid();
	@mkdir($docroot.'/modules', 0777, true);
	ZabbixSource::$raiz = $docroot;
	$pastaRd = $docroot.'/modules/RelatorioDisponibilidade';
	$stub = !is_dir($pastaRd);

	if ($stub) {
		@mkdir($pastaRd.'/services', 0777, true);
		file_put_contents($pastaRd.'/manifest.json', json_encode(['manifest_version' => 2.0, 'id' => 'djs-relatorio-disponibilidade',
			'namespace' => 'RelatorioDisponibilidade', 'version' => '1.15.0']));
		file_put_contents($pastaRd.'/services/ModuleConfig.php', '<?php
namespace Modules\RelatorioDisponibilidade\Services;
final class ModuleConfig {
	public const DEFAULT_TRIGGER_PATTERNS = ["host unreachable by icmp"];
	public static function get(): array { return ["slo" => 99.5, "trigger_patterns" => ["/^SD-WAN .*link down$/", "icmp ping"], "purge_maintenance" => "exclude"]; }
}');
		file_put_contents($pastaRd.'/services/ReportService.php', '<?php
namespace Modules\RelatorioDisponibilidade\Services;
final class ReportService {
	public static array $chamadas = [];
	public static function generate($groupid, $groupname, $from, $to, $ts_from, $ts_till, $version, $patterns, $slo, $calendar, $purge) {
		self::$chamadas[] = compact("groupid", "patterns", "slo", "purge");
		$janela = $ts_till - $ts_from;
		return [
			"hosts" => [
				["hostid" => "1", "host" => "AG-SP-001", "has_data" => true, "sla_percent" => 99.5, "total_downtime_sec" => 3024, "window_seconds" => $janela, "maintenance_discounted_sec" => 600, "incidents" => [[], []]],
				["hostid" => "3", "host" => "AG-SP-003", "has_data" => false, "sla_percent" => null, "total_downtime_sec" => 0, "window_seconds" => $janela, "incidents" => []],
			],
			"purged_hosts" => [["hostid" => "2", "host" => "AG-SP-002", "reason" => "manual", "reason_label" => "Expurgo SLA manual"]],
			"purge" => ["maintenance_mode" => $purge["maintenance_mode"]]
		];
	}
	private static function isAvailabilityTrigger(string $d, array $patterns): bool {
		foreach ($patterns as $p) { if ($p[0] === "/") { if (@preg_match($p."i", $d) === 1) return true; } elseif (stripos($d, $p) !== false) return true; }
		return false;
	}
}');
	}

	$fonte = ZabbixSource::fonte();
	sxCheck($fonte['origem'] === 'referencia', 'com o módulo de referência presente, o motor é o dele (origem=referencia)');
	if ($stub) {
		sxCheck($fonte['patterns'] === ['/^SD-WAN .*link down$/', 'icmp ping'], 'os padrões vêm de ModuleConfig::get(), regex preservada');
		sxCheck($fonte['purge'] === 'exclude' && isset($fonte['purge_opcoes']['exclude']), 'o expurgo padrão vem da configuração dele, com as três opções');
		sxCheck(abs($fonte['slo'] - 99.5) < 0.001, 'o SLO vem da configuração dele');
		sxCheck(ZabbixSource::ehDisponibilidade('SD-WAN [WAN1]: link down', $fonte['patterns']), 'a regex do módulo de referência casa via o matcher dele');
		sxCheck(!ZabbixSource::ehDisponibilidade('Aviso SD-WAN link down agora', $fonte['patterns']), 'âncoras da regex respeitadas');

		Db::executar('TRUNCATE sla_medicao, sla_importacao RESTART IDENTITY');
		$r = ZabbixSource::coletar(['10'], $from, $till, 'teste', 'discount');
		$g = $r['grupos'][0];
		sxCheck($g['motor'] === 'referencia', 'a coleta usou o ReportService de referência');
		$ch = \Modules\RelatorioDisponibilidade\Services\ReportService::$chamadas;
		sxCheck($ch !== [] && $ch[0]['purge']['maintenance_mode'] === 'discount' && $ch[0]['patterns'] === $fonte['patterns'],
			'o expurgo escolhido na tela e os padrões dele chegam ao generate()');
		sxCheck($g['hosts'] === 3 && $g['calculaveis'] === 1 && $g['sem_trigger'] === 1 && $g['expurgados'] === 1, 'mapeamento: 1 calculável, 1 sem trigger, 1 expurgado');
		$linhas = Db::consultar('SELECT * FROM sla_medicao ORDER BY equipamento');
		sxCheck(abs((float) $linhas[0]['sla'] - 100 * (1 - 3024 / ($till - $from))) < 0.0001 && (int) $linhas[0]['incidentes'] === 2, 'host calculável: SLA pela janela dele e incidentes contados');
		sxCheck(strpos($linhas[0]['observacao'], 'descontados por manutenção') !== false && strpos($linhas[0]['observacao'], 'motor:referencia') !== false, 'observação registra o desconto e o motor');
		sxCheck($linhas[1]['situacao'] === 'Expurgado' && $linhas[1]['sla'] === null && strpos($linhas[1]['observacao'], 'Expurgo SLA manual') !== false, 'host expurgado fora do cálculo, com o motivo');
		sxCheck(stripos($linhas[2]['situacao'], 'sem trigger') !== false && $linhas[2]['sla'] === null, 'has_data=false vira sem trigger');

		foreach (['services/ModuleConfig.php', 'services/ReportService.php', 'manifest.json'] as $f) { @unlink($pastaRd.'/'.$f); }
		@rmdir($pastaRd.'/services'); @rmdir($pastaRd); @rmdir($docroot.'/modules');
	}

	Db::executar('TRUNCATE sla_medicao, sla_importacao RESTART IDENTITY');
	echo "\n";
	if ($falhas > 0) { fwrite(STDERR, "$falhas verificação(ões) falharam.\n"); exit(1); }
	echo "Todas as verificações da coleta direta passaram.\n";
}
