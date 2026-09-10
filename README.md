# SLA Executivo — módulo do frontend Zabbix

Painel executivo de SLA em **Tools › SLA Executivo › SLA Executivo (Mensal)**. Consolida os CSVs do
**Relatório de Disponibilidade** mês a mês: meta, desafio, categorias
(Operação, Escritórios, Agências ou as que você criar), ranking, mapa de calor,
concentração da indisponibilidade, consumo do orçamento de erro e exportação em
CSV, JSON e PDF.

**Sem containers extras.** O módulo guarda tudo em Postgres, mas usa **o mesmo
banco que o frontend do Zabbix já acessa** — não sobe `sla-db`, não sobe
`sla-api`, não tem `docker-compose` para além do que você já roda. Copie a
pasta, habilite o módulo, pronto.

## Como isso funciona sem nenhuma configuração

O container `zabbix-web-apache-pgsql` já recebe estas variáveis de ambiente,
porque são elas que ele usa para falar com o Postgres do Zabbix:

```yaml
environment:
  - DB_SERVER_HOST=${PSQL_HOST}
  - POSTGRES_USER=${PSQL_USER}
  - POSTGRES_PASSWORD=${PSQL_KEY}
  - POSTGRES_DB=${PSQL_DATABASE}
```

O módulo lê essas mesmas variáveis (`services/Db.php`) e, na primeira conexão,
cria suas próprias tabelas nesse banco — todas com o prefixo `sla_`, para não
colidir com nada do Zabbix. Não existe passo de instalação de banco: é
`CREATE TABLE IF NOT EXISTS`, rodado automaticamente.

Quem preferir isolar os dados do SLA num banco à parte (por exemplo, para não
competir com o housekeeping do Zabbix por I/O) pode: o cartão **Conexão com o
banco**, em Tools › SLA Executivo, aceita host/porta/banco/usuário/senha
próprios. Preenchidos, eles têm prioridade; em branco, o módulo volta a usar a
conexão automática.

## Estrutura

```
SlaExecutivo/
├── manifest.json                        actions e assets
├── Module.php                           entrada em Tools › SLA Executivo
├── actions/
│   ├── ReportView.php                   sla.executivo.view — visão mensal
│   ├── SemanalView.php                  sla.executivo.semanal — semanal por unidade
│   ├── Api.php                          sla.executivo.api — único endpoint
│   └── SettingsUpdate.php               sla.executivo.settings.update (Admin)
├── services/
│   ├── Db.php                           conexão e criação automática do schema
│   ├── Parser.php                       leitura dos CSVs (relatório e formato largo)
│   ├── Repository.php                   parâmetros, importação, consolidação, exportação
│   ├── Config.php                       conexão opcional e logotipo
│   └── InputCompat.php                  compatibilidade 6.4 → 8.0
├── views/
│   └── module.sla.executivo.view.php    esqueleto da página
├── assets/
│   ├── css/sla-executivo.css            tudo sob .sx-app
│   └── js/
│       ├── chart.umd.js                 Chart.js 4.4.0 (offline)
│       ├── sla-executivo.js             visão mensal
│       └── sla-semanal.js               acompanhamento semanal
├── sql/
│   ├── 001_schema.sql                   tabelas sla_* (aplicadas sozinhas, em ordem)
│   └── 002_semanal.sql                  semana, unidade e peso de negócio
├── tests/
│   ├── config.php                       php tests/config.php
│   ├── repository.php                   php tests/repository.php (precisa de Postgres)
│   └── render.js                        node tests/render.js
├── tools/gitea-workflow.yaml
├── CHANGELOG.md
└── README.md
```

## Instalação

```bash
cd /etc/digisystem/zabbix
cp -r SlaExecutivo modules/
git add modules/SlaExecutivo && git commit -m "SLA Executivo 4.0.0" && git push
docker compose restart zabbix-web-apache-pgsql
```

No frontend: **Administration › General › Modules › Scan directory**, depois
habilite "SLA Executivo". Abra Tools › SLA Executivo — se o quadro carregar
(ou o botão "Carregar dados de exemplo" funcionar), a conexão automática
pegou. Se aparecer o aviso de banco sem resposta, veja a seção de diagnóstico
abaixo.

Não há `.env` novo, não há variável nova para configurar, não há segundo
`docker compose up`.

Permissões: ver o painel exige Zabbix User; importar, mudar parâmetros,
classificar grupos e configurar uma conexão à parte exigem Admin ou Super
admin.

## Uso

1. Como Admin, gere os relatórios em Relatório de Disponibilidade, um por mês,
   e importe os CSVs pela área de carga.
2. Confira a classificação dos grupos, ajuste meta e desafio, salve em PDF.
3. Qualquer Zabbix User pode abrir a tela e ver o painel — só não importa nem
   muda parâmetro.

"Carregar dados de exemplo" preenche o banco com um conjunto de teste, sem
precisar de nenhum CSV — útil para validar a instalação. Depois é só remover
essa importação pela lista.

## Acompanhamento semanal por unidade

**Tools › SLA Executivo › SLA Executivo (Semanal)** reproduz o quadro da diretoria: um bloco
por unidade com `1 SEM … 6 SEM`, `ACUM` e `PONDERADA`, gráfico combinado por
unidade (barras = Geral, linhas = OP/ES/AG, tracejados = meta/desafio) e o
ranking das unidades pela ponderada.

Como alimentar: gere o Relatório de Disponibilidade **uma vez por semana**
(o período inicial define a semana; semanas correm de domingo a sábado) e
importe pela página mensal. O mesmo CSV serve às duas visões.

Como a unidade é descoberta: pelo código de duas letras maiúsculas no fim do
nome do grupo de hosts ("Agências SP" → SP). Grupos que não seguem esse
padrão caem em "Geral" até um Admin apontar a unidade no cartão da própria
página.

Como a PONDERADA é calculada — peso de negócio, não tempo:

```
PONDERADA(unidade) = Σ ACUM(categoria) × peso(categoria) ÷ Σ peso
```

Pesos padrão OP 40 · ES 35 · AG 25 (editáveis). A coluna PONDERADA de cada
categoria mostra a contribuição (ACUM × peso); a da linha Geral, a soma.
Unidade sem alguma categoria tem a soma normalizada pelos pesos presentes, e a
leitura executiva avisa.

Estrutura: `actions/SemanalView.php`, `views/module.sla.executivo.semanal.view.php`,
`assets/js/sla-semanal.js`, migração `sql/002_semanal.sql`.

## Como o número é calculado

```
disponibilidade = 1 − (Σ tempo parado ÷ Σ janela de cobertura)
```

A janela de cada equipamento é deduzida de
`tempo parado ÷ ((100 − SLA)/100)` no momento da importação. Isso mantém o
resultado coerente com o SLA que o próprio relatório informou e funciona igual
em 24x7 e em expediente comercial, sem configuração adicional. Equipamentos
expurgados e sem trigger de disponibilidade ficam fora do cálculo e aparecem
contados à parte — nunca como 100%. Média simples entre equipamentos também
está disponível nos parâmetros, para comparação.

## Formatos de CSV aceitos

- **Relatório de Disponibilidade** — colunas `Grupo de hosts`,
  `Período inicial`, `Equipamento`, `Situação`, `SLA (%)` e
  `Indisponibilidade (segundos)`. O mês vem do período inicial; sem essa
  coluna, o módulo tenta ler `2026-01` no nome do arquivo.
- **Planilha em formato largo** — uma linha por unidade, uma coluna por mês
  (`JAN`…`DEZ`, `2026-01` ou `01/2026`), com coluna opcional de peso.

## Diagnóstico

Se Tools › SLA Executivo mostrar "Não foi possível conectar ao banco de
dados": confira o cartão **Conexão com o banco** no rodapé da página — ele
mostra o host e o banco que estão sendo usados. Na maioria dos casos, a causa
é uma destas:

- O container `zabbix-web-apache-pgsql` não tem as variáveis
  `DB_SERVER_HOST`/`POSTGRES_USER`/`POSTGRES_PASSWORD`/`POSTGRES_DB` (raro,
  mas acontece em instalações customizadas — confira com
  `docker exec Zabbix-Frontend-Apache env | grep -i postgres`).
- O usuário do banco não tem permissão para `CREATE TABLE` no schema
  `public` (o mesmo usuário que o Zabbix usa para instalar seu próprio schema
  já deveria ter).
- A extensão `pgsql` do PHP não está habilitada no frontend — nas imagens
  oficiais `zabbix-web-apache-pgsql` ela vem por padrão, então isso só é
  problema em frontend customizado.

## Testes

```bash
php  tests/config.php       # conexão opcional e logotipo — não precisa de banco
node tests/render.js        # renderização a partir de um consolidado simulado

# Precisa de um Postgres alcançável (pode ser o mesmo do Zabbix, ou um vazio de teste)
DB_SERVER_HOST=localhost DB_SERVER_PORT=5432 \
POSTGRES_DB=sla_teste POSTGRES_USER=sla_teste POSTGRES_PASSWORD=sla_teste \
php tests/repository.php

find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

`tests/repository.php` cria e limpa suas próprias linhas (tabelas `sla_*`);
não é destrutivo para o restante do banco do Zabbix, mas evite rodá-lo contra
produção — use um banco de teste.

## Requisitos

- Zabbix frontend 6.4, 7.0, 7.2, 7.4 ou 8.0.
- Extensão PHP `pgsql` (padrão nas imagens `zabbix-web-apache-pgsql`).
- Acesso de escrita ao diretório `modules/` do frontend.
