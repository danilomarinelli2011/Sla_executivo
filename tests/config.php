<?php declare(strict_types = 1);

/**
 * Verificações da configuração de conexão opcional do módulo.
 *
 *     php tests/config.php
 *
 * Roda fora do Zabbix: Config::normalizar() não toca no frontend, e é ela quem
 * decide o que pode ser gravado como conexão de banco à parte e caminho do
 * logotipo. No caminho padrão nada disto é usado — o módulo já fala com a
 * mesma conexão do Zabbix — mas quem quiser isolar precisa que isto barre
 * entrada inconsistente.
 */

if (!function_exists('_')) {
	function _(string $string): string {
		return $string;
	}
}

require_once __DIR__.'/../services/Config.php';

use Modules\SlaExecutivo\Services\Config;

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

function sxRecusa(callable $fn, string $mensagem): void {
	try {
		$fn();
		sxCheck(false, $mensagem.' (deveria ter sido recusado)');
	}
	catch (InvalidArgumentException $e) {
		sxCheck(true, $mensagem);
	}
}

echo "── Caminho padrão: tudo em branco ───────────────────────\n";
$config = Config::normalizar([]);
sxCheck($config['db_host'] === '' && $config['db_user'] === '', 'nada preenchido é aceito — usa a conexão do Zabbix');

echo "\n── Conexão à parte, completa ─────────────────────────────\n";
$config = Config::normalizar([
	'db_host' => 'sla-db.interno', 'db_port' => '5432', 'db_name' => 'sla', 'db_user' => 'sla'
]);
sxCheck($config['db_host'] === 'sla-db.interno', 'host gravado');
sxCheck($config['db_port'] === '5432', 'porta gravada');

echo "\n── Conexão incompleta ────────────────────────────────────\n";
sxRecusa(function () {
	Config::normalizar(['db_host' => 'sla-db.interno']);
}, 'só o host preenchido é recusado');

sxRecusa(function () {
	Config::normalizar(['db_host' => 'sla-db.interno', 'db_port' => '5432', 'db_name' => 'sla']);
}, 'faltando o usuário é recusado');

echo "\n── Porta ──────────────────────────────────────────────────\n";
sxRecusa(function () {
	Config::normalizar(['db_host' => 'h', 'db_port' => '999999', 'db_name' => 'd', 'db_user' => 'u']);
}, 'porta fora da faixa 1–65535 é recusada');

sxRecusa(function () {
	Config::normalizar(['db_host' => 'h', 'db_port' => 'abc', 'db_name' => 'd', 'db_user' => 'u']);
}, 'porta não numérica é recusada');

echo "\n── Logotipo ───────────────────────────────────────────────\n";
$config = Config::normalizar(['logo_url' => '/rebranding/logo_digi.png']);
sxCheck($config['logo_url'] === 'rebranding/logo_digi.png', 'a barra inicial é removida do caminho');

sxRecusa(function () {
	Config::normalizar(['logo_url' => '../../etc/passwd']);
}, 'caminho com ".." é recusado');

sxRecusa(function () {
	Config::normalizar(['logo_url' => 'https://exemplo/logo.png']);
}, 'logotipo em URL externa é recusado');

echo "\n";

if ($falhas > 0) {
	fwrite(STDERR, $falhas." verificação(ões) falharam.\n");
	exit(1);
}

echo "Todas as verificações de configuração passaram.\n";
