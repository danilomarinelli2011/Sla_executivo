<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Actions;

use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use InvalidArgumentException;
use Modules\SlaExecutivo\Services\Config;
use Modules\SlaExecutivo\Services\Db;
use Modules\SlaExecutivo\Services\InputCompat;
use Modules\SlaExecutivo\Services\Repository;

require_once __DIR__.'/../services/InputCompat.php';
require_once __DIR__.'/../services/Config.php';
require_once __DIR__.'/../services/Db.php';
require_once __DIR__.'/../services/Repository.php';

/**
 * Grava a conexão de banco opcional e o logotipo.
 *
 * No caminho normal ninguém precisa mexer aqui: o módulo já usa a mesma
 * conexão do Zabbix. Esta tela existe só para quem quiser isolar os dados do
 * SLA num banco à parte.
 */
final class SettingsUpdate extends CController {

	use InputCompat;

	protected function init(): void {
		$this->initInputCompat();
	}

	protected function checkInput(): bool {
		$valido = $this->validateInputCompat([
			'db_host' => ['string'],
			'db_port' => ['string'],
			'db_name' => ['string'],
			'db_user' => ['string'],
			'db_password' => ['string'],
			'logo_url' => ['string']
		], [
			'db_host' => 'string', 'db_port' => 'string', 'db_name' => 'string',
			'db_user' => 'string', 'db_password' => 'string', 'logo_url' => 'string'
		]);

		if (!$valido) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $valido;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	}

	protected function doAction(): void {
		$resposta = new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'sla.executivo.view')->getUrl()
		);

		try {
			Config::salvar([
				'db_host' => $this->getInput('db_host', ''),
				'db_port' => $this->getInput('db_port', ''),
				'db_name' => $this->getInput('db_name', ''),
				'db_user' => $this->getInput('db_user', ''),
				'db_password' => $this->getInput('db_password', ''),
				'logo_url' => $this->getInput('logo_url', '')
			]);

			// Confere na hora: a próxima conexão já usa os novos parâmetros, então
			// vale testar antes de mandar o usuário de volta para a tela.
			Db::reiniciar();
			$saude = Repository::saude();

			if ($saude['ok']) {
				CMessageHelper::setSuccessTitle(_('Conexão salva. O banco respondeu.'));
			}
			else {
				CMessageHelper::setWarningTitle(_('Conexão salva, mas o banco não respondeu.'));
				CMessageHelper::addWarning($saude['erro']);
			}
		}
		catch (InvalidArgumentException $e) {
			CMessageHelper::setErrorTitle(_('Não foi possível salvar a conexão.'));
			CMessageHelper::addError($e->getMessage());
		}
		catch (\Exception $e) {
			CMessageHelper::setErrorTitle(_('Não foi possível salvar a conexão.'));
			CMessageHelper::addError($e->getMessage());
		}

		$this->setResponse($resposta);
	}
}
