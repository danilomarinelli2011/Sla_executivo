<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Services;

use APP;
use InvalidArgumentException;
use Zabbix\Core\CModule;

/**
 * Configuração do módulo.
 *
 * No caminho padrão não há nada para configurar aqui: Db::conexao() já usa o
 * ambiente do container do frontend. O que este serviço guarda é só o que
 * pode ser sobrescrito — uma conexão de banco à parte, para quem quiser isolar
 * os dados do SLA, e o caminho do logotipo do relatório.
 */
final class Config {

	public const MODULE_ID = 'sla_executivo';

	private const LOGO_PADRAO = 'rebranding/logo_digi.png';

	public static function logoUrl(): string {
		$modulo = self::module();
		$guardado = $modulo !== null ? $modulo->getConfig() : [];
		$guardado = is_array($guardado) ? $guardado : [];

		return (string) ($guardado['logo_url'] ?? self::LOGO_PADRAO);
	}

	/**
	 * Caminho do logotipo, só quando o arquivo existe de fato no frontend.
	 */
	public static function logoUrlVerificada(): ?string {
		$url = self::logoUrl();

		if ($url === '') {
			return null;
		}

		$raiz = realpath(__DIR__.'/../../../');
		$arquivo = realpath(__DIR__.'/../../../'.$url);

		if ($raiz === false || $arquivo === false || strpos($arquivo, $raiz) !== 0 || !is_file($arquivo)) {
			return null;
		}

		return $url;
	}

	/**
	 * @param array<string, mixed> $bruto
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return array<string, string>
	 */
	public static function normalizar(array $bruto): array {
		$host = trim((string) ($bruto['db_host'] ?? ''));
		$port = trim((string) ($bruto['db_port'] ?? ''));
		$name = trim((string) ($bruto['db_name'] ?? ''));
		$user = trim((string) ($bruto['db_user'] ?? ''));
		$password = (string) ($bruto['db_password'] ?? '');
		$logo = trim((string) ($bruto['logo_url'] ?? ''));

		// Ou os quatro campos de conexão vêm preenchidos, ou nenhum: meio caminho
		// deixaria a conexão inconsistente sem ninguém perceber até a próxima
		// tentativa de uso.
		$preenchidos = array_filter([$host, $port, $name, $user]);

		if ($preenchidos !== [] && count($preenchidos) < 4) {
			throw new InvalidArgumentException(
				_('Para usar um banco à parte, preencha host, porta, banco e usuário. Deixe os quatro em branco para usar a conexão do próprio Zabbix.')
			);
		}

		if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
			throw new InvalidArgumentException(_('Porta inválida.'));
		}

		if ($logo !== '' && (preg_match('#^[A-Za-z0-9._/-]+$#', $logo) !== 1 || strpos($logo, '..') !== false)) {
			throw new InvalidArgumentException(
				_('Caminho do logotipo inválido. Use um caminho relativo dentro do frontend.')
			);
		}

		return [
			'db_host' => $host, 'db_port' => $port, 'db_name' => $name,
			'db_user' => $user, 'db_password' => $password, 'logo_url' => ltrim($logo, '/')
		];
	}

	/**
	 * @param array<string, mixed> $bruto
	 *
	 * @throws InvalidArgumentException
	 */
	public static function salvar(array $bruto): void {
		$modulo = self::module();

		if ($modulo === null) {
			throw new InvalidArgumentException(
				_('O módulo não está registrado no frontend. Faça o "Scan directory" em Administration → General → Modules.')
			);
		}

		$limpo = self::normalizar($bruto);
		$atual = $modulo->getConfig();
		$atual = is_array($atual) ? $atual : [];

		// Senha em branco no formulário mantém a que já estava gravada: a tela
		// nunca mostra o valor, então enviar vazio não pode significar apagar —
		// a menos que a conexão inteira esteja sendo limpa (os quatro em branco).
		if ($limpo['db_password'] === '' && $limpo['db_host'] !== '') {
			$limpo['db_password'] = (string) ($atual['db_password'] ?? '');
		}

		$modulo->setConfig($limpo);
	}

	public static function module(): ?CModule {
		return APP::ModuleManager()->getModule(self::MODULE_ID);
	}
}
