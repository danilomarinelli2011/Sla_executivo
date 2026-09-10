# Histórico de versões

## 4.1.2 — PDF em janela própria (antes saía em branco)

A 4.0.3 tentou vencer o layout do Zabbix por CSS na impressão. Funcionou para
o corte, mas na página semanal o resultado foi pior: dez páginas em retrato,
todas vazias — nem o `@page` em paisagem foi respeitado. Brigar com
`100vh`, sidebar fixa e `main` de 1200px a cada versão do frontend é uma
aposta ruim.

Mudança de abordagem: **"Salvar em PDF" abre uma janela própria** só com a
faixa de identidade e o relatório, carregando a mesma folha de estilo do
módulo, e os gráficos entram como imagem (`canvas.toDataURL`), congelados
do estado que está na tela. Sem sidebar, sem `wrapper`, sem `100vh` — o
navegador imprime um documento comum, em A4 paisagem, com quebra de página
entre os cards.

- `imprimir()` reescrita nas duas páginas (`sla-executivo.js` e
  `sla-semanal.js`). Os `src` do cabeçalho viram absolutos antes da cópia,
  porque a janela nasce em `about:blank`.
- Pop-up bloqueado é avisado na tela, com a instrução de liberar o endereço.
- Os `beforeprint`/`afterprint` da 4.0.3 saíram: não há mais redesenho de
  gráfico na impressão.
- As regras `@media print` da folha ficaram: são inofensivas na janela nova
  e ainda ajudam se alguém usar Ctrl+P na página do Zabbix.

## 4.1.1 — menu em três níveis

Tools › **SLA Executivo** › SLA Executivo (Mensal) | SLA Executivo (Semanal).
Só `Module.php` mudou: o item "SLA Executivo" passou a ter um submenu com as
duas páginas (`CMenuItem::setSubMenu(new CMenu([...]))`), o mesmo mecanismo
que o Zabbix usa em Administration › General.

## 4.1.0 — acompanhamento semanal por unidade (quadro da diretoria)

Nova página, **Tools › SLA Executivo — Semanal**, que reproduz a planilha de
acompanhamento da diretoria: um quadro por unidade com `1 SEM … 6 SEM`,
`ACUM` e `PONDERADA`, gráfico combinado por unidade e o ranking das unidades
pela disponibilidade ponderada.

### O que foi lido da planilha e virou regra

- **Semana-calendário dentro do mês**, de domingo a sábado. A semana 1 é a que
  contém o dia 1; a troca acontece todo domingo. Setembro/2026 começa numa
  terça: 1–5 é a semana 1, 6–12 a semana 2. Um mês tem de 4 a 6 semanas, e a
  página mostra só as que existem no calendário daquele mês.
- **PONDERADA é peso de negócio, não tempo.** Conferido nos números da
  planilha: Amapá = 99,67×0,40 + 100×0,35 + 100×0,25 = 39,87 + 35,00 + 25,00
  = 99,87; Goiás = 39,87 + 34,53 + 24,56 = 98,96. Pesos padrão OP 40 · ES 35 ·
  AG 25, editáveis por Admin. A coluna PONDERADA de cada categoria é a
  contribuição (ACUM × peso); a da linha Geral é a soma.
- Quando uma unidade não tem alguma categoria, a soma é **normalizada pelos
  pesos presentes** — a unidade não pode ser punida por não ter Operação,
  só por ter ficado indisponível. A leitura executiva avisa quando isso
  acontece, para a comparação ser feita com cuidado.
- **A linha Geral** é a média de todos os equipamentos da unidade (ponderada
  por tempo, como na visão mensal); é diferente da PONDERADA, e a planilha
  também as distingue (99,82 vs 99,87 no Amapá).
- **Ranking** ordena as unidades pela PONDERADA; os quadros seguem a mesma
  ordem, com a posição no título.

### De onde vem cada dado novo

Nada de coluna nova no CSV. Tudo é deduzido do que o Relatório de
Disponibilidade já exporta:

- **Semana** — do "Período inicial". Para o acompanhamento semanal, gere o
  relatório uma vez por semana (período de domingo a sábado, ou o recorte que
  a operação usar) e importe; o mesmo CSV alimenta a visão mensal.
- **Unidade** — o código de duas letras maiúsculas no fim do nome do grupo
  ("Agências SP" → SP; "Escritórios — Matriz" não casa, cai em "Geral"). O
  que for apontado à mão no cartão "Unidade de cada grupo de hosts" vence.
- **Peso de negócio** — `sla_categoria.peso_negocio`, editável na própria
  página.

### Banco

- Migração `sql/002_semanal.sql`: `periodo_inicio`, `periodo_fim` e `semana`
  em `sla_medicao`; `peso_negocio` em `sla_categoria`; `unidade` em
  `sla_grupo_categoria`; função `sla_unidade_de()`; view
  `sla_medicao_classificada` recriada com a coluna `unidade`.
- `Db::garantirSchema()` virou um runner de migrações com registro em
  `sla_migracao`. Instalações da 4.0.x são reconhecidas pela presença de
  `sla_config` e recebem só o `002`, sem tocar no que já existe.

### Exportação e PDF

- "Baixar quadro semanal (CSV)": ranking e um bloco por unidade, mesmo
  padrão (ponto e vírgula, vírgula decimal, BOM).
- Na impressão, cada quadro de unidade fica inteiro numa página
  (`break-inside: avoid`), e os gráficos são redimensionados antes.

### Testes

- `tests/repository.php` ganhou 20 verificações do semanal, com dois CSVs de
  semanas reais de setembro/2026: dedução da semana, unidade pelo nome do
  grupo, ordem das categorias, semanas vazias sem zerar, PONDERADA conferida
  contra Σ(ACUM × peso), pesos alterados, recusa de peso inválido, unidade
  manual vencendo e voltando, CSV semanal.
- O JSON real do `Repository::semanal()` foi alimentado no `sla-semanal.js`
  num DOM simulado: sete quadros, cinco semanas em julho, ranking, pesos,
  leitura executiva — sem ajuste nenhum entre as duas pontas.
- Dados de exemplo refeitos: sete unidades × três categorias, semana a
  semana, de janeiro a julho, com incidentes esporádicos para o ranking ter
  variação real.

## 4.0.3 — PDF cortado: só a primeira tela saía impressa

"Salvar em PDF" gerava um arquivo com a primeira tela e nada mais — KPIs e
quadro apareciam, os gráficos, o mapa de calor e o detalhamento não.

Causa, conferida no CSS compilado do Zabbix 7.0 (`blue-theme.css`): o
frontend prende a página num contêiner rolável do tamanho da tela —
`html, body { height: 100vh }` e `.wrapper { overflow: auto; min-width: 1200px }`.
O navegador imprime apenas o que está dentro desse viewport; o que exigia
rolagem não existe para o papel. Não é comportamento da nossa tela: qualquer
página do Zabbix impressa direto sofre o mesmo corte. O `@media print` do
módulo passa a liberar isso, só quando está imprimindo.

- `html`, `body` e `.wrapper` com `height: auto`, `overflow: visible` e
  `min-width: 0` no papel; `main` idem.
- Os contêineres internos que rolam na tela — o quadro executivo (largo) e o
  detalhamento (com `max-height` de 420px) — passam a se expandir por completo
  (`max-height: none`, `overflow: visible`, com `!important` porque o limite
  do detalhamento vem de estilo inline).
- Os cards perdem o `overflow: hidden` na impressão, para nada ser cortado
  na borda.
- `@page { size: A4 landscape; margin: 10mm }` declarado explicitamente.

### Gráficos em branco ou cortados no PDF

O Chart.js redesenha o canvas quando o contêiner muda de tamanho — e na
impressão ele muda (A4 paisagem, coluna única). Quando o redesenho cai no
meio da geração do PDF, o gráfico sai vazio. Agora:

- `beforeprint` e `afterprint` forçam `resize()` + `update()` em todos os
  gráficos.
- O botão "Salvar em PDF" redimensiona primeiro e só abre a caixa de
  impressão 150 ms depois, para o Chrome não fotografar o estado anterior.

## 4.0.2 — layout com a identidade DigiMon e correção de "Invalid Date"

### Layout

A tela abria com o visual genérico do Zabbix — cards sem hierarquia, botões
sem estados, nenhuma marca. Ajustado para o mesmo padrão visual dos outros
módulos da DigiSystem:

- Faixa de identidade sempre visível no topo (antes só aparecia no PDF
  impresso): fundo em degradê navy, logo, risco laranja embaixo — o mesmo
  `--sx-navy`/`--sx-orange` já usado no restante da folha, só que agora
  também na tela, não só no papel.
- Cards com cantos mais arredondados (8px), sombra sutil e cabeçalho com
  fundo levemente destacado, para separar visualmente do corpo.
- Botões com mais respiro, estado de foco e leve efeito ao clicar.
- Campos de formulário com altura consistente (36px) e anel de foco em vez de
  simplesmente uma borda cinza — mais fácil enxergar onde o cursor está.
- KPIs com números maiores e rótulos em versalete, como nos outros painéis.

Nada disso mexeu em `id`, estrutura de dados ou nomes de classe além dos já
existentes — é só a folha de estilo (`assets/css/sla-executivo.css`) e o
cabeçalho da view.

### Correção: "Última alteração: Invalid Date"

O rodapé dos parâmetros mostrava essa mensagem em vez da data. Causa:
`Repository::config()` convertia o timestamp do Postgres para o formato que o
`Date()` do JavaScript espera trocando o espaço por `T`
(`str_replace(' ', 'T', ...)`), mas o Postgres devolve o fuso sem os
dois-pontos (`+00`, não `+00:00`) — formato que o `Date()` do navegador
recusa silenciosamente, resultando em `Invalid Date`.

- Adicionado `Repository::iso()`, que passa o timestamp por `DateTime` e
  formata com `DateTime::ATOM`, gerando sempre `+00:00` com os dois-pontos.
- Aplicado tanto em `atualizado_em` (parâmetros) quanto em `importado_em`
  (lista de importações), que tinha o mesmo defeito.
- Novo teste em `tests/repository.php` que confere o formato por regex, para
  a regressão não voltar sem ser notada.

## 4.0.1 — corrige HTTP 500 ao abrir o painel

A tela quebrava com `TypeError` logo na entrada:

```
Config::module(): Return value must be of type ?CModule,
Modules\SlaExecutivo\Module returned
```

Causa: `services/Config.php` importava `CModule` do namespace global
(`use CModule;`), mas o Zabbix 7 devolve, em `APP::ModuleManager()->getModule()`,
uma instância de `Zabbix\Core\CModule` — a mesma classe que `Module.php` sempre
importou corretamente. O tipo declarado no retorno de `Config::module()` nunca
batia com o que o Zabbix realmente entregava, e o PHP rejeitava o retorno com
erro fatal a cada carregamento da página.

- Corrigido o `use` em `services/Config.php` para `Zabbix\Core\CModule`.
- Testado reproduzindo o cenário exato do log: `Config::module()` recebendo
  uma instância que estende `Zabbix\Core\CModule` (como o Zabbix real
  devolve) — sem a correção, `TypeError`; com ela, passa.
- Reconferida a suíte completa (`tests/repository.php`, `tests/config.php`,
  `tests/render.js`) contra um Postgres do zero: nada mais foi afetado.

## 4.0.0 — sem containers extras: usa o Postgres que o Zabbix já tem

A versão 3.0.0 resolveu a persistência, mas trocou um problema por outro: agora
era preciso manter `sla-db` e `sla-api` no ar, com seu próprio
`docker-compose.sla.yaml`, sempre que a stack do Zabbix subisse. Esta versão
elimina essa segunda stack. O módulo continua guardando tudo em Postgres, mas
fala **direto** com o banco — o mesmo que o frontend do Zabbix já usa — sem
API intermediária e sem container novo.

### O que muda

- **Zero configuração no caminho normal.** `services/Db.php` lê
  `DB_SERVER_HOST`, `DB_SERVER_PORT`, `POSTGRES_DB`, `POSTGRES_USER` e
  `POSTGRES_PASSWORD` — as mesmas variáveis que o container
  `zabbix-web-apache-pgsql` já recebe no seu `docker-compose.yaml`, porque são
  elas que ele usa para falar com o Postgres do próprio Zabbix. O módulo cria
  suas tabelas (prefixo `sla_`) nesse mesmo banco, na primeira conexão, com
  `CREATE TABLE IF NOT EXISTS`. Copiar a pasta, habilitar o módulo e pronto —
  nenhum `docker compose up` além do que você já roda.
- **`analytics/sla-api` (Python/Flask) deixou de existir.** A lógica de
  importação (`parser.py`) e consolidação (`analytics.py`) foi portada para
  PHP: `services/Parser.php` e `services/Repository.php`. O cálculo do quadro
  executivo continua majoritariamente em SQL — só a divisão final (ponderada
  por tempo ou média simples) é feita em PHP, exatamente como antes era feito
  em Python.
- **`actions/Api.php` deixou de ser um proxy HTTP.** Antes ele repassava a
  chamada para a API externa via cURL; agora despacha direto para
  `Repository`, que já está no mesmo processo PHP. O contrato com o
  JavaScript não mudou — mesmo `path`, mesmo formato de resposta — então a
  tela não precisou de reescrita, só ajustes pontuais.
- **Conexão à parte, para quem quiser isolar.** Quem preferir um banco
  separado do Zabbix (por exemplo, para não competir com o housekeeping)
  ainda pode: o cartão "Conexão com o banco", em Tools › SLA Executivo, aceita
  host/porta/banco/usuário/senha próprios. Preenchido, ele tem prioridade
  sobre o ambiente; em branco, volta a usar a conexão automática.
- **`sla.executivo.config.update` virou `sla.executivo.settings.update`**,
  porque agora grava conexão de banco, não credencial de API.

### Bugs encontrados e corrigidos durante a portagem

Portar o parser de Python para PHP expôs dois defeitos que não existiam do
lado Python:

- **Conversão de número com separador de milhar.** `numero("1.234,56")`
  devolvia `123456` em vez de `1234.56` — a troca de vírgula por ponto estava
  acontecendo antes da remoção do separador de milhar, então o ponto decimal
  recém-criado era removido junto. Corrigido invertendo a ordem.
- **Exportação de CSV sem aspas em todo campo.** `fputcsv()` só coloca aspas
  quando o campo "precisa" (contém delimitador, aspas ou quebra de linha); o
  padrão do Relatório de Disponibilidade traz aspas em todo campo, para o
  Excel em português abrir sem perguntar nada. A escrita passou a ser feita
  linha a linha, à mão.

Os dois foram pegos pelos testes antes de qualquer entrega — não em produção.

### Testes

- `tests/repository.php` — a mesma bateria de 36+ verificações que antes
  rodava em Python (`test_api.py`), agora em PHP, contra um Postgres real:
  importação, hash de duplicado, ponderação por tempo, expurgados fora do
  cálculo, troca de método, classificação manual vencendo a expressão,
  exportação em CSV/JSON, remoção em cascata, dados de exemplo, auditoria.
- `tests/config.php` — validação da conexão de banco opcional (porta, campos
  obrigatórios juntos) e do caminho do logotipo.
- `tests/render.js` — inalterado: continua verificando a renderização a
  partir de um consolidado simulado. Também foi conferido, fora da suíte
  automatizada, que o JSON gerado pelo `Repository::consolidado()` real do
  PHP renderiza sem ajuste nenhum no `render.js` — o contrato entre as duas
  pontas não quebrou na portagem.
- `tools/gitea-workflow.yaml` — atualizado para subir um Postgres de serviço
  no runner em vez de construir a imagem Docker da API (que não existe mais).

### Migrando da 3.0.0

Se você chegou a subir a stack da 3.0.0: pare e remova `sla-db` e `sla-api`
(`docker compose -f deploy/docker-compose.sla.yaml down`), troque a pasta do
módulo por esta versão, e habilite de novo em Administration › General ›
Modules. Os dados da 3.0.0 ficaram no banco `sla` separado — se quiser
recuperá-los, seu `.env` ainda tem `SLA_DB_PASS`; ligue esse Postgres uma vez,
rode `pg_dump`, e restaure as tabelas `sla_*` no banco do Zabbix (ou aponte a
conexão opcional deste módulo para aquele Postgres antigo, em vez de migrar os
dados). Quem preferir simplesmente recomeçar, o caminho mais curto é reimportar
os CSVs.

## 3.0.0 — banco próprio em Postgres, tudo em container

O painel deixou de guardar dados no navegador. Agora existe uma stack:
`sla-db` (Postgres 16 dedicado) e `sla-api` (Python/Flask), ambos em container,
na mesma rede do Zabbix. O módulo passou a ser só a tela — quem calcula,
armazena e audita é a API.

### Por que mudar

Na 2.0.0 os dados viviam no `localStorage` do navegador de quem importava os
CSVs. Funcionava para uma pessoa, mas: fechar a aba e importar de outra máquina
recomeçava do zero, dois analistas viam classificações diferentes para o mesmo
grupo, não havia trilha de quem mudou o quê, e não dava para consultar o
histórico de fora do navegador (auditoria, outro relatório, outra ferramenta).

### Arquitetura

```
navegador → frontend Zabbix → sla-api → sla-db
            (proxy do módulo)  (token)   (Postgres 16)
```

O navegador nunca fala com a API diretamente. Toda chamada passa pelo módulo
(`actions/Api.php`), que acrescenta o token no servidor, já com a autenticação
do Zabbix aplicada e uma lista fechada de caminhos permitidos — nada de repassar
URL arbitrária para dentro da rede do compose.

### Banco (`sla-db`)

- `sla_config` — parâmetros do indicador, linha única, válidos para toda a
  instalação.
- `sla_categoria` / `sla_grupo_categoria` — categorias com expressão de
  classificação automática, e a classificação manual, que vence a expressão.
- `sla_importacao` — um registro por arquivo, com hash SHA-256 do conteúdo.
  Subir o mesmo CSV duas vezes, mesmo renomeado, é recusado antes de tocar nas
  medições.
- `sla_medicao` — uma linha por equipamento por período, com a janela de
  cobertura já deduzida na importação.
- `sla_auditoria` — quem importou, mudou parâmetro ou reclassificou um grupo,
  e quando.
- `sla_medicao_classificada` — view que resolve a categoria de cada medição:
  classificação manual primeiro, depois a expressão, por último "não
  classificado".

Banco separado do Zabbix de propósito: retenção, backup e crescimento deste
histórico não têm relação com o housekeeping do Zabbix.

### API (`sla-api`)

- Endpoints para configuração, categorias, classificação, importação,
  consolidado e exportação (`app.py`).
- O cálculo do quadro executivo é feito majoritariamente em SQL
  (`analytics.py`): agregação por mês, categoria e grupo fica no banco; só a
  divisão final (ponderada por tempo ou média simples) é feita em Python,
  porque o método pode cair para a média quando o CSV não trouxe tempo de
  indisponibilidade.
- `parser.py` — o mesmo critério de leitura da 2.0.0: relatório do módulo
  padrão e formato largo (planilha colada), com dedução de janela de cobertura
  a partir de `tempo parado ÷ ((100 − SLA)/100)`.
- Migração de schema aplicada sozinha na subida, com `pg_advisory_lock` para os
  workers do gunicorn não disputarem o mesmo `CREATE TABLE`.
- Sem `SLA_API_TOKEN` configurado, a API recusa tudo com HTTP 503 — melhor não
  subir protegida do que subir aberta na rede do compose sem ninguém notar.

### Módulo (`modules/SlaExecutivo`)

- Nova action `sla.executivo.api`, um proxy autenticado entre a tela e a API.
  Leitura para qualquer Zabbix User; escrita (importar, mudar parâmetro,
  classificar, apagar) restrita a Admin, com CSRF conferido contra o nome
  completo da action.
- `services/ApiClient.php` — cliente HTTP com cURL e fallback por stream, para
  funcionar mesmo em frontends sem a extensão cURL habilitada (só a importação
  de arquivos exige cURL).
- `services/Config.php` reduzido: agora só guarda o necessário para alcançar a
  API — endereço, token e caminho do logotipo. O token pode vir da variável de
  ambiente `SLA_API_TOKEN` do container do frontend, o que evita gravá-lo pela
  tela.
- Tela de conexão confere a saúde da API no momento em que é salva, em vez de
  deixar o erro aparecer só na próxima visita.
- JavaScript (`assets/js/sla-executivo.js`) deixou de calcular: pede o
  consolidado pronto e cuida só de renderização, gráficos e as ações de
  importar, classificar e exportar.

### Testes

- `analytics/sla-api/tests/test_api.py` — 36 verificações contra um Postgres
  real: importação, hash de duplicado, ponderação por tempo conferida contra o
  cálculo manual, expurgados fora do cálculo, troca de método, classificação
  manual vencendo a expressão, exportação em CSV/JSON, remoção em cascata,
  dados de exemplo e auditoria.
- `modules/SlaExecutivo/tests/config.php` — validação do endereço da API, do
  token e do caminho do logotipo.
- `modules/SlaExecutivo/tests/render.js` — carrega o arquivo de produção num
  DOM simulado e verifica a renderização a partir de um consolidado no mesmo
  formato que a API devolve.

### Migrando da 2.0.0

Não há dados a migrar: a 2.0.0 guardava tudo no navegador. Basta subir a stack,
trocar a pasta do módulo e reimportar os CSVs. Os parâmetros (meta, desafio,
categorias) são informados uma vez na nova tela de conexão e passam a viver no
banco.

## 2.0.0 — alinhamento ao padrão do Relatório de Disponibilidade

Reescrita do módulo para seguir a mesma engenharia do
`RelatorioDisponibilidade`, conferida contra o código-fonte do Zabbix 7.0.

### Página nativa, sem iframe

A versão 1.0.0 embutia um `dashboard.html` estático servido pelo Apache do
frontend. Funcionava, mas ficava fora do roteador do Zabbix: qualquer proxy que
bloqueasse `modules/` derrubava a tela, e o arquivo era acessível sem passar pela
verificação de permissão.

- A página passou a ser montada em `views/module.sla.executivo.view.php` com
  `CTag`, como o Relatório de Disponibilidade faz com `rd_tag()`.
- CSS e JS entraram como assets declarados no manifest.
- Toda regra de estilo vive sob `.sx-app`, sem reset global — uma folha de módulo
  com `* { margin: 0 }` quebraria o frontend inteiro.

### Compatibilidade de versão

- Adicionada a trait `Services\InputCompat`, a mesma correção da versão 1.5.3 do
  Relatório de Disponibilidade: `setInputValidationMethod()`,
  `INPUT_VALIDATION_FORM` e as regras `['object', 'fields' => ...]` só existem a
  partir do 7.4, e chamá-las em 6.4/7.0 dá HTTP 500 ao salvar.
- O pacote passa a servir 6.4, 7.0, 7.2, 7.4 e 8.0.

### Configuração da instalação, não do navegador

- Meta, desafio, método de cálculo, casas decimais, categorias e classificação
  dos grupos passaram a ficar no registro do módulo (`CModule::setConfig()`), e
  não no `localStorage` de cada pessoa.
- Nova action `sla.executivo.config.update`, restrita a Admin, com CSRF validado
  contra o nome completo da action — que é como o `CController` trata
  controladores de módulo.
- `Services\Config` recusa meta fora de 0–100, desafio menor que a meta, cor que
  não é hexadecimal, padrão de classificação que não compila e caminho de
  logotipo com `..` ou URL externa.
- Quem não é Admin continua ajustando os parâmetros na tela para simular um
  cenário; o padrão da instalação não muda.

### Relatório e exportação

- O CSV consolidado segue o padrão do Relatório de Disponibilidade: ponto e
  vírgula, vírgula decimal, BOM UTF-8 e todos os campos entre aspas, para o Excel
  em português abrir com duplo clique.
- O PDF sai em A4 paisagem com o logotipo do rebranding no cabeçalho, e a área de
  carga e os parâmetros somem na impressão.
- O logotipo só é renderizado quando o arquivo existe em disco: `<img>` quebrado
  no relatório entregue ao cliente é pior do que relatório sem logo.

### Offline

- Chart.js 4.4.0 embarcado em `assets/js/`. A versão anterior dependia de CDN, o
  que não serve para frontend em rede fechada.

### Testes

- `tests/config.php` — padrões, conversão de vírgula decimal, recusa de entrada
  inválida, saneamento de categorias e da classificação.
- `tests/calculo.js` — carrega o próprio arquivo de produção num DOM simulado e
  verifica leitura do CSV, dedução da janela de cobertura, ponderação por tempo
  contra média simples, exclusão de expurgados e sem trigger, ranking, tendência,
  formato largo e o CSV exportado.
- `tools/gitea-workflow.yaml` roda os dois e o `php -l` a cada push.

## 1.0.0 — primeira versão

- Painel executivo em arquivo único, embutido no frontend por iframe.
- Quadro mensal por categoria, KPIs, evolução, ranking, Pareto da
  indisponibilidade, orçamento de erro, mapa de calor e leitura automática dos
  números.
