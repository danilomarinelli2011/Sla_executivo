<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Services;

use API;
use APP;
use RuntimeException;
use Throwable;

/**
 * Coleta de disponibilidade direto do Zabbix, sem CSV.
 *
 * Dois motores, na ordem:
 *
 * 1. **Referência** — se o módulo RelatorioDisponibilidade estiver instalado
 *    no mesmo frontend, chama o ReportService dele com a configuração dele
 *    (padrões de trigger, meta, cobertura, expurgo). É o mesmo número que o
 *    relatório oficial produz, porque é o mesmo código.
 * 2. **Interno** — sem o módulo de referência, reproduz as regras dele com a
 *    API do Zabbix: trigger de disponibilidade identificada por padrão de
 *    nome; eventos de problema com recuperação (incidente aberto conta até o
 *    fim do período); intervalos sobrepostos de várias triggers fundidos;
 *    manutenções de janela única descontadas; host sem trigger vira
 *    "Sem trigger de disponibilidade", nunca 100 %.
 *
 * O resultado entra em sla_medicao pelo mesmo caminho do CSV, então as visões
 * mensal e semanal não sabem de onde o dado veio — e a coleta da mesma janela
 * substitui a anterior (hash determinístico), o que permite "atualizar" uma
 * semana sem duplicar.
 */
final class ZabbixSource {

	/** Padrões usados quando não há módulo de referência nem configuração própria. */
	public const PADROES_PADRAO = ['unavailable by icmp', 'icmp ping', 'is not available', 'unreachable', 'indispon'];

	private const REFERENCIA_NAMESPACE = 'RelatorioDisponibilidade';
	private const MAX_GRUPOS = 20;
	private const DIAS_ANTES = 31;

	// ── Configuração efetiva ─────────────────────────────────────────────────

	/**
	 * @return array{origem: string, modulo: ?string, versao: ?string, patterns: array<int, string>,
	 *               slo: float, calendar: array<string, mixed>, purge: string, aviso: string}
	 */
	public static function fonte(): array {
		$meta = 99.0;

		try {
			$meta = Repository::config()['meta'];
		}
		catch (Throwable $e) {
			// sem banco ainda: o SLO cai no padrão
		}

		$ref = self::referencia();

		if ($ref !== null) {
			$c = $ref['config'];

			return [
				'origem' => 'referencia', 'modulo' => $ref['id'], 'versao' => $ref['versao'], 'caminho' => $ref['caminho'],
				'patterns' => $c['patterns'] !== [] ? $c['patterns'] : self::PADROES_PADRAO,
				'slo' => $c['slo'] ?? $meta,
				'calendar' => $c['calendar'],
				'purge' => $c['purge'],
				'purge_opcoes' => ['off' => _('Sem expurgo'), 'discount' => _('Descontar janelas de manutenção do SLA'), 'exclude' => _('Remover hosts em manutenção do cálculo')],
				'aviso' => $ref['aviso'],
				'diagnostico' => $ref['diagnostico']
			];
		}

		$modulo = Config::module();
		$guardado = $modulo !== null ? $modulo->getConfig() : [];
		$guardado = is_array($guardado) ? $guardado : [];
		$patterns = self::listaDePadroes($guardado['zbx_patterns'] ?? '');

		return [
			'origem' => 'proprio', 'modulo' => null, 'versao' => null, 'caminho' => null,
			'patterns' => $patterns !== [] ? $patterns : self::PADROES_PADRAO,
			'slo' => $meta,
			'calendar' => ['mode' => '24x7', 'timezone' => date_default_timezone_get()],
			'purge' => in_array($guardado['zbx_purge'] ?? '', ['off', 'discount'], true) ? $guardado['zbx_purge'] : 'discount',
			'purge_opcoes' => ['off' => _('Sem expurgo'), 'discount' => _('Descontar janelas de manutenção do SLA'), 'exclude' => _('Remover hosts em manutenção do cálculo')],
			'aviso' => _('Módulo RelatorioDisponibilidade não encontrado: usando o motor interno (cobertura 24x7, manutenções de janela única).'),
			'diagnostico' => self::$diagnostico
		];
	}

	/** @var array<int, string> O que a busca pela referência encontrou, para a tela. */
	private static array $diagnostico = [];

	/**
	 * Raiz do frontend (a pasta que contém modules/). Em produção é deduzida:
	 * services → SlaExecutivo → modules → raiz. Os testes a sobrescrevem.
	 */
	public static ?string $raiz = null;

	/**
	 * Localiza o módulo de referência — pelo registro do frontend ou, se ele
	 * estiver desabilitado, pelo manifest.json no disco — e lê a configuração
	 * pela classe ModuleConfig dele, que é quem sabe as chaves.
	 *
	 * @return array{id: string, versao: string, caminho: string, config: array<string, mixed>, aviso: string, diagnostico: array<int, string>}|null
	 */
	public static function referencia(): ?array {
		self::$diagnostico = [];
		$raiz = self::$raiz !== null ? realpath(self::$raiz) : realpath(__DIR__.'/../../../');
		$pasta = null;
		$manifest = null;

		// 1. Módulos habilitados.
		try {
			foreach (APP::ModuleManager()->getModules() as $m) {
				$mf = $m->getManifest();
				$ns = (string) ($mf['namespace'] ?? '');
				$id = (string) ($mf['id'] ?? '');
				self::$diagnostico[] = sprintf('módulo habilitado: %s (namespace %s, pasta %s)', $id, $ns, $m->getRelativePath());

				if ($ns === self::REFERENCIA_NAMESPACE || stripos($id, 'relatorio-disponibilidade') !== false || stripos($id, 'relatorio_disponibilidade') !== false) {
					$pasta = $raiz !== false ? realpath($raiz.'/'.trim($m->getRelativePath(), '/')) : false;
					$manifest = $mf;
					break;
				}
			}
		}
		catch (Throwable $e) {
			self::$diagnostico[] = 'ModuleManager indisponível: '.$e->getMessage();
		}

		// 2. Disco: cobre módulo desabilitado ou registro ainda não escaneado.
		if (!$pasta && $raiz !== false) {
			foreach (glob($raiz.'/modules/*/manifest.json') ?: [] as $arquivo) {
				$mf = json_decode((string) file_get_contents($arquivo), true);

				if (is_array($mf) && (($mf['namespace'] ?? '') === self::REFERENCIA_NAMESPACE)) {
					$pasta = dirname($arquivo);
					$manifest = $mf;
					self::$diagnostico[] = 'encontrado no disco (não listado como habilitado): '.$pasta;
					break;
				}
			}
		}

		if (!$pasta || $manifest === null) {
			self::$diagnostico[] = 'nenhum módulo com namespace '.self::REFERENCIA_NAMESPACE.' em '.($raiz ?: '?').'/modules';
			return null;
		}

		$service = $pasta.'/services/ReportService.php';

		if (!is_file($service)) {
			self::$diagnostico[] = 'pasta encontrada, mas sem services/ReportService.php: '.$pasta;
			return null;
		}

		self::$diagnostico[] = 'ReportService encontrado em '.$service;

		// Configuração pela classe do próprio módulo.
		$config = ['patterns' => [], 'slo' => null, 'calendar' => ['mode' => '24x7', 'timezone' => date_default_timezone_get()], 'purge' => 'discount'];
		$aviso = '';
		$mc = $pasta.'/services/ModuleConfig.php';

		if (is_file($mc)) {
			try {
				require_once $mc;
				$classe = '\\Modules\\'.self::REFERENCIA_NAMESPACE.'\\Services\\ModuleConfig';

				if (class_exists($classe) && method_exists($classe, 'get')) {
					$settings = $classe::get();
					$settings = is_array($settings) ? $settings : [];
					$config['patterns'] = array_values(array_filter(array_map('strval', (array) ($settings['trigger_patterns'] ?? []))));
					$config['slo'] = isset($settings['slo']) ? (float) $settings['slo'] : null;

					if (is_array($settings['calendar'] ?? null)) {
						$config['calendar'] = $settings['calendar'];
					}
					elseif (isset($settings['calendar_mode'])) {
						$config['calendar'] = ['mode' => (string) $settings['calendar_mode'], 'timezone' => (string) ($settings['timezone'] ?? date_default_timezone_get())];
					}

					$purge = (string) ($settings['purge_maintenance'] ?? $settings['maintenance_mode'] ?? 'discount');
					$config['purge'] = in_array($purge, ['off', 'discount', 'exclude'], true) ? $purge : 'discount';
					self::$diagnostico[] = sprintf('ModuleConfig::get() lido: %d padrão(ões), SLO %s, expurgo %s',
						count($config['patterns']), $config['slo'] === null ? '—' : $config['slo'], $config['purge']);

					if ($config['patterns'] === [] && defined($classe.'::DEFAULT_TRIGGER_PATTERNS')) {
						$config['patterns'] = (array) constant($classe.'::DEFAULT_TRIGGER_PATTERNS');
					}
				}
				else {
					$aviso = _('ModuleConfig do módulo de referência sem método get(): usando padrões e expurgo padrão.');
				}
			}
			catch (Throwable $e) {
				$aviso = _('Não foi possível ler a configuração do módulo de referência: ').$e->getMessage();
				self::$diagnostico[] = $aviso;
			}
		}

		return [
			'id' => (string) ($manifest['id'] ?? 'relatorio_disponibilidade'),
			'versao' => (string) ($manifest['version'] ?? ''),
			'caminho' => $pasta,
			'config' => $config,
			'aviso' => $aviso,
			'diagnostico' => self::$diagnostico
		];
	}

	/**
	 * @param mixed $valor  Lista, ou texto com um padrão por linha/vírgula.
	 *
	 * @return array<int, string>
	 */
	public static function listaDePadroes($valor): array {
		$itens = is_array($valor) ? $valor : preg_split('/[\r\n,;]+/', (string) $valor);
		$saida = [];

		foreach ($itens as $item) {
			$item = trim((string) $item);

			if ($item !== '' && !in_array($item, $saida, true)) {
				$saida[] = $item;
			}
		}

		return $saida;
	}

	// ── Grupos ───────────────────────────────────────────────────────────────

	/**
	 * @return array<int, array{groupid: string, name: string, hosts: int}>
	 */
	public static function gruposDeHosts(): array {
		$grupos = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'selectHosts' => 'count',
			'with_monitored_hosts' => true,
			'sortfield' => 'name'
		]);

		if (!is_array($grupos)) {
			throw new RuntimeException(_('A API do Zabbix não devolveu os grupos de hosts.'));
		}

		return array_map(static fn(array $g) => [
			'groupid' => (string) $g['groupid'], 'name' => (string) $g['name'], 'hosts' => (int) ($g['hosts'] ?? 0)
		], $grupos);
	}

	// ── Coleta ───────────────────────────────────────────────────────────────

	/**
	 * Coleta um período para uma lista de grupos e grava no banco.
	 *
	 * @param array<int, string> $groupids
	 *
	 * @return array<string, mixed>  Resumo por grupo.
	 */
	public static function coletar(array $groupids, int $from, int $till, string $usuario, ?string $purge = null): array {
		$groupids = array_values(array_unique(array_filter(array_map('strval', $groupids), 'ctype_digit')));

		if ($groupids === []) {
			throw new RuntimeException(_('Escolha ao menos um grupo de hosts.'));
		}

		if (count($groupids) > self::MAX_GRUPOS) {
			throw new RuntimeException(sprintf(_('No máximo %1$d grupos por coleta.'), self::MAX_GRUPOS));
		}

		if ($till <= $from) {
			throw new RuntimeException(_('Período inválido.'));
		}

		$till = min($till, time());
		$fonte = self::fonte();

		if ($purge !== null && array_key_exists($purge, $fonte['purge_opcoes'])) {
			$fonte['purge'] = $purge;
		}
		$grupos = API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $groupids]);

		if (!is_array($grupos) || $grupos === []) {
			throw new RuntimeException(_('Nenhum dos grupos informados existe ou está acessível ao seu usuário.'));
		}

		$resumo = [];

		foreach ($grupos as $grupo) {
			$medicoes = null;
			$motor = 'interno';

			if ($fonte['origem'] === 'referencia') {
				try {
					$medicoes = self::viaReferencia($grupo, $from, $till, $fonte);
					$motor = 'referencia';
				}
				catch (Throwable $e) {
					$medicoes = null;   // cai para o interno, mas registra o motivo
					$motivo = $e->getMessage();
				}
			}

			if ($medicoes === null) {
				$medicoes = self::viaInterno($grupo, $from, $till, $fonte);
			}

			foreach ($medicoes as &$m) {
				$m['observacao'] = trim(($m['observacao'] ?? '').' motor:'.$motor.(isset($motivo) ? ' (referência falhou: '.$motivo.')' : ''));
			}
			unset($m);

			$nome = sprintf('zabbix:%s:%s..%s', $grupo['name'], date('Y-m-d', $from), date('Y-m-d', $till - 1));
			// Identidade da janela = grupo + período. Padrões e expurgo NÃO entram:
			// recoletar com outra regra deve substituir, não coexistir — senão a
			// mesma semana apareceria duas vezes no quadro.
			$hash = hash('sha256', 'zabbix|'.$grupo['groupid'].'|'.$from.'|'.$till);
			$id = Repository::gravarColeta($nome, $hash, $medicoes, $usuario);

			$resumo[] = [
				'grupo' => $grupo['name'], 'groupid' => (string) $grupo['groupid'], 'importacao_id' => $id, 'motor' => $motor,
				'hosts' => count($medicoes),
				'calculaveis' => count(array_filter($medicoes, static fn($m) => $m['calculavel'])),
				'sem_trigger' => count(array_filter($medicoes, static fn($m) => stripos($m['situacao'], 'sem trigger') !== false)),
				'expurgados' => count(array_filter($medicoes, static fn($m) => stripos($m['situacao'], 'expurg') !== false))
			];
		}

		Db::auditar($usuario, 'coleta.zabbix', ['grupos' => count($resumo), 'de' => date('c', $from), 'ate' => date('c', $till), 'motor' => $fonte['origem']]);

		return ['periodo' => ['de' => date('Y-m-d', $from), 'ate' => date('Y-m-d', $till - 1)], 'fonte' => $fonte, 'grupos' => $resumo];
	}

	/**
	 * Motor de referência: o ReportService do RelatorioDisponibilidade.
	 *
	 * @param array{groupid: string, name: string} $grupo
	 * @param array<string, mixed> $fonte
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function viaReferencia(array $grupo, int $from, int $till, array $fonte): array {
		$ref = self::referencia();

		if ($ref === null) {
			throw new RuntimeException('módulo de referência ausente');
		}

		foreach (['ModuleConfig', 'BusinessCalendar', 'MaintenanceService', 'ReportService'] as $classe) {
			$arquivo = $ref['caminho'].'/services/'.$classe.'.php';

			if (is_file($arquivo)) {
				require_once $arquivo;
			}
		}

		$classe = '\\Modules\\'.self::REFERENCIA_NAMESPACE.'\\Services\\ReportService';

		if (!class_exists($classe)) {
			throw new RuntimeException('ReportService de referência não carregou');
		}

		$versao = defined('ZABBIX_VERSION') ? ZABBIX_VERSION : '7.0';
		$relatorio = $classe::generate(
			(string) $grupo['groupid'], (string) $grupo['name'],
			date('Y-m-d', $from), date('Y-m-d', $till - 1),
			$from, $till, $versao,
			$fonte['patterns'], (float) $fonte['slo'], $fonte['calendar'],
			['maintenance_mode' => $fonte['purge']]
		);

		if (!is_array($relatorio) || !isset($relatorio['hosts'])) {
			throw new RuntimeException('retorno inesperado do ReportService');
		}

		return self::mapearReferencia($relatorio, $grupo['name'], $from, $till);
	}

	/**
	 * Converte o relatório de referência no formato de sla_medicao.
	 *
	 * @param array<string, mixed> $relatorio
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function mapearReferencia(array $relatorio, string $grupo, int $from, int $till): array {
		$saida = [];

		// Hosts expurgados: manual ("Expurgo SLA - host - ...") ou manutenção que
		// cobriu o período inteiro. Ficam fora do cálculo, com o motivo na observação.
		foreach ((array) ($relatorio['purged_hosts'] ?? []) as $p) {
			$saida[] = self::linha($grupo, (string) ($p['host'] ?? $p['name'] ?? ''), $from, $till, 'Expurgado',
				null, null, 0, trim((string) ($p['reason_label'] ?? $p['reason'] ?? 'expurgo')));
		}

		foreach ((array) $relatorio['hosts'] as $h) {
			$nome = (string) ($h['host'] ?? $h['name'] ?? '');
			$temDados = array_key_exists('has_data', $h) ? (bool) $h['has_data'] : ($h['sla_percent'] ?? $h['sla'] ?? null) !== null;
			$janela = isset($h['window_seconds']) ? (float) $h['window_seconds'] : (float) ($till - $from);
			$down = (float) ($h['total_downtime_sec'] ?? 0);
			$incidentes = is_array($h['incidents'] ?? null) ? count($h['incidents']) : (int) ($h['incidents'] ?? 0);
			$obs = (float) ($h['maintenance_discounted_sec'] ?? 0) > 0
				? sprintf(_('%1$s descontados por manutenção'), gmdate('H\hi', (int) $h['maintenance_discounted_sec'])) : '';

			$saida[] = self::linha($grupo, $nome, $from, $till,
				$temDados ? 'Monitorado' : 'Sem trigger de disponibilidade',
				$temDados ? $down : null, $temDados ? $janela : null, $incidentes, $obs);
		}

		return $saida;
	}

	/**
	 * Motor interno.
	 *
	 * @param array{groupid: string, name: string} $grupo
	 * @param array<string, mixed> $fonte
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function viaInterno(array $grupo, int $from, int $till, array $fonte): array {
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'], 'groupids' => [$grupo['groupid']],
			'filter' => ['status' => 0], 'sortfield' => 'name'
		]);

		if (!is_array($hosts)) {
			throw new RuntimeException(_('A API do Zabbix não devolveu os hosts do grupo.'));
		}

		if ($hosts === []) {
			return [];
		}

		$hostids = array_column($hosts, 'hostid');
		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description'], 'hostids' => $hostids,
			'selectHosts' => ['hostid'], 'monitored' => true
		]);
		$triggers = is_array($triggers) ? $triggers : [];

		// Triggers de disponibilidade por host, pelo nome.
		$porHost = [];

		foreach ($triggers as $t) {
			if (!self::ehDisponibilidade((string) $t['description'], $fonte['patterns'])) {
				continue;
			}

			foreach ((array) ($t['hosts'] ?? []) as $h) {
				$porHost[(string) $h['hostid']][] = (string) $t['triggerid'];
			}
		}

		$triggerids = array_unique(array_merge(...array_values($porHost) ?: [[]]));
		$intervalos = [];   // hostid => [[ini, fim], ...]
		$incidentes = [];   // hostid => n

		if ($triggerids !== []) {
			// Problemas que podem tocar o período: começam até o fim dele e
			// (para pegar incidentes abertos antes) desde DIAS_ANTES antes.
			$eventos = API::Event()->get([
				'output' => ['eventid', 'objectid', 'clock', 'r_eventid'], 'selectHosts' => ['hostid'],
				'source' => 0, 'object' => 0, 'value' => 1, 'objectids' => array_values($triggerids),
				'time_from' => $from - self::DIAS_ANTES * 86400, 'time_till' => $till,
				'sortfield' => ['clock'], 'sortorder' => 'ASC', 'limit' => 100000
			]);
			$eventos = is_array($eventos) ? $eventos : [];

			$rids = array_values(array_unique(array_filter(array_column($eventos, 'r_eventid'), static fn($r) => $r !== '0' && $r !== 0 && $r !== null)));
			$recuperacao = [];

			foreach (array_chunk($rids, 5000) as $lote) {
				$rec = API::Event()->get(['output' => ['eventid', 'clock'], 'eventids' => $lote]);

				foreach (is_array($rec) ? $rec : [] as $r) {
					$recuperacao[(string) $r['eventid']] = (int) $r['clock'];
				}
			}

			foreach ($eventos as $e) {
				$ini = (int) $e['clock'];
				$rid = (string) ($e['r_eventid'] ?? '0');
				$fim = ($rid !== '0' && isset($recuperacao[$rid])) ? $recuperacao[$rid] : $till;   // aberto conta até o fim

				foreach ((array) ($e['hosts'] ?? []) as $h) {
					$hid = (string) $h['hostid'];

					if (!isset($porHost[$hid])) {
						continue;
					}

					$intervalos[$hid][] = [$ini, $fim];

					if ($ini >= $from && $ini < $till) {
						$incidentes[$hid] = ($incidentes[$hid] ?? 0) + 1;
					}
				}
			}
		}

		$manutencoes = in_array($fonte['purge'], ['discount', 'exclude'], true) ? self::janelasDeManutencao($hostids, $from, $till) : [];
		$saida = [];

		foreach ($hosts as $h) {
			$hid = (string) $h['hostid'];
			$nome = (string) $h['name'];

			if (!isset($porHost[$hid])) {
				$saida[] = self::linha($grupo['name'], $nome, $from, $till, 'Sem trigger de disponibilidade', null, null, 0, '');
				continue;
			}

			$desconto = $manutencoes[$hid] ?? [];

			// Modo "exclude" do módulo de referência: host com manutenção no
			// período sai do cálculo inteiro, marcado como expurgado.
			if ($fonte['purge'] === 'exclude' && $desconto !== []) {
				$saida[] = self::linha($grupo['name'], $nome, $from, $till, 'Expurgado', null, null, 0,
					sprintf(_('%1$d manutenção(ões) no período — host removido do cálculo'), count($desconto)));
				continue;
			}

			$janela = ($till - $from) - self::downtime($desconto, $from, $till);
			$down = self::downtime($intervalos[$hid] ?? [], $from, $till, $desconto);

			$saida[] = self::linha($grupo['name'], $nome, $from, $till, 'Monitorado',
				(float) $down, (float) max($janela, 0), (int) ($incidentes[$hid] ?? 0),
				$desconto !== [] ? sprintf(_('%1$d manutenção(ões) descontada(s)'), count($desconto)) : '');
		}

		return $saida;
	}

	/**
	 * Segundos parados dentro de [from, till), com intervalos fundidos e as
	 * janelas de manutenção subtraídas. Puro: é o que os testes exercitam.
	 *
	 * @param array<int, array{0: int, 1: int}> $intervalos
	 * @param array<int, array{0: int, 1: int}> $descontar
	 */
	public static function downtime(array $intervalos, int $from, int $till, array $descontar = []): int {
		$fundidos = self::fundir($intervalos, $from, $till);
		$total = 0;

		foreach ($fundidos as [$a, $b]) {
			$total += $b - $a;

			foreach (self::fundir($descontar, $a, $b) as [$c, $d]) {
				$total -= $d - $c;
			}
		}

		return max($total, 0);
	}

	/**
	 * Recorta a [from, till), ordena e funde sobreposições.
	 *
	 * @param array<int, array{0: int, 1: int}> $intervalos
	 *
	 * @return array<int, array{0: int, 1: int}>
	 */
	public static function fundir(array $intervalos, int $from, int $till): array {
		$lista = [];

		foreach ($intervalos as [$a, $b]) {
			$a = max((int) $a, $from);
			$b = min((int) $b, $till);

			if ($b > $a) {
				$lista[] = [$a, $b];
			}
		}

		usort($lista, static fn($x, $y) => $x[0] <=> $y[0]);
		$saida = [];

		foreach ($lista as $atual) {
			$n = count($saida);

			if ($n > 0 && $atual[0] <= $saida[$n - 1][1]) {
				$saida[$n - 1][1] = max($saida[$n - 1][1], $atual[1]);
			}
			else {
				$saida[] = $atual;
			}
		}

		return $saida;
	}

	/**
	 * Manutenções de janela única (timeperiod_type 0) que alcançam os hosts.
	 * Recorrentes ficam de fora — o módulo de referência trata; o interno avisa.
	 *
	 * @param array<int, string> $hostids
	 *
	 * @return array<string, array<int, array{0: int, 1: int}>>  hostid => janelas
	 */
	private static function janelasDeManutencao(array $hostids, int $from, int $till): array {
		$lista = API::Maintenance()->get([
			'output' => ['maintenanceid', 'active_since', 'active_till'],
			'hostids' => $hostids, 'selectHosts' => ['hostid'], 'selectHostGroups' => ['groupid'],
			'selectTimeperiods' => ['timeperiod_type', 'start_date', 'period']
		]);

		if (!is_array($lista)) {
			return [];
		}

		$grupoHosts = null;
		$saida = [];

		foreach ($lista as $m) {
			$janelas = [];

			foreach ((array) ($m['timeperiods'] ?? []) as $tp) {
				if ((int) ($tp['timeperiod_type'] ?? -1) !== 0) {
					continue;
				}

				$ini = (int) $tp['start_date'];
				$fim = $ini + (int) $tp['period'];
				$ini = max($ini, (int) $m['active_since']);
				$fim = min($fim, (int) $m['active_till']);

				if ($fim > $ini) {
					$janelas[] = [$ini, $fim];
				}
			}

			if ($janelas === []) {
				continue;
			}

			$alvo = array_map('strval', array_column((array) ($m['hosts'] ?? []), 'hostid'));
			$gids = array_column((array) ($m['hostgroups'] ?? []), 'groupid');

			if ($gids !== []) {
				if ($grupoHosts === null) {
					$grupoHosts = [];
					$hs = API::Host()->get(['output' => ['hostid'], 'hostids' => $hostids, 'selectHostGroups' => ['groupid']]);

					foreach (is_array($hs) ? $hs : [] as $h) {
						foreach ((array) ($h['hostgroups'] ?? $h['groups'] ?? []) as $g) {
							$grupoHosts[(string) $g['groupid']][] = (string) $h['hostid'];
						}
					}
				}

				foreach ($gids as $gid) {
					$alvo = array_merge($alvo, $grupoHosts[(string) $gid] ?? []);
				}
			}

			foreach (array_unique($alvo) as $hid) {
				if (in_array($hid, $hostids, true)) {
					foreach ($janelas as $j) {
						$saida[$hid][] = $j;
					}
				}
			}
		}

		return $saida;
	}

	public static function ehDisponibilidade(string $descricao, array $patterns): bool {
		// Com o módulo de referência carregado, a regra é a dele (literal ou regex).
		$classe = '\\Modules\\'.self::REFERENCIA_NAMESPACE.'\\Services\\ReportService';

		if (class_exists($classe, false) && method_exists($classe, 'isAvailabilityTrigger')) {
			try {
				$m = new \ReflectionMethod($classe, 'isAvailabilityTrigger');
				$m->setAccessible(true);

				return (bool) $m->invoke(null, $descricao, $patterns);
			}
			catch (Throwable $e) {
				// cai no matcher local
			}
		}

		$texto = mb_strtolower($descricao);
		$semAcento = Parser::semAcento($descricao);

		foreach ($patterns as $p) {
			$p = trim((string) $p);

			if ($p === '') {
				continue;
			}

			// /regex/mods ou regex:… — a mesma sintaxe do módulo de referência.
			if (preg_match('#^/(.+)/([a-z]*)$#s', $p, $r) === 1) {
				if (@preg_match('/'.str_replace('/', '\\/', $r[1]).'/'.(strpos($r[2], 'i') === false ? 'i' : '').$r[2], $descricao) === 1) {
					return true;
				}

				continue;
			}

			if (preg_match('/^regexp?:\s*(.+)$/is', $p, $r) === 1) {
				if (@preg_match('~'.str_replace('~', '\\~', $r[1]).'~i', $descricao) === 1) {
					return true;
				}

				continue;
			}

			$pl = mb_strtolower($p);

			if (mb_strpos($texto, $pl) !== false || strpos($semAcento, Parser::semAcento($p)) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function linha(string $grupo, string $host, int $from, int $till, string $situacao,
			?float $down, ?float $janela, int $incidentes, string $obs): array {
		$inicio = new \DateTime('@'.$from);
		$inicio->setTimezone(new \DateTimeZone(date_default_timezone_get()));
		$fimData = new \DateTime('@'.($till - 1));
		$fimData->setTimezone(new \DateTimeZone(date_default_timezone_get()));
		$sla = ($janela !== null && $janela > 0 && $down !== null) ? round(100 * (1 - $down / $janela), 6) : null;

		return [
			'grupo' => $grupo, 'equipamento' => $host, 'situacao' => $situacao,
			'ano' => (int) $inicio->format('Y'), 'mes' => (int) $inicio->format('n'),
			'periodo_inicio' => $inicio->format('Y-m-d'), 'periodo_fim' => $fimData->format('Y-m-d'),
			'semana' => Parser::semanaDoMes($inicio),
			'sla' => $sla, 'meta_slo' => null,
			'indisponibilidade_s' => $down !== null ? round($down, 2) : null,
			'janela_s' => $janela !== null ? round($janela, 2) : null,
			'incidentes' => $incidentes, 'peso' => 1.0,
			'calculavel' => $sla !== null, 'observacao' => $obs
		];
	}
}
