<?php declare(strict_types = 1);

/**
 * SLA Executivo — acompanhamento semanal por unidade.
 *
 * O esqueleto é montado aqui; os quadros por unidade, o ranking e os gráficos
 * são desenhados por assets/js/sla-semanal.js a partir do consolidado semanal
 * (sla.executivo.api, path=semanal).
 *
 * @var CView $this
 * @var array $data
 */

require_once __DIR__.'/sx_helpers.php';

$is_admin = (bool) $data['is_admin'];
$db_ok = (bool) $data['db_ok'];

$context = json_encode([
	'db_ok' => $db_ok,
	'can_write' => $is_admin,
	'csrf_token' => (string) $data['csrf_token'],
	'csrf_field' => (string) $data['csrf_field']
], JSON_UNESCAPED_UNICODE);

// ── Faixa de identidade ─────────────────────────────────────────────────────
$head = [];

if ($data['logo_url'] !== null) {
	$head[] = sx_void('img', ['src' => $data['logo_url'], 'alt' => 'DigiMon']);
}

$head[] = sx_tag('div', [_('Acompanhamento semanal de disponibilidade'), sx_tag('span', '', ['id' => 'sx-print-title'])]);

$avisos = [];

if (!$db_ok) {
	$avisos[] = sx_tag('div', [
		sx_tag('strong', _('Não foi possível conectar ao banco de dados.')),
		sx_tag('span', (string) $data['db_erro'])
	], ['class' => 'sx-alert sx-alert-warning']);
}

// ── Barra de ações ──────────────────────────────────────────────────────────
$actions = [
	sx_tag('a', _('← Visão mensal'), ['href' => 'zabbix.php?action=sla.executivo.view', 'class' => 'sx-btn']),
	sx_field('sx-p-mes', _('Mês de referência'), sx_select('sx-p-mes', [], '')),
	sx_tag('span', '', ['class' => 'sx-toolbar-gap']),
	sx_tag('button', _('Baixar quadro semanal (CSV)'), ['type' => 'button', 'id' => 'sx-btn-csv', 'class' => 'sx-btn sx-secondary-button']),
	sx_tag('button', _('Salvar em PDF'), ['type' => 'button', 'id' => 'sx-btn-print', 'class' => 'sx-btn sx-primary-button'])
];

// ── Pesos de negócio (Admin) ────────────────────────────────────────────────
$pesos = [
	sx_tag('div', '', ['class' => 'sx-params', 'id' => 'sx-pesos-form']),
	sx_tag('p', _('A PONDERADA de cada unidade é a soma de ACUM × peso por categoria (OP 40 · ES 35 · AG 25, na planilha da diretoria). Se uma unidade não tiver alguma categoria, a soma é normalizada pelos pesos presentes — a unidade não é penalizada por não ter a categoria.'), ['class' => 'sx-note'])
];

if ($is_admin) {
	$pesos[] = sx_tag('div', sx_tag('button', _('Salvar pesos'), ['type' => 'button', 'id' => 'sx-btn-pesos', 'class' => 'sx-btn sx-primary-button']), ['style' => 'margin-top:10px']);

	// Cadastro de categoria direto por aqui: adicionar já entra no quadro de
	// pesos, sem precisar ir até a visão mensal. Remover é por linha, no
	// próprio card (desenhado por sla-semanal.js em renderPesos()).
	$pesos[] = sx_tag('div', [
		sx_field('sx-newcat', _('Nova categoria'), sx_input('sx-newcat', '', ['placeholder' => _('Ex.: Data Center')])),
		sx_field('sx-newsig', _('Sigla'), sx_input('sx-newsig', '', ['placeholder' => 'DC', 'maxlength' => '4'])),
		sx_tag('button', _('Adicionar categoria'), ['type' => 'button', 'id' => 'sx-btn-addcat', 'class' => 'sx-btn'])
	], ['class' => 'sx-params', 'style' => 'margin-top:12px']);
	$pesos[] = sx_tag('p', _('Remover uma categoria some com ela do quadro de pesos e desfaz a classificação manual de grupos que apontavam para ela — os grupos afetados caem de volta para a categoria pela expressão, ou "Não classificado".'), ['class' => 'sx-note']);
}

// ── Unidades por grupo (Admin) ──────────────────────────────────────────────
$unidades = [
	sx_tag('div', '', ['id' => 'sx-unidades-list']),
	sx_tag('p', _('A unidade é deduzida do código de duas letras maiúsculas no fim do nome do grupo ("Agências SP" → SP). O que for apontado à mão vence; deixe em branco para voltar à dedução.'), ['class' => 'sx-note'])
];

// ── Conteúdo ────────────────────────────────────────────────────────────────
$report = sx_tag('div', [
	sx_tag('div', '', ['class' => 'sx-kpis', 'id' => 'sx-kpis']),

	sx_card(_('Ranking semanal de disponibilidade ponderada'),
		sx_tag('div', sx_tag('canvas', null, ['id' => 'sx-ch-ranking']), ['class' => 'sx-chartbox sx-chartbox-tall']),
		'', [], 'sx-ranking-title'),

	sx_card(_('Rede — todas as unidades'), [
		sx_tag('div', sx_tag('table', '', ['class' => 'sx-matrix sx-matrix-semanal', 'id' => 'sx-rede-tabela']), ['class' => 'sx-tablewrap']),
		sx_tag('div', sx_tag('canvas', null, ['id' => 'sx-ch-rede']), ['class' => 'sx-chartbox', 'style' => 'margin-top:14px'])
	], _('barras: geral por semana · linhas: unidades'), [], 'sx-rede-title'),

	sx_tag('div', '', ['id' => 'sx-unidades']),

	sx_card(_('Leitura dos números'), sx_tag('div', '', ['id' => 'sx-findings']))
], ['id' => 'sx-report', 'style' => 'display:none']);

$conteudo = [
	sx_tag('div', $head, ['class' => 'sx-print-head']),
	sx_tag('div', $avisos, ['class' => 'sx-noprint']),
	sx_tag('div', $actions, ['class' => 'sx-toolbar sx-noprint']),
	sx_card(_('Pesos de negócio por categoria'), $pesos, '', ['class' => 'sx-card sx-noprint']),
	sx_card(_('Unidade de cada grupo de hosts'), $unidades, _('define os quadros desta página'),
		['class' => 'sx-card sx-noprint', 'id' => 'sx-sec-unidades', 'style' => 'display:none']),
	$report,
	sx_tag('div', _('Consultando o banco...'), ['class' => 'sx-empty sx-card', 'id' => 'sx-empty']),
	sx_tag('div', '', ['class' => 'sx-toast', 'id' => 'sx-toast'])
];

(new CHtmlPage())
	->setTitle(_('SLA Executivo — Semanal'))
	->setDocUrl('https://www.digisystem.cloud')
	->addItem(sx_tag('div', $conteudo, [
		'id' => 'sx-app',
		'class' => 'sx-app sx-semanal',
		'data-sx-config' => (string) $context
	]))
	->show();
