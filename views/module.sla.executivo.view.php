<?php declare(strict_types = 1);

/**
 * SLA Executivo — página do módulo.
 *
 * A view monta o esqueleto e o estado da conexão. Os dados chegam depois, pelo
 * proxy, já consolidados pela API.
 *
 * @var CView $this
 * @var array $data
 */

function sx_tag(string $name, $content = null, array $attrs = []): CTag {
	$tag = new CTag($name, true, $content);

	foreach ($attrs as $key => $value) {
		$tag->setAttribute($key, $value);
	}

	return $tag;
}

/**
 * Elemento sem fechamento (input, img).
 */
function sx_void(string $name, array $attrs = []): CTag {
	$tag = new CTag($name, false);

	foreach ($attrs as $key => $value) {
		$tag->setAttribute($key, $value);
	}

	return $tag;
}

function sx_card(string $title, $body, string $subtitle = '', array $attrs = [], string $title_id = ''): CTag {
	$heading = [$title];

	if ($subtitle !== '') {
		$heading[] = sx_tag('span', $subtitle);
	}

	$title_attrs = ['class' => 'sx-card-title'];

	if ($title_id !== '') {
		$title_attrs['id'] = $title_id;
	}

	return sx_tag('section', [
		sx_tag('h2', $heading, $title_attrs),
		sx_tag('div', $body, ['class' => 'sx-card-body'])
	], array_merge(['class' => 'sx-card'], $attrs));
}

function sx_field(string $id, string $label, CTag $control): CTag {
	return sx_tag('div', [sx_tag('label', $label, ['for' => $id]), $control], ['class' => 'sx-field']);
}

function sx_input(string $id, string $value, array $attrs = []): CTag {
	return sx_void('input', array_merge(['id' => $id, 'value' => $value], $attrs));
}

/**
 * @param array<string, string> $options
 */
function sx_select(string $id, array $options, string $selected, bool $disabled = false): CTag {
	$items = [];

	foreach ($options as $value => $label) {
		$option = sx_tag('option', $label, ['value' => (string) $value]);

		if ((string) $value === $selected) {
			$option->setAttribute('selected', 'selected');
		}

		$items[] = $option;
	}

	$select = sx_tag('select', $items, ['id' => $id]);

	if ($disabled) {
		$select->setAttribute('disabled', 'disabled');
	}

	return $select;
}

function sx_chart(string $id, string $extra_class = ''): CTag {
	return sx_tag('div', sx_tag('canvas', null, ['id' => $id]), ['class' => trim('sx-chartbox '.$extra_class)]);
}

$is_admin = (bool) $data['is_admin'];
$db_ok = (bool) $data['db_ok'];

// Contexto do JavaScript. Vai em atributo, que o CTag escapa.
$context = json_encode([
	'db_ok' => $db_ok,
	'can_write' => $is_admin,
	'csrf_token' => (string) $data['csrf_token'],
	'csrf_field' => (string) $data['csrf_field'],
	'logo_url' => (string) ($data['logo_url'] ?? '')
], JSON_UNESCAPED_UNICODE);

// ── Cabeçalho de impressão ──────────────────────────────────────────────────
$print_head = [];

if ($data['logo_url'] !== null) {
	$print_head[] = sx_void('img', ['src' => $data['logo_url'], 'alt' => 'DigiMon']);
}

$print_head[] = sx_tag('div', [_('Painel Executivo de SLA'), sx_tag('span', '', ['id' => 'sx-print-title'])]);

// ── Estado do banco ──────────────────────────────────────────────────────────
$avisos = [];

if (!$db_ok) {
	$avisos[] = sx_tag('div', [
		sx_tag('strong', _('Não foi possível conectar ao banco de dados.')),
		sx_tag('span', sprintf(_('Servidor: %1$s · Banco: %2$s'), $data['db_host'], $data['db_name'])),
		sx_tag('span', (string) $data['db_erro']),
		sx_tag('span', $is_admin
			? _('Confira as credenciais no cartão "Conexão com o banco", no rodapé desta página.')
			: _('Avise um Admin para conferir a conexão do módulo.'))
	], ['class' => 'sx-alert sx-alert-warning']);
}

// ── Barra de ações ──────────────────────────────────────────────────────────
$actions = [];

if ($is_admin) {
	$actions[] = sx_tag('button', _('Carregar dados de exemplo'),
		['type' => 'button', 'id' => 'sx-btn-demo', 'class' => 'sx-btn']);
	$actions[] = sx_tag('button', _('Limpar base'),
		['type' => 'button', 'id' => 'sx-btn-clear', 'class' => 'sx-btn']);
}

$actions[] = sx_tag('span', '', ['class' => 'sx-toolbar-gap']);
$actions[] = sx_tag('button', _('Baixar consolidado (CSV)'),
	['type' => 'button', 'id' => 'sx-btn-csv', 'class' => 'sx-btn sx-secondary-button']);
$actions[] = sx_tag('button', _('Baixar dados (JSON)'),
	['type' => 'button', 'id' => 'sx-btn-json', 'class' => 'sx-btn']);
$actions[] = sx_tag('button', _('Salvar em PDF'),
	['type' => 'button', 'id' => 'sx-btn-print', 'class' => 'sx-btn sx-primary-button']);

// ── Importação (só Admin grava) ─────────────────────────────────────────────
$corpo_drop = [
	sx_tag('div', _('Arraste aqui os CSVs exportados do Relatório de Disponibilidade'), ['class' => 'sx-drop-title']),
	sx_tag('p', _('Cada arquivo entra no banco uma única vez: conteúdo repetido é recusado pelo hash, então não há risco de dobrar o mês.')),
	sx_tag('div', [
		sx_tag('button', _('Selecionar arquivos'),
			['type' => 'button', 'id' => 'sx-btn-pick', 'class' => 'sx-btn sx-primary-button']),
		sx_tag('button', _('Baixar modelo de CSV'),
			['type' => 'button', 'id' => 'sx-btn-model', 'class' => 'sx-btn'])
	], ['class' => 'sx-btnrow']),
	sx_input('sx-filein', '', ['type' => 'file', 'accept' => '.csv,text/csv', 'multiple' => 'multiple', 'hidden' => 'hidden']),
	sx_tag('div', '', ['class' => 'sx-files', 'id' => 'sx-filelist'])
];

$drop = $is_admin
	? sx_tag('div', $corpo_drop, ['class' => 'sx-drop sx-noprint', 'id' => 'sx-drop'])
	: sx_card(_('Importações no banco'),
		sx_tag('div', '', ['class' => 'sx-files', 'id' => 'sx-filelist']),
		_('a importação é feita por um Admin'),
		['class' => 'sx-card sx-noprint']);

// ── Parâmetros ──────────────────────────────────────────────────────────────
$somente_leitura = $is_admin ? [] : ['disabled' => 'disabled'];

$params = [
	sx_tag('div', [
		sx_field('sx-p-titulo', _('Indicador'),
			sx_input('sx-p-titulo', '', array_merge(['maxlength' => '34'], $somente_leitura))),
		sx_field('sx-p-ano', _('Ano'), sx_select('sx-p-ano', [], '')),
		sx_field('sx-p-meta', _('Meta (%)'),
			sx_input('sx-p-meta', '', array_merge(['type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '100'], $somente_leitura))),
		sx_field('sx-p-des', _('Desafio (%)'),
			sx_input('sx-p-des', '', array_merge(['type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '100'], $somente_leitura))),
		sx_field('sx-p-metodo', _('Cálculo'), sx_select('sx-p-metodo', [
			'ponderada' => _('Ponderada por tempo de indisponibilidade'),
			'media' => _('Média simples entre equipamentos')
		], 'ponderada', !$is_admin)),
		sx_field('sx-p-rank', _('Ranking por'), sx_select('sx-p-rank', [
			'grupo' => _('Grupo de hosts'),
			'equip' => _('Equipamento'),
			'cat' => _('Categoria')
		], 'grupo', !$is_admin)),
		sx_field('sx-p-dec', _('Casas decimais'), sx_select('sx-p-dec', ['2' => '2', '3' => '3', '4' => '4'], '2', !$is_admin))
	], ['class' => 'sx-params'])
];

if ($is_admin) {
	$params[] = sx_tag('div', sx_tag('button', _('Salvar parâmetros'),
		['type' => 'button', 'id' => 'sx-btn-save', 'class' => 'sx-btn sx-primary-button']),
		['style' => 'margin-top:12px']);
}

$params[] = sx_tag('p', '', ['class' => 'sx-note', 'id' => 'sx-params-note']);

// ── Classificação ───────────────────────────────────────────────────────────
$mapping = [];

if ($is_admin) {
	$mapping[] = sx_tag('div', [
		sx_field('sx-newcat', _('Nova categoria'), sx_input('sx-newcat', '', ['placeholder' => _('Ex.: Data Center')])),
		sx_field('sx-newsig', _('Sigla'), sx_input('sx-newsig', '', ['placeholder' => 'DC', 'maxlength' => '4'])),
		sx_tag('button', _('Adicionar categoria'), ['type' => 'button', 'id' => 'sx-btn-addcat', 'class' => 'sx-btn'])
	], ['class' => 'sx-params', 'style' => 'margin-bottom:12px']);
}

$mapping[] = sx_tag('div', '', ['id' => 'sx-maplist']);
$mapping[] = sx_tag('p', _('A classificação fica no banco e vale para todos. O que for apontado à mão vence a expressão da categoria.'),
	['class' => 'sx-note']);

// ── Conexão com o banco (Admin) ──────────────────────────────────────────────
// No caminho padrão isto fica vazio de propósito: o módulo já usa a mesma
// conexão do Zabbix (as variáveis DB_SERVER_HOST, POSTGRES_USER etc. que o
// container do frontend já recebe), então não há nada para configurar. Os
// campos abaixo só importam para quem quiser isolar os dados do SLA num banco
// à parte — nesse caso os quatro primeiros precisam ser preenchidos juntos.
$conexao = null;

if ($is_admin) {
	$atual = $data['db_config_atual'];

	$campos = [
		sx_field('sx-db-host', _('Host'), sx_input('sx-db-host', (string) ($atual['db_host'] ?? ''),
			['name' => 'db_host', 'placeholder' => _('em branco = usar o do Zabbix')])),
		sx_field('sx-db-port', _('Porta'), sx_input('sx-db-port', (string) ($atual['db_port'] ?? ''),
			['name' => 'db_port', 'placeholder' => '5432'])),
		sx_field('sx-db-name', _('Banco'), sx_input('sx-db-name', (string) ($atual['db_name'] ?? ''),
			['name' => 'db_name'])),
		sx_field('sx-db-user', _('Usuário'), sx_input('sx-db-user', (string) ($atual['db_user'] ?? ''),
			['name' => 'db_user'])),
		sx_field('sx-db-password', _('Senha'), sx_input('sx-db-password', '', [
			'name' => 'db_password', 'type' => 'password', 'autocomplete' => 'new-password',
			'placeholder' => ($atual['db_password'] ?? '') !== '' ? _('gravada — deixe em branco para manter') : ''
		])),
		sx_field('sx-logo-url', _('Logotipo do relatório'),
			sx_input('sx-logo-url', (string) ($atual['logo_url'] ?? ''), ['name' => 'logo_url'])),
		sx_tag('button', _('Salvar'), ['type' => 'submit', 'class' => 'sx-btn sx-primary-button'])
	];

	$formulario = sx_tag('form', [
		sx_void('input', [
			'type' => 'hidden',
			'name' => (string) $data['csrf_field'],
			'value' => (string) $data['csrf_settings']
		]),
		sx_tag('div', $campos, ['class' => 'sx-params'])
	], [
		'method' => 'post',
		'action' => 'zabbix.php?action=sla.executivo.settings.update'
	]);

	$estado = $db_ok
		? sprintf(_('Conectado · %1$s medição(ões) no banco'), (string) $data['db_medicoes'])
		: _('Sem conexão');

	$nota = $data['db_usando_ambiente']
		? sprintf(_('Usando a mesma conexão do Zabbix (%1$s / %2$s) — nenhum container novo é necessário. Preencha os campos acima só se quiser um banco à parte.'), $data['db_host'], $data['db_name'])
		: _('Usando uma conexão configurada aqui, diferente da do Zabbix. Apague os quatro primeiros campos e salve para voltar a usar a conexão automática.');

	$conexao = sx_card(_('Conexão com o banco'), [
		$formulario,
		sx_tag('p', $nota, ['class' => 'sx-note'])
	], $estado, ['class' => 'sx-card sx-noprint']);
}

// ── Relatório ───────────────────────────────────────────────────────────────
$report = sx_tag('div', [
	sx_tag('div', '', ['class' => 'sx-kpis', 'id' => 'sx-kpis']),

	sx_card(_('Quadro executivo'), [
		sx_tag('div', [
			sx_tag('div', sx_tag('table', '', ['class' => 'sx-matrix', 'id' => 'sx-matrix']),
				['class' => 'sx-tablewrap sx-matrix-col']),
			sx_tag('div', [
				sx_tag('div', _('Disponibilidade ponderada anual'), ['class' => 'sx-annual-h']),
				sx_tag('div', '—', ['class' => 'sx-annual-v', 'id' => 'sx-annual-v']),
				sx_tag('div', '—', ['class' => 'sx-annual-f', 'id' => 'sx-annual-f'])
			], ['class' => 'sx-annual'])
		], ['class' => 'sx-matrix-row']),
		sx_tag('p', '', ['class' => 'sx-note', 'id' => 'sx-matrix-note'])
	], '', ['id' => 'sx-matrix-card'], 'sx-matrix-title'),

	sx_card(_('Evolução mensal por categoria'), sx_chart('sx-ch-main', 'sx-chartbox-tall'),
		_('barras: resultado geral · linhas: categorias')),

	sx_tag('div', [
		sx_card(_('Disponibilidade ponderada mensal'), sx_chart('sx-ch-month')),
		sx_card(_('Ranking'), sx_chart('sx-ch-rank'), '', [], 'sx-rank-title')
	], ['class' => 'sx-grid2']),

	sx_tag('div', [
		sx_card(_('Concentração da indisponibilidade'), sx_chart('sx-ch-pareto'),
			_('quem responde pela maior parte do tempo parado')),
		sx_card(_('Consumo do orçamento de erro'), sx_tag('div', '', ['id' => 'sx-budget']))
	], ['class' => 'sx-grid3']),

	sx_card(_('Mapa de calor por grupo e mês'),
		sx_tag('div', sx_tag('table', '', ['class' => 'sx-heat', 'id' => 'sx-heat']), ['class' => 'sx-tablewrap'])),

	sx_card(_('Leitura dos números'), sx_tag('div', '', ['id' => 'sx-findings'])),

	sx_card(_('Detalhamento'),
		sx_tag('div', sx_tag('table', '', ['class' => 'sx-plain', 'id' => 'sx-detail']), [
			'class' => 'sx-tablewrap',
			'style' => 'max-height:420px;overflow-y:auto'
		]),
		_('linhas consideradas no cálculo'))
], ['id' => 'sx-report', 'style' => 'display:none']);

// ── Montagem ────────────────────────────────────────────────────────────────
$conteudo = [
	sx_tag('div', $print_head, ['class' => 'sx-print-head']),
	sx_tag('div', $avisos, ['class' => 'sx-noprint']),
	sx_tag('div', '', ['class' => 'sx-noprint', 'id' => 'sx-alerts']),
	sx_tag('div', $actions, ['class' => 'sx-toolbar sx-noprint']),
	$drop,
	sx_card(_('Parâmetros do indicador'), $params, '', ['class' => 'sx-card sx-noprint']),
	sx_card(_('Classificação dos grupos'), $mapping, _('define as linhas do quadro executivo'), [
		'class' => 'sx-card sx-noprint',
		'id' => 'sx-sec-map',
		'style' => 'display:none'
	])
];

if ($conexao !== null) {
	$conteudo[] = $conexao;
}

$conteudo[] = $report;
$conteudo[] = sx_tag('div', _('Consultando a API...'), ['class' => 'sx-empty sx-card', 'id' => 'sx-empty']);
$conteudo[] = sx_tag('div', '', ['class' => 'sx-toast', 'id' => 'sx-toast']);

(new CHtmlPage())
	->setTitle(_('SLA Executivo'))
	->setDocUrl('https://www.digisystem.cloud')
	->addItem(sx_tag('div', $conteudo, [
		'id' => 'sx-app',
		'class' => 'sx-app',
		'data-sx-config' => (string) $context
	]))
	->show();
