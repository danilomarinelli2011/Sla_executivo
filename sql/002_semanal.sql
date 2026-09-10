-- ════════════════════════════════════════════════════════════════════════════
-- SLA Executivo — 002: acompanhamento semanal por unidade
--
-- Três informações novas, todas deduzidas do que já vem no CSV:
--   • semana      — semana-calendário dentro do mês (1..6), a partir do
--                   "Período inicial". Semanas correm de domingo a sábado.
--   • unidade     — a unidade (UF, site) do grupo de hosts. Classificação
--                   manual em sla_grupo_categoria.unidade; senão, o código de
--                   duas letras maiúsculas no fim do nome do grupo.
--   • peso_negocio — peso de cada categoria na "disponibilidade ponderada"
--                   da diretoria (OP 40 / ES 35 / AG 25).
-- ════════════════════════════════════════════════════════════════════════════

ALTER TABLE sla_medicao
	ADD COLUMN IF NOT EXISTS periodo_inicio date,
	ADD COLUMN IF NOT EXISTS periodo_fim    date,
	ADD COLUMN IF NOT EXISTS semana         smallint CHECK (semana IS NULL OR semana BETWEEN 1 AND 6);

CREATE INDEX IF NOT EXISTS sla_medicao_semana_idx ON sla_medicao (ano, mes, semana);

ALTER TABLE sla_categoria
	ADD COLUMN IF NOT EXISTS peso_negocio numeric(6,2) NOT NULL DEFAULT 0 CHECK (peso_negocio >= 0);

UPDATE sla_categoria SET peso_negocio = 40 WHERE id = 'OP' AND peso_negocio = 0;
UPDATE sla_categoria SET peso_negocio = 35 WHERE id = 'ES' AND peso_negocio = 0;
UPDATE sla_categoria SET peso_negocio = 25 WHERE id = 'AG' AND peso_negocio = 0;

ALTER TABLE sla_grupo_categoria
	ADD COLUMN IF NOT EXISTS unidade text;

-- Unidade automática: código de duas letras MAIÚSCULAS no fim do nome, precedido
-- por espaço ou separador ("Agências SP", "Operacao-GO", "Escritórios — MA").
-- "Escritórios — Matriz" não casa: "iz" é minúsculo e não vem depois de separador.
CREATE OR REPLACE FUNCTION sla_unidade_de(grupo text) RETURNS text AS $$
	SELECT COALESCE(
		(SELECT m[1] FROM regexp_matches(grupo, '(?:^|[[:space:]_/\-—–])([A-Z]{2})[[:space:]]*$') AS m LIMIT 1),
		'Geral'
	);
$$ LANGUAGE sql IMMUTABLE;

-- A view passa a resolver também a unidade. A classificação manual (quando
-- existe) vence a dedução automática, no mesmo espírito da categoria.
-- DROP + CREATE em vez de OR REPLACE: as colunas novas de sla_medicao entram
-- no meio da lista (antes de categoria_id), e OR REPLACE só aceita coluna no fim.
DROP VIEW IF EXISTS sla_medicao_classificada;

CREATE VIEW sla_medicao_classificada AS
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
	) AS categoria_id,
	COALESCE(NULLIF(gc.unidade, ''), sla_unidade_de(m.grupo)) AS unidade
FROM sla_medicao m
LEFT JOIN sla_grupo_categoria gc ON gc.grupo = m.grupo;
