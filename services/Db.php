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
	private static function garantirSchema(PDO $pdo): void {
		if (self::$schema_conferido) {
			return;
		}

		self::$schema_conferido = true;

		$existe = $pdo->query("SELECT to_regclass('public.sla_config') AS t")->fetch();

		if ($existe && $existe['t'] !== null) {
			return;
		}

		$arquivo = __DIR__.'/../sql/001_schema.sql';

		if (!is_file($arquivo)) {
			throw new RuntimeException(_('Arquivo de schema do SLA Executivo não encontrado.'));
		}

		// Uma transação: ou cria tudo, ou nada fica pela metade se algo falhar
		// no meio (por exemplo, falta de permissão para criar tabela).
		$pdo->beginTransaction();

		try {
			$pdo->exec((string) file_get_contents($arquivo));
			$pdo->commit();
		}
		catch (PDOException $e) {
			$pdo->rollBack();

			throw new RuntimeException(
				_('Não foi possível criar as tabelas do SLA Executivo: ').$e->getMessage()
			);
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
