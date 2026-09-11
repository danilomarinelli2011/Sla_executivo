/**
 * Verificações da renderização.
 *
 *     node tests/render.js
 *
 * Carrega o arquivo de produção num DOM simulado e entrega a ele um consolidado
 * no mesmo formato que a API devolve. Não testa cálculo — isso é do lado do
 * Python, contra o banco. Testa se a tela sabe ler o que vem de lá.
 */
'use strict';

const path = require('path');

let falhas = 0;

function verificar(condicao, mensagem) {
	if (condicao) {
		console.log('  ok   ' + mensagem);
	}
	else {
		falhas++;
		console.log('  FALHA ' + mensagem);
	}
}

// ── DOM simulado ────────────────────────────────────────────────────────────
const elementos = {};

function elemento(id) {
	if (!elementos[id]) {
		elementos[id] = {
			id: id,
			innerHTML: '',
			textContent: '',
			value: '',
			style: {},
			dataset: {},
			classList: {add() {}, remove() {}, contains() { return false; }},
			addEventListener() {},
			insertAdjacentHTML(_posicao, html) { this.innerHTML += html; },
			querySelectorAll() { return []; },
			appendChild() {},
			setAttribute() {},
			getAttribute() { return null; },
			remove() {}
		};
		elementos[id].parentElement = elementos[id];
	}

	return elementos[id];
}

global.document = {
	getElementById: (id) => (id === 'sx-app' ? null : elemento(id)),
	addEventListener: () => {},
	querySelectorAll: () => [],
	createElement: () => elemento('criado'),
	readyState: 'complete',
	body: elemento('body')
};
global.window = global;
global.getComputedStyle = () => ({fontFamily: 'sans-serif'});
global.setTimeout = () => 0;
global.clearTimeout = () => {};
global.fetch = () => Promise.resolve({ok: true, json: () => Promise.resolve({ok: true})});

const painel = require(path.join(__dirname, '..', 'assets', 'js', 'sla-executivo.js'));

// ── Consolidado no formato da API ───────────────────────────────────────────
const consolidado = {
	ano: 2026,
	anos: [2026],
	config: {
		titulo: 'ENERGIA', meta: 99.0, desafio: 99.7, metodo: 'ponderada',
		ranking: 'grupo', decimais: 2, logo_url: '', atualizado_em: '2026-02-01T10:00:00',
		atualizado_por: 'admin'
	},
	meses: ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'],
	cats: [
		{id: 'AG', nome: 'Agências', sigla: 'AG', cor: '#1E4380'},
		{id: 'ES', nome: 'Escritórios', sigla: 'ES', cor: '#C7891B'}
	],
	categorias: [
		{id: 'AG', nome: 'Agências', sigla: 'AG', cor: '#1E4380', padrao: 'ag'},
		{id: 'ES', nome: 'Escritórios', sigla: 'ES', cor: '#C7891B', padrao: 'escrit'},
		{id: 'NC', nome: 'Não classificado', sigla: 'NC', cor: '#5B6879', padrao: ''}
	],
	porMesCat: {
		AG: [99.88, 99.31, null, null, null, null, null, null, null, null, null, null],
		ES: [99.88, 99.88, null, null, null, null, null, null, null, null, null, null]
	},
	geral: [99.88, 99.48, null, null, null, null, null, null, null, null, null, null],
	acumCat: {AG: 99.59, ES: 99.88},
	acumGeral: 99.68,
	rank: [
		{nome: 'Escritórios', sla: 99.88, down: 6360, inc: 4, meses: 2},
		{nome: 'Agências SP', sla: 99.59, down: 12720, inc: 8, meses: 2}
	],
	heat: [
		{grupo: 'Agências SP', cat: 'AG', valores: [99.88, 99.31, null, null, null, null, null, null, null, null, null, null], acum: 99.59},
		{grupo: 'Escritórios', cat: 'ES', valores: [99.88, 99.88, null, null, null, null, null, null, null, null, null, null], acum: 99.88}
	],
	pareto: [{grupo: 'Agências SP', down: 12720}, {grupo: 'Escritórios', down: 6360}],
	budget: [
		{id: 'AG', nome: 'Agências', cor: '#1E4380', down: 12720, orcamento: 53568, pct: 23.7},
		{id: 'ES', nome: 'Escritórios', cor: '#C7891B', down: 6360, orcamento: 26784, pct: 23.7}
	],
	detalhe: [
		{mes: 0, grupo: 'Agências SP', cat: 'AG', equip: 'AG-SP-001', sla: 99.81, down: 5040, inc: 3, sit: 'Monitorado'},
		{mes: 1, grupo: 'Agências SP', cat: 'AG', equip: 'AG-SP-001', sla: 98.60, down: 37500, inc: 9, sit: 'Monitorado'}
	],
	totais: {
		linhas: 6, detalhe_exibido: 2, downTotal: 19080, janelaTotal: 8035200, incTotal: 12,
		equipamentos: 3, grupos: 2, expurgados: 2, semTrigger: 2, orcamentoConsumido: 23.7
	},
	grupos: [
		{grupo: 'Agências SP', cat: 'AG', registros: 4},
		{grupo: 'Escritórios', cat: 'ES', registros: 2}
	],
	importacoes: [
		{id: 1, arquivo: 'jan-2026.csv', ano: 2026, mes: 1, linhas: 5, calculaveis: 3,
		 importado_em: '2026-02-01T10:00:00', importado_por: 'admin'}
	]
};

painel.state.ctx = {can_write: true, csrf_field: '_csrf_token', csrf_token: 'x'};
painel.render(consolidado);

console.log('── Quadro executivo ─────────────────────────────────────');
const matriz = elemento('sx-matrix').innerHTML;
verificar(matriz.indexOf('ENERGIA — 2026') !== -1, 'o título traz indicador e ano');
verificar(matriz.indexOf('99,88') !== -1, 'os valores saem com vírgula decimal');
verificar((matriz.match(/<tr/g) || []).length === 7,
	'sete linhas: título, cabeçalho de colunas, duas categorias, geral, meta e desafio');
verificar(matriz.indexOf('sx-v-warn') !== -1 && matriz.indexOf('sx-v-ok') !== -1,
	'as cores seguem meta e desafio');
verificar(elemento('sx-annual-v').textContent === '99,68%', 'o acumulado anual aparece no quadro lateral');

console.log('\n── KPIs ─────────────────────────────────────────────────');
const kpis = elemento('sx-kpis').innerHTML;
verificar(kpis.indexOf('99,68%') !== -1, 'disponibilidade acumulada');
verificar(kpis.indexOf('24%') !== -1, 'orçamento de erro vem pronto do banco');
verificar(kpis.indexOf('3 equipamentos') !== -1, 'contagem de equipamentos');

console.log('\n── Painéis ──────────────────────────────────────────────');
verificar(elemento('sx-budget').innerHTML.indexOf('Agências') !== -1, 'orçamento por categoria');
verificar(elemento('sx-heat').innerHTML.indexOf('Escritórios') !== -1, 'mapa de calor por grupo');
verificar(elemento('sx-detail').innerHTML.indexOf('AG-SP-001') !== -1, 'detalhamento com o equipamento');
verificar(elemento('sx-detail').innerHTML.indexOf('Exibindo 2 de 6') !== -1,
	'o rodapé avisa que a tabela está cortada');
verificar(elemento('sx-filelist').innerHTML.indexOf('jan-2026.csv') !== -1, 'lista de importações');
verificar(elemento('sx-maplist').innerHTML.indexOf('Agências SP') !== -1, 'classificação dos grupos');
verificar(elemento('sx-params-note').textContent.indexOf('valem para todos') !== -1,
	'a nota diz que os parâmetros são da instalação');

console.log('\n── Leitura dos números ──────────────────────────────────');
const leitura = elemento('sx-findings').innerHTML;
verificar(leitura.indexOf('Meta cumprida') !== -1, 'acumulado entre meta e desafio é reconhecido');
verificar(leitura.indexOf('fevereiro') !== -1, 'aponta o pior mês pelo nome');
verificar(leitura.indexOf('Agências SP') !== -1, 'aponta onde está o tempo parado');
verificar(leitura.indexOf('expurgado') !== -1, 'avisa sobre as linhas fora do cálculo');

console.log('\n── Ano sem dado ─────────────────────────────────────────');
painel.render(Object.assign({}, consolidado, {
	totais: Object.assign({}, consolidado.totais, {linhas: 0})
}));
verificar(elemento('sx-empty').textContent.indexOf('Nenhuma medição para 2026') !== -1,
	'ano vazio explica o que fazer em vez de mostrar quadro zerado');

console.log('\n── Tendência ────────────────────────────────────────────');
const t = painel.tendencia([99.1, 99.2, 99.3, 99.5]);
verificar(t !== null && t.slope > 0, 'série crescente devolve inclinação positiva');
verificar(painel.tendencia([99.1, null, null]) === null, 'menos de três pontos não vira tendência');

// ════════════════════════════════════════════════════════════════════════
// Semanal: cadastro de categoria (adicionar/remover) e soma automática
// ════════════════════════════════════════════════════════════════════════
console.log('\n── Semanal · quadro de pesos ─────────────────────────────');

const painelSemanal = require(path.join(__dirname, '..', 'assets', 'js', 'sla-semanal.js'));

// D mínimo: só o suficiente para render() alcançar renderPesos() antes do
// atalho de "sem unidades" (que corta o resto da página, irrelevante aqui).
function semanalMinimo(categorias) {
	return {
		ano: 2026, mes: 9,
		config: {titulo: 'DISPONIBILIDADE', meta: 99, desafio: 99.7, decimais: 2},
		categorias: categorias,
		unidades: [],
		meses_disponiveis: [{ano: 2026, mes: 9}]
	};
}

const categoriasPadrao = [
	{id: 'OP', nome: 'Operação', sigla: 'OP', cor: '#B02020', peso: 40},
	{id: 'ES', nome: 'Escritórios', sigla: 'ES', cor: '#C7891B', peso: 35},
	{id: 'AG', nome: 'Agências', sigla: 'AG', cor: '#1E4380', peso: 25},
	{id: 'NC', nome: 'Não classificado', sigla: 'NC', cor: '#5B6879', peso: 0}
];

painelSemanal.state.ctx = {can_write: true, csrf_field: '_csrf_token', csrf_token: 'x'};
painelSemanal.render(semanalMinimo(categoriasPadrao));

const pesosForm = elemento('sx-pesos-form').innerHTML;
verificar(pesosForm.indexOf('data-peso="OP"') !== -1 && pesosForm.indexOf('data-peso="AG"') !== -1,
	'um campo de peso por categoria (exceto Não classificado)');
verificar(pesosForm.indexOf('data-peso="NC"') === -1,
	'Não classificado nunca ganha campo de peso');
verificar(pesosForm.indexOf('id="sx-peso-soma"') !== -1 && pesosForm.match(/value="100,00"/) !== null,
	'a soma inicial bate com 40 + 35 + 25');
verificar(pesosForm.indexOf('data-cat-remove="OP"') !== -1,
	'cada categoria (com permissão de escrita) ganha um botão de remover');

console.log('\n── Semanal · cadastro sem permissão de escrita ───────────');
painelSemanal.state.ctx = {can_write: false, csrf_field: '_csrf_token', csrf_token: 'x'};
painelSemanal.render(semanalMinimo(categoriasPadrao));
const pesosFormLeitura = elemento('sx-pesos-form').innerHTML;
verificar(pesosFormLeitura.indexOf('data-cat-remove') === -1,
	'sem permissão de escrita, o botão de remover não aparece');
verificar(pesosFormLeitura.indexOf('disabled') !== -1,
	'sem permissão de escrita, os campos de peso ficam desabilitados');

console.log('\n── Semanal · soma reflete adicionar/remover categoria ────');
painelSemanal.state.ctx = {can_write: true, csrf_field: '_csrf_token', csrf_token: 'x'};

// Simula o efeito de adicionarCategoria(): categoria nova entra com peso 0 —
// a soma das existentes não muda, mas o campo dela aparece.
const comCategoriaNova = categoriasPadrao.concat([
	{id: 'C1A2', nome: 'Data Center', sigla: 'DC', cor: '#7A3E9D', peso: 0}
]);
painelSemanal.render(semanalMinimo(comCategoriaNova));
const pesosComNova = elemento('sx-pesos-form').innerHTML;
verificar(pesosComNova.indexOf('data-peso="C1A2"') !== -1, 'a categoria recém-cadastrada ganha seu próprio campo de peso');
verificar(pesosComNova.match(/value="100,00"/) !== null,
	'peso 0 da categoria nova não altera a soma das demais (ainda 100,00)');

// Simula o efeito de removerCategoria(): a soma cai exatamente pelo peso
// da categoria removida — é o "ajuste automático" pedido.
const semAG = categoriasPadrao.filter(c => c.id !== 'AG');
painelSemanal.render(semanalMinimo(semAG));
const pesosSemAG = elemento('sx-pesos-form').innerHTML;
verificar(pesosSemAG.indexOf('data-peso="AG"') === -1, 'a categoria removida não tem mais campo de peso');
verificar(pesosSemAG.match(/value="75,00"/) !== null,
	'a soma se ajusta sozinha para 75,00 (100 − 25) ao remover Agências, sem recarregar a página à mão');

console.log('');

if (falhas > 0) {
	console.error(falhas + ' verificação(ões) falharam.');
	process.exit(1);
}

console.log('Todas as verificações de renderização passaram.');
