<?php declare(strict_types = 1);

namespace Modules\SlaExecutivo;

use APP;
use CMenu;
use CMenuItem;
use Zabbix\Core\CModule;

/**
 * SLA Executivo.
 *
 * Publica o painel em Tools > SLA Executivo, a mesma seção usada pelos demais
 * módulos da DigiSystem. A seção não existe no Zabbix de fábrica: findOrAdd() a
 * cria quando o primeiro módulo sobe, e getSubMenu() já devolve um submenu vazio
 * quando ainda não há nenhum — conferido no código do 7.0.
 */
class Module extends CModule {

	private const MENU_SECTION = 'Tools';

	public function init(): void {
		$menu = APP::Component()->get('menu.main');

		if ($menu === null) {
			return;
		}

		// Tools › SLA Executivo › (Mensal | Semanal): três níveis, como
		// Administration › General › GUI no próprio Zabbix.
		$menu
			->findOrAdd(_(self::MENU_SECTION))
			->getSubMenu()
			->add(
				(new CMenuItem(_('SLA Executivo')))
					->setSubMenu(new CMenu([
						(new CMenuItem(_('SLA Executivo (Mensal)')))
							->setAction('sla.executivo.view')
							->setAliases(['sla.executivo.settings.update']),
						(new CMenuItem(_('SLA Executivo (Semanal)')))
							->setAction('sla.executivo.semanal')
					]))
			);
	}
}
