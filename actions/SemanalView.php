<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\SlaExecutivo\Services\Config;
use Modules\SlaExecutivo\Services\Repository;

require_once __DIR__.'/../services/Config.php';
require_once __DIR__.'/../services/Db.php';
require_once __DIR__.'/../services/Repository.php';

/**
 * Acompanhamento semanal por unidade — o quadro da diretoria.
 *
 * Mesmo desenho da página mensal: o PHP entrega o esqueleto e o estado do
 * banco; os números chegam pela action sla.executivo.api (path=semanal).
 */
final class SemanalView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([]);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$saude = Repository::saude();

		$this->setResponse(new CControllerResponseData([
			'title' => _('SLA Executivo — Semanal'),
			'logo_url' => Config::logoUrlVerificada(),
			'is_admin' => $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN,
			'db_ok' => $saude['ok'],
			'db_erro' => $saude['erro'],
			'csrf_token' => CCsrfTokenHelper::get('sla.executivo.api'),
			'csrf_field' => CCsrfTokenHelper::CSRF_TOKEN_NAME
		]));
	}
}
