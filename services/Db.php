<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Services;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexão com o banco.
 *
 * Zero configuração no caminho normal: o container do frontend
 * (zabbix-web-apache-pgsql) já recebe DB_SERVER_HOST, DB_SERVER_PORT,
 * POSTGRES_DB, POSTGRES_USER e POSTGRES_PASSWORD para falar com o Postgres do
 * próprio Zabbix — são as mesmas variáveis do seu docker-compose.yaml. Este
 * módulo lê essas variáveis e cria suas tabelas (prefixo sla_) no mesmo banco,
 * então não existe container novo para subir.
 *
 * Quem preferir isolar os dados do SLA num banco à parte pode gravar uma
 * conexão diferente em Administration → General → Modules → SLA Executivo →
 * Configurar; o que for gravado ali tem prioridade sobre o ambiente.
 */
final class Db {

	private static ?PDO $conexao = null;
	private static bool $schema_conferido = false;

	/**
	 * Descarta a conexão em cache. Usado depois de salvar uma conexão nova, para
	 * a checagem de saúde seguinte já usar os parâmetros atuais em vez dos da
	 * requisição anterior.
	 */
	public static function reiniciar(): void {
		self::$conexao = null;
		self::$schema_conferido = false;
	}

	public static function conexao(): PDO {
		if (self::$conexao !== null) {
			return self::$conexao;
		}

		$config = self::parametros();

		$dsn = sprintf(
			'pgsql:host=%s;port=%s;dbname=%s',
			$config['host'],
			$config['port'],
			$config['dbname']
		);

		try {
			$pdo = new PDO($dsn, $config['user'], $config['password'], [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_TIMEOUT => 5
			]);
		}
		catch (PDOException $e) {
			throw new RuntimeException(
				_('Não foi possível conectar ao banco de dados do SLA Executivo: ').$e->getMessage()
			);
		}

		self::$conexao = $pdo;
		self::garantirSchema($pdo);

		return $pdo;
	}

	/**
	 * @return array{host: string, port: string, dbname: string, user: string, password: string}
	 */
	public static function parametros(): array {
		$modulo = Config::module();
		$guardado = $modulo !== null ? $modulo->getConfig() : [];
		$guardado = is_array($guardado) ? $guardado : [];

		// Override explícito primeiro; senão, o mesmo ambiente que o Zabbix usa.
		return [
			'host' => self::valor($guardado, 'db_host', 'DB_SERVER_HOST', 'localhost'),
			'port' => self::valor($guardado, 'db_port', 'DB_SERVER_PORT', '5432'),
			'dbname' => self::valor($guardado, 'db_name', 'POSTGRES_DB', 'zabbix'),
			'user' => self::valor($guardado, 'db_user', 'POSTGRES_USER', 'zabbix'),
			'password' => self::valor($guardado, 'db_password', 'POSTGRES_PASSWORD', '')
		];
	}

	/**
	 * Verdadeiro quando nada foi sobrescrito na tela: o módulo está usando a
	 * mesma conexão do Zabbix, sem nenhuma configuração própria.
	 */
	/**
	 * Diagnóstico completo, para a tela: de onde vem cada parâmetro, se a
	 * extensão existe, se conecta, se consegue criar tabela, quais migrações
	 * rodaram. É o que se olha quando "está dando erro" sem dizer qual.
	 *
	 * @return array<string, mixed>
	 */
	public static function diagnostico(): array {
		$modulo = Config::module();
		$guardado = $modulo !== null ? $modulo->getConfig() : [];
		$guardado = is_array($guardado) ? $guardado : [];
		$origem = static function (string $chave_config, string $chave_ambiente) use ($guardado): string {
			if (trim((string) ($guardado[$chave_config] ?? '')) !== '') {
				return 'tela';
			}

			return trim((string) getenv($chave_ambiente)) !== '' ? 'ambiente ('.$chave_ambiente.')' : 'padrão';
		};

		$p = self::parametros();
		$saida = [
			'extensao_pdo_pgsql' => extension_loaded('pdo_pgsql'),
			'parametros' => [
				'host' => ['valor' => $p['host'], 'origem' => $origem('db_host', 'DB_SERVER_HOST')],
				'port' => ['valor' => $p['port'], 'origem' => $origem('db_port', 'DB_SERVER_PORT')],
				'dbname' => ['valor' => $p['dbname'], 'origem' => $origem('db_name', 'POSTGRES_DB')],
				'user' => ['valor' => $p['user'], 'origem' => $origem('db_user', 'POSTGRES_USER')],
				'password' => ['valor' => $p['password'] !== '' ? '••••••' : '(vazia)', 'origem' => $origem('db_password', 'POSTGRES_PASSWORD')]
			],
			'conecta' => false, 'versao' => '', 'cria_tabela' => false, 'migracoes' => [], 'medicoes' => 0, 'erro' => ''
		];

		if (!$saida['extensao_pdo_pgsql']) {
			$saida['erro'] = _('A extensão PHP pdo_pgsql não está carregada neste frontend.');
			return $saida;
		}

		try {
			$pdo = self::conexao();
			$saida['conecta'] = true;
			$saida['versao'] = (string) $pdo->query('SHOW server_version')->fetchColumn();
			$saida['migracoes'] = array_column($pdo->query('SELECT arquivo FROM sla_migracao ORDER BY 1')->fetchAll(), 'arquivo');
			$saida['medicoes'] = (int) $pdo->query('SELECT count(*) FROM sla_medicao')->fetchColumn();

			// Cria e apaga uma tabela temporária: prova a permissão sem deixar rastro.
			$pdo->exec('CREATE TEMP TABLE sla_diag_tmp (x int)');
			$pdo->exec('DROP TABLE sla_diag_tmp');
			$saida['cria_tabela'] = true;
		}
		catch (\Throwable $e) {
			$saida['erro'] = $e->getMessage();
		}

		return $saida;
	}

	/**
	 * Testa parâmetros digitados, sem gravar nada e sem tocar na conexão em uso.
	 *
	 * @param array<string, string> $p  host, port, dbname, user, password
	 *
	 * @return array{ok: bool, mensagem: string}
	 */
	public static function testar(array $p): array {
		if (!extension_loaded('pdo_pgsql')) {
			return ['ok' => false, 'mensagem' => _('A extensão PHP pdo_pgsql não está carregada.')];
		}

		$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $p['host'], $p['port'] !== '' ? $p['port'] : '5432', $p['dbname']);

		try {
			$pdo = new PDO($dsn, $p['user'], $p['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
			$versao = (string) $pdo->query('SHOW server_version')->fetchColumn();
			$pdo->exec('CREATE TEMP TABLE sla_diag_tmp (x int)');
			$pdo->exec('DROP TABLE sla_diag_tmp');
			$tem = $pdo->query("SELECT to_regclass('public.sla_config')")->fetchColumn();

			return ['ok' => true, 'mensagem' => sprintf(_('Conectou ao PostgreSQL %1$s, pode criar tabelas. %2$s'), $versao,
				$tem !== null ? _('As tabelas sla_* já existem neste banco.') : _('As tabelas sla_* ainda não existem; serão criadas na primeira abertura.'))];
		}
		catch (\Throwable $e) {
			$mensagem = $e->getMessage();
			$dica = '';

			if (stripos($mensagem, 'could not connect') !== false || stripos($mensagem, 'Connection refused') !== false
					|| stripos($mensagem, 'timeout') !== false || stripos($mensagem, 'No such file') !== false) {
				$dica = ' '._('Lembre que o endereço é resolvido de dentro do container do frontend: "localhost" aqui é o próprio container, não a sua máquina. Use o nome do serviço no compose (ex.: postgres) ou o IP do host.');
			}
			elseif (stripos($mensagem, 'password authentication failed') !== false || stripos($mensagem, 'authentication') !== false) {
				$dica = ' '._('Usuário ou senha recusados pelo Postgres. Confira também o pg_hba.conf para conexões vindas da rede do Docker.');
			}
			elseif (stripos($mensagem, 'does not exist') !== false) {
				$dica = ' '._('O banco informado não existe neste servidor.');
			}

			return ['ok' => false, 'mensagem' => $mensagem.$dica];
		}
	}

	public static function usandoAmbiente(): bool {
		$modulo = Config::module();
		$guardado = $modulo !== null ? $modulo->getConfig() : [];

		return !is_array($guardado) || array_filter([
			$guardado['db_host'] ?? '',
			$guardado['db_port'] ?? '',
			$guardado['db_name'] ?? '',
			$guardado['db_user'] ?? ''
		]) === [];
	}

	private static function valor(array $guardado, string $chave_config, string $chave_ambiente, string $padrao): string {
		$da_tela = trim((string) ($guardado[$chave_config] ?? ''));

		if ($da_tela !== '') {
			return $da_tela;
		}

		$do_ambiente = trim((string) getenv($chave_ambiente));

		return $do_ambiente !== '' ? $do_ambiente : $padrao;
	}

	/**
	 * Cria as tabelas na primeira conexão do processo, se ainda não existirem.
	 * `IF NOT EXISTS` em tudo: rodar de novo não faz mal.
	 */
	/**
	 * Aplica, uma vez cada, os arquivos de sql/ que ainda não rodaram.
	 *
	 * O registro fica em sla_migracao. Instalações da 4.0.x, que criaram o
	 * schema antes de existir esse registro, são reconhecidas pela presença de
	 * sla_config: o 001 é marcado como aplicado sem rodar de novo.
	 */
	private static function garantirSchema(PDO $pdo): void {
		if (self::$schema_conferido) {
			return;
		}

		self::$schema_conferido = true;

		$pdo->exec('CREATE TABLE IF NOT EXISTS sla_migracao (
			arquivo text PRIMARY KEY,
			aplicada_em timestamptz NOT NULL DEFAULT now()
		)');

		$existe_config = $pdo->query("SELECT to_regclass('public.sla_config') AS t")->fetch();

		if ($existe_config && $existe_config['t'] !== null) {
			$pdo->exec("INSERT INTO sla_migracao (arquivo) VALUES ('001_schema.sql') ON CONFLICT DO NOTHING");
		}

		$aplicadas = array_column($pdo->query('SELECT arquivo FROM sla_migracao')->fetchAll(), 'arquivo');
		$arquivos = glob(__DIR__.'/../sql/*.sql') ?: [];
		sort($arquivos);

		if ($arquivos === []) {
			throw new RuntimeException(_('Arquivos de schema do SLA Executivo não encontrados.'));
		}

		// Trava de aplicação: dois workers do PHP-FPM subindo a página ao mesmo
		// tempo não podem aplicar a mesma migração em paralelo.
		$pdo->exec('SELECT pg_advisory_lock(725101)');

		try {
			foreach ($arquivos as $arquivo) {
				$nome = basename($arquivo);

				if (in_array($nome, $aplicadas, true)) {
					continue;
				}

				// Uma transação por arquivo: ou ele entra inteiro, ou nada fica
				// pela metade (por exemplo, por falta de permissão).
				$pdo->beginTransaction();

				try {
					$pdo->exec((string) file_get_contents($arquivo));
					$pdo->prepare('INSERT INTO sla_migracao (arquivo) VALUES (?)')->execute([$nome]);
					$pdo->commit();
				}
				catch (PDOException $e) {
					$pdo->rollBack();

					throw new RuntimeException(
						sprintf(_('Não foi possível aplicar %1$s ao banco do SLA Executivo: %2$s'), $nome, $e->getMessage())
					);
				}
			}
		}
		finally {
			$pdo->exec('SELECT pg_advisory_unlock(725101)');
		}
	}

	/**
	 * @param array<int, mixed> $parametros
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function consultar(string $sql, array $parametros = []): array {
		$comando = self::conexao()->prepare($sql);
		$comando->execute($parametros);

		return $comando->fetchAll();
	}

	/**
	 * @param array<int, mixed> $parametros
	 */
	public static function consultarUm(string $sql, array $parametros = []): ?array {
		$comando = self::conexao()->prepare($sql);
		$comando->execute($parametros);
		$linha = $comando->fetch();

		return $linha === false ? null : $linha;
	}

	/**
	 * @param array<int, mixed> $parametros
	 */
	public static function executar(string $sql, array $parametros = []): int {
		$comando = self::conexao()->prepare($sql);
		$comando->execute($parametros);

		return $comando->rowCount();
	}

	public static function auditar(string $usuario, string $acao, array $detalhe = []): void {
		self::executar(
			'INSERT INTO sla_auditoria (usuario, acao, detalhe) VALUES (?, ?, ?::jsonb)',
			[$usuario, $acao, json_encode($detalhe, JSON_UNESCAPED_UNICODE)]
		);
	}
}
