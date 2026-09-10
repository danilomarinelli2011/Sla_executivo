-- ════════════════════════════════════════════════════════════════════════════
-- SLA Executivo — banco próprio
--
-- Aplicado automaticamente pela API na subida (db.migrar). Cada arquivo em
-- sql/ roda uma vez e fica registrado em sla_migracao.
-- ════════════════════════════════════════════════════════════════════════════

-- ── Parâmetros do indicador ────────────────────────────────────────────────
-- Linha única: a instalação tem um conjunto de parâmetros, não um por usuário.
CREATE TABLE IF NOT EXISTS sla_config (
	id              smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
	titulo          text        NOT NULL DEFAULT 'DISPONIBILIDADE',
	meta            numeric(7,4) NOT NULL DEFAULT 99.0000 CHECK (meta >= 0 AND meta <= 100),
	desafio         numeric(7,4) NOT NULL DEFAULT 99.7000 CHECK (desafio >= 0 AND desafio <= 100),
	metodo          text        NOT NULL DEFAULT 'ponderada' CHECK (metodo IN ('ponderada', 'media')),
	ranking         text        NOT NULL DEFAULT 'grupo' CHECK (ranking IN ('grupo', 'equip', 'cat')),
	decimais        smallint    NOT NULL DEFAULT 2 CHECK (decimais BETWEEN 2 AND 4),
	logo_url        text        NOT NULL DEFAULT 'rebranding/logo_digi.png',
	atualizado_em   timestamptz NOT NULL DEFAULT now(),
	atualizado_por  text        NOT NULL DEFAULT '',
	CHECK (desafio >= meta)
);

INSERT INTO sla_config (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

-- ── Categorias do quadro executivo ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sla_categoria (
	id      text PRIMARY KEY,
	nome    text     NOT NULL,
	sigla   text     NOT NULL,
	cor     text     NOT NULL DEFAULT '#5B6879' CHECK (cor ~ '^#[0-9A-Fa-f]{6}$'),
	padrao  text     NOT NULL DEFAULT '',
	ordem   smallint NOT NULL DEFAULT 100
);

INSERT INTO sla_categoria (id, nome, sigla, cor, padrao, ordem) VALUES
	('OP', 'Operação',          'OP', '#B02020', 'oper|usina|planta|subestac|industr|fabric|produ[cç]', 10),
	('ES', 'Escritórios',       'ES', '#C7891B', 'escrit|matriz|sede|corporat|administ|backoffice',     20),
	('AG', 'Agências',          'AG', '#1E4380', 'ag[eê]nc|loja|filial|ponto de venda|pdv',             30),
	('NC', 'Não classificado',  'NC', '#5B6879', '',                                                   99)
ON CONFLICT (id) DO NOTHING;

-- ── Classificação manual de grupos ─────────────────────────────────────────
-- Vence sobre a expressão da categoria: o que o operador apontou não é
-- reavaliado por regex a cada importação.
CREATE TABLE IF NOT EXISTS sla_grupo_categoria (
	grupo         text PRIMARY KEY,
	categoria_id  text        NOT NULL REFERENCES sla_categoria(id) ON DELETE CASCADE,
	definido_em   timestamptz NOT NULL DEFAULT now(),
	definido_por  text        NOT NULL DEFAULT ''
);

-- ── Importações ────────────────────────────────────────────────────────────
-- O hash do conteúdo impede subir o mesmo arquivo duas vezes e dobrar o mês.
CREATE TABLE IF NOT EXISTS sla_importacao (
	id                  bigserial PRIMARY KEY,
	arquivo             text        NOT NULL,
	hash                text        NOT NULL UNIQUE,
	ano                 smallint,
	mes                 smallint CHECK (mes IS NULL OR mes BETWEEN 1 AND 12),
	linhas              integer     NOT NULL DEFAULT 0,
	linhas_calculaveis  integer     NOT NULL DEFAULT 0,
	bytes               integer     NOT NULL DEFAULT 0,
	importado_em        timestamptz NOT NULL DEFAULT now(),
	importado_por       text        NOT NULL DEFAULT ''
);

-- ── Medições ───────────────────────────────────────────────────────────────
-- Uma linha por equipamento por período. janela_s é a janela de cobertura
-- deduzida no momento da importação (ver api/parser.py).
CREATE TABLE IF NOT EXISTS sla_medicao (
	id                   bigserial PRIMARY KEY,
	importacao_id        bigint       NOT NULL REFERENCES sla_importacao(id) ON DELETE CASCADE,
	grupo                text         NOT NULL,
	equipamento          text         NOT NULL,
	situacao             text         NOT NULL DEFAULT '',
	ano                  smallint     NOT NULL,
	mes                  smallint     NOT NULL CHECK (mes BETWEEN 1 AND 12),
	sla                  numeric(9,6),
	meta_slo             numeric(7,4),
	indisponibilidade_s  numeric(14,2),
	janela_s             numeric(14,2),
	incidentes           integer      NOT NULL DEFAULT 0,
	peso                 numeric(9,3) NOT NULL DEFAULT 1,
	calculavel           boolean      NOT NULL DEFAULT true,
	observacao           text         NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS sla_medicao_periodo_idx    ON sla_medicao (ano, mes);
CREATE INDEX IF NOT EXISTS sla_medicao_grupo_idx      ON sla_medicao (grupo);
CREATE INDEX IF NOT EXISTS sla_medicao_importacao_idx ON sla_medicao (importacao_id);

-- ── Medição com categoria resolvida ────────────────────────────────────────
-- Ordem de decisão: classificação manual, depois a primeira expressão que
-- casar (por ordem), depois "não classificado".
CREATE OR REPLACE VIEW sla_medicao_classificada AS
SELECT
	m.*,
	COALESCE(
		gc.categoria_id,
		(
			SELECT c.id
			FROM sla_categoria c
			WHERE c.padrao <> '' AND m.grupo ~* c.padrao
			ORDER BY c.ordem
			LIMIT 1
		),
		'NC'
	) AS categoria_id
FROM sla_medicao m
LEFT JOIN sla_grupo_categoria gc ON gc.grupo = m.grupo;

-- ── Trilha de alterações dos parâmetros ────────────────────────────────────
CREATE TABLE IF NOT EXISTS sla_auditoria (
	id        bigserial PRIMARY KEY,
	momento   timestamptz NOT NULL DEFAULT now(),
	usuario   text        NOT NULL DEFAULT '',
	acao      text        NOT NULL,
	detalhe   jsonb       NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS sla_auditoria_momento_idx ON sla_auditoria (momento DESC);
