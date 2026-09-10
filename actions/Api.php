<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use CWebUser;
use InvalidArgumentException;
use Modules\SlaExecutivo\Services\InputCompat;
use Modules\SlaExecutivo\Services\Repository;
use RuntimeException;
use Throwable;

require_once __DIR__.'/../services/InputCompat.php';
require_once __DIR__.'/../services/Db.php';
require_once __DIR__.'/../services/Config.php';
require_once __DIR__.'/../services/Parser.php';
require_once __DIR__.'/../services/Repository.php';

/**
 * Ponte entre a tela e o banco.
 *
 * Um único endpoint, despachado por "path", como na versão em stack — mas sem
 * HTTP no meio: as chamadas vão direto para Repository, que fala com o mesmo
 * Postgres do Zabbix. O navegador nunca acessa o banco; só este controlador.
 */
final class Api extends CController {

	use InputCompat;

	private const PERMITIDOS = '#^(consolidado|config|categorias|mapa|importacoes(/\d+)?|demo|dados|export/(csv|json)|health)$#';

	protected function init(): void {
		$this->initInputCompat();
		// Confere o CSRF à mão em doAction(): este controlador também atende
		// GET de leitura, que não carrega token de formulário.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInputCompat([
			'path' => ['string', 'required'],
			'metodo' => ['string'],
			'payload' => ['string'],
			'ano' => ['string'],
			CCsrfTokenHelper::CSRF_TOKEN_NAME => ['string']
		], [
			'path' => 'required|string',
			'metodo' => 'string',
			'payload' => 'string',
			'ano' => 'string',
			CCsrfTokenHelper::CSRF_TOKEN_NAME => 'string'
		]);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$caminho = trim((string) $this->getInput('path', ''), '/');
		$metodo = strtoupper((string) $this->getInput('metodo', 'GET'));
		$usuario = (string) (CWebUser::$data['username'] ?? '');

		if (preg_match(self::PERMITIDOS, $caminho) !== 1) {
			$this->responder(['ok' => false, 'erro' => _('Caminho não permitido.')]);
			return;
		}

		$escrita = $metodo !== 'GET';

		if ($escrita) {
			if (!CCsrfTokenHelper::check((string) $this->getInput(CCsrfTokenHelper::CSRF_TOKEN_NAME, ''), 'sla.executivo.api')) {
				$this->responder(['ok' => false, 'erro' => _('Sessão expirada. Recarregue a página.')]);
				return;
			}

			if ($this->getUserType() < USER_TYPE_ZABBIX_ADMIN) {
				$this->responder(['ok' => false, 'erro' => _('Somente Admin pode alterar os dados do SLA.')]);
				return;
			}
		}

		$ano = (string) $this->getInput('ano', '');
		$ano = ($ano !== '' && ctype_digit($ano)) ? (int) $ano : null;
		$corpo = json_decode((string) $this->getInput('payload', '{}'), true);
		$corpo = is_array($corpo) ? $corpo : [];

		try {
			$resultado = $this->despachar($caminho, $metodo, $corpo, $ano, $usuario);
			$this->responder(['ok' => true] + $resultado);
		}
		catch (InvalidArgumentException $e) {
			// Erro de validação: mensagem já pensada para o usuário final.
			$this->responder(['ok' => false, 'erro' => $e->getMessage()]);
		}
		catch (RuntimeException $e) {
			// Falha de banco/infra.
			$this->responder(['ok' => false, 'erro' => $e->getMessage()]);
		}
		catch (Throwable $e) {
			$this->responder(['ok' => false, 'erro' => _('Erro inesperado: ').$e->getMessage()]);
		}
	}

	/**
	 * @param array<string, mixed> $corpo
	 *
	 * @return array<string, mixed>
	 */
	private function despachar(string $caminho, string $metodo, array $corpo, ?int $ano, string $usuario): array {
		if ($caminho === 'health') {
			return ['json' => Repository::saude()];
		}

		if ($caminho === 'consolidado') {
			return ['json' => Repository::consolidado($ano)];
		}

		if ($caminho === 'config') {
			return $metodo === 'PUT'
				? ['json' => ['config' => Repository::salvarConfig($corpo, $usuario)]]
				: ['json' => ['config' => Repository::config(), 'categorias' => Repository::categorias(), 'anos' => Repository::anosDisponiveis()]];
		}

		if ($caminho === 'categorias') {
			return $metodo === 'PUT'
				? ['json' => ['categorias' => Repository::salvarCategorias((array) ($corpo['categorias'] ?? []), $usuario)]]
				: ['json' => ['categorias' => Repository::categorias()]];
		}

		if ($caminho === 'mapa') {
			Repository::salvarMapa((array) ($corpo['mapa'] ?? []), $usuario);

			return ['json' => ['ok' => true]];
		}

		if ($caminho === 'importacoes' && $metodo === 'POST') {
			return ['json' => Repository::importar($this->arquivosEnviados(), $usuario)];
		}

		if ($caminho === 'importacoes') {
			return ['json' => ['importacoes' => Repository::listarImportacoes()]];
		}

		if (preg_match('#^importacoes/(\d+)$#', $caminho, $m) === 1) {
			$existe = Repository::removerImportacao((int) $m[1], $usuario);

			return $existe
				? ['json' => ['ok' => true]]
				: ['json' => null, 'erro' => _('Importação não encontrada.')];
		}

		if ($caminho === 'demo') {
			return ['json' => Repository::demo($ano, $usuario)];
		}

		if ($caminho === 'dados') {
			Repository::limparDados($usuario);

			return ['json' => ['ok' => true]];
		}

		if ($caminho === 'export/csv') {
			return ['texto' => Repository::exportarCsv($ano)];
		}

		if ($caminho === 'export/json') {
			return ['texto' => Repository::exportarJson($ano)];
		}

		return ['json' => null, 'erro' => _('Operação não reconhecida.')];
	}

	/**
	 * Arquivos do campo "arquivos[]", lidos direto do upload.
	 *
	 * @return array<int, array{nome: string, conteudo: string}>
	 */
	private function arquivosEnviados(): array {
		if (!isset($_FILES['arquivos']) || !is_array($_FILES['arquivos'])) {
			return [];
		}

		$enviados = $_FILES['arquivos'];
		$nomes = (array) ($enviados['name'] ?? []);
		$saida = [];

		foreach (array_keys($nomes) as $indice) {
			if ((int) ($enviados['error'][$indice] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
				continue;
			}

			$temporario = (string) ($enviados['tmp_name'][$indice] ?? '');

			if ($temporario === '' || !is_uploaded_file($temporario)) {
				continue;
			}

			$conteudo = file_get_contents($temporario);

			if ($conteudo === false) {
				continue;
			}

			$saida[] = ['nome' => basename((string) $nomes[$indice]), 'conteudo' => $conteudo];
		}

		return $saida;
	}

	/**
	 * @param array<string, mixed> $dados
	 */
	private function responder(array $dados): void {
		$dados += ['ok' => false, 'erro' => '', 'json' => null, 'texto' => ''];

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($dados, JSON_UNESCAPED_UNICODE)
		]));
	}
}
