<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use Modules\SlaExecutivo\Services\Config;
use Modules\SlaExecutivo\Services\Db;
use Modules\SlaExecutivo\Services\Repository;

require_once __DIR__.'/../services/Config.php';
require_once __DIR__.'/../services/Db.php';
require_once __DIR__.'/../services/Repository.php';

/**
 * Página do painel executivo de SLA.
 *
 * Entrega o esqueleto e o estado de saúde do banco. Os dados em si a tela busca
 * depois, pela action sla.executivo.api, para a página abrir rápido mesmo com o
 * histórico grande.
 */
final class ReportView extends CController {

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
		$is_admin = $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
		$saude = Repository::saude();
		$parametros = Db::parametros();

		$this->setResponse(new CControllerResponseData([
			'title' => _('SLA Executivo'),
			'logo_url' => Config::logoUrlVerificada(),
			'is_admin' => $is_admin,
			'db_ok' => $saude['ok'],
			'db_erro' => $saude['erro'],
			'db_medicoes' => $saude['medicoes'],
			'db_usando_ambiente' => $saude['usando_ambiente'],
			'db_host' => $parametros['host'],
			'db_name' => $parametros['dbname'],
			'db_config_atual' => Config::module() !== null ? (array) (Config::module()->getConfig() ?: []) : [],
			'csrf_token' => CCsrfTokenHelper::get('sla.executivo.api'),
			'csrf_settings' => CCsrfTokenHelper::get('sla.executivo.settings.update'),
			'csrf_field' => CCsrfTokenHelper::CSRF_TOKEN_NAME
		]));
	}
}
