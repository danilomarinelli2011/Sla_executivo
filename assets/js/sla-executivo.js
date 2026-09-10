/**
 * SLA Executivo — painel do frontend.
 *
 * Da versão 3.0.0 em diante a tela não calcula nada: ela pede o consolidado
 * pronto para a API, que lê do banco próprio em Postgres. Aqui ficam a
 * renderização, os gráficos e as ações de importar, classificar e exportar.
 *
 * Nenhuma chamada vai direto para a API: tudo passa pelo proxy do módulo
 * (zabbix.php?action=sla.executivo.api), que acrescenta o token no servidor e
 * já contou com a autenticação do Zabbix.
 */
(function(){
"use strict";

/* ══════════════════════════════════════════════════════════════
   1. Estado
   ══════════════════════════════════════════════════════════════ */
const MES = ["JAN","FEV","MAR","ABR","MAI","JUN","JUL","AGO","SET","OUT","NOV","DEZ"];
const MES_NOME = ["janeiro","fevereiro","março","abril","maio","junho","julho","agosto","setembro","outubro","novembro","dezembro"];

const state = {
  ctx:  {},                 /* contexto entregue pelo PHP */
  cfg:  {titulo:"", meta:99, desafio:99.7, metodo:"ponderada", rank:"grupo", dec:2, ano:null},
  cats: [],                 /* categorias com dado no ano */
  categorias: [],           /* todas as categorias cadastradas */
  D:    null,               /* último consolidado recebido */
  ocupado: false
};

let chMain=null, chMonth=null, chRank=null, chPareto=null;

/* ══════════════════════════════════════════════════════════════
   2. Utilitários
   ══════════════════════════════════════════════════════════════ */
const $  = id => document.getElementById("sx-" + id);
const norm = s => (s||"").toString().normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase().trim();
const esc = s => (s==null?"":String(s)).replace(/[&<>"]/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

function toast(msg){ const t=$("toast"); if(!t) return; t.textContent=msg; t.classList.add("sx-on"); clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove("sx-on"),2600); }
function fmt(v,d){ if(v==null||!isFinite(v)) return "—"; return v.toFixed(d===undefined?state.cfg.dec:d).replace(".",",")+"%"; }
function fmtNum(v,d){ if(v==null||!isFinite(v)) return "—"; return v.toLocaleString("pt-BR",{minimumFractionDigits:d||0,maximumFractionDigits:d||0}); }
function dur(seg){
  if(seg==null||!isFinite(seg)) return "—";
  seg = Math.round(seg);
  const d=Math.floor(seg/86400), h=Math.floor(seg%86400/3600), m=Math.floor(seg%3600/60), s=seg%60;
  if(d) return d+"d "+h+"h "+m+"m";
  if(h) return h+"h "+m+"m";
  if(m) return m+"m "+s+"s";
  return s+"s";
}
function download(nome, conteudo, mime){
  const blob = new Blob([conteudo], {type:mime||"text/plain;charset=utf-8"});
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob); a.download = nome;
  document.body.appendChild(a); a.click();
  setTimeout(()=>{ URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
/* classe de cor conforme meta/desafio */
function cls(v){
  if(v==null||!isFinite(v)) return "sx-v-none";
  if(v >= state.cfg.desafio) return "sx-v-ok";
  if(v >= state.cfg.meta)    return "sx-v-warn";
  return "sx-v-bad";
}
function corDe(v){
  if(v==null||!isFinite(v)) return "#C3CAD4";
  if(v >= state.cfg.desafio) return "#0F7B47";
  if(v >= state.cfg.meta)    return "#C7891B";
  return "#B02020";
}

function catDe(id){
  return (state.categorias || []).find(c => c.id === id)
      || (state.cats || []).find(c => c.id === id)
      || {id:id, nome:"—", sigla:"—", cor:"#5B6879"};
}

/* regressão linear simples sobre os meses com dados */
function tendencia(serie){
  const pts = serie.map((v,i)=>({x:i,y:v})).filter(p=>p.y!=null);
  if(pts.length < 3) return null;
  const n=pts.length, sx=pts.reduce((a,p)=>a+p.x,0), sy=pts.reduce((a,p)=>a+p.y,0);
  const sxy=pts.reduce((a,p)=>a+p.x*p.y,0), sxx=pts.reduce((a,p)=>a+p.x*p.x,0);
  const den = n*sxx - sx*sx;
  if(!den) return null;
  const a = (n*sxy - sx*sy)/den, b = (sy - a*sx)/n;
  const prox = pts[pts.length-1].x + 1;
  return {slope:a, intercepto:b, proximo: a*prox + b, ultimoMes: pts[pts.length-1].x};
}

/* ══════════════════════════════════════════════════════════════
   3. Conversa com a API (sempre pelo proxy do módulo)
   ══════════════════════════════════════════════════════════════ */
const ACAO = "zabbix.php?action=sla.executivo.api";

function lerContexto(){
  const app = document.getElementById("sx-app");
  if(!app) return {};
  try{ return JSON.parse(app.getAttribute("data-sx-config") || "{}"); }
  catch(e){ return {}; }
}

/* Toda escrita leva o token CSRF; leitura vai como GET simples. */
async function chamar(caminho, opcoes){
  opcoes = opcoes || {};
  const metodo = opcoes.metodo || "GET";
  const forma = new FormData();
  forma.append("path", caminho);
  forma.append("metodo", metodo);
  if(opcoes.ano) forma.append("ano", String(opcoes.ano));
  if(opcoes.payload) forma.append("payload", JSON.stringify(opcoes.payload));
  if(metodo !== "GET") forma.append(state.ctx.csrf_field || "_csrf_token", state.ctx.csrf_token || "");
  if(opcoes.arquivos){
    for(const arquivo of opcoes.arquivos) forma.append("arquivos[]", arquivo, arquivo.name);
  }

  try{
    const resposta = await fetch(ACAO, {method:"POST", body:forma, credentials:"same-origin"});
    if(!resposta.ok) return {ok:false, erro:"HTTP " + resposta.status + " no frontend"};
    const corpo = await resposta.json();
    if(!corpo.ok) toast(corpo.erro || "A API recusou a operação.");
    return corpo;
  }
  catch(erro){
    toast("Falha de rede ao falar com a API.");
    return {ok:false, erro:String(erro)};
  }
}

async function carregar(ano){
  if(state.ocupado) return;
  state.ocupado = true;
  ocupar(true);

  const resposta = await chamar("consolidado", {ano: ano || (state.cfg.ano || "")});

  state.ocupado = false;
  ocupar(false);

  if(!resposta.ok || !resposta.json){
    $("report").style.display = "none";
    $("empty").style.display = "block";
    $("empty").textContent = "Não foi possível ler o consolidado: " + (resposta.erro || "resposta vazia") + ".";
    return;
  }

  render(resposta.json);
}

function ocupar(ligado){
  document.querySelectorAll("#sx-app button").forEach(b => { b.disabled = ligado; });
  if($("empty") && ligado && !state.D){ $("empty").textContent = "Consultando a API..."; }
}

/* ══════════════════════════════════════════════════════════════
   4. Renderização
   ══════════════════════════════════════════════════════════════ */

function render(D){
  if(!D){ $("report").style.display="none"; $("empty").style.display="block"; return; }
  state.cfg = {
    titulo:  D.config.titulo,
    meta:    D.config.meta,
    desafio: D.config.desafio,
    metodo:  D.config.metodo,
    rank:    D.config.ranking,
    dec:     D.config.decimais,
    ano:     D.ano
  };
  state.cats = D.cats;
  state.categorias = D.categorias;

  renderImportacoes(D);
  renderMapa(D);
  renderParametros(D);

  if(!D.totais.linhas){
    $("report").style.display="none";
    $("empty").style.display="block";
    $("empty").textContent = D.importacoes.length
      ? "Nenhuma medição para " + D.ano + ". Escolha outro ano ou importe os CSVs do período."
      : "Nenhum dado importado ainda. Envie os CSVs do Relatório de Disponibilidade.";
    return;
  }

  $("empty").style.display="none"; $("report").style.display="block";
  if($("print-title")) $("print-title").textContent = state.cfg.titulo + " — " + D.ano;
  renderKPIs(D); renderMatriz(D); renderGraficos(D); renderBudget(D);
  renderHeat(D); renderFindings(D); renderDetail(D);
  state.D = D;
}

function renderImportacoes(D){
  const caixa = $("filelist");
  if(!caixa) return;
  const itens = D.importacoes || [];
  if(!itens.length){ caixa.innerHTML = ""; return; }
  caixa.innerHTML = itens.map(i => `
    <span class="sx-chip"><b>${esc(i.arquivo)}</b>
      <span style="color:var(--sx-muted)">${i.mes?MES[i.mes-1]+"/"+i.ano+" · ":""}${i.calculaveis} de ${i.linhas} linhas</span>
      ${state.ctx.can_write ? `<button class="sx-chip-x" data-imp="${i.id}" title="Remover esta importação">×</button>` : ""}
    </span>`).join("");
  caixa.querySelectorAll("[data-imp]").forEach(b => b.onclick = async () => {
    if(!confirm("Remover esta importação? As medições dela saem do quadro.")) return;
    const r = await chamar("importacoes/" + b.dataset.imp, {metodo:"DELETE"});
    if(r.ok){ toast("Importação removida."); carregar(); }
  });
}

function renderMapa(D){
  const gs = D.grupos || [];
  $("sec-map").style.display = gs.length ? "block" : "none";
  if(!gs.length) return;
  const cats = state.categorias || [];
  $("maplist").innerHTML = gs.map(g => {
    const c = catDe(g.cat);
    const opcoes = cats.map(x=>`<option value="${x.id}" ${x.id===g.cat?"selected":""}>${esc(x.nome)}</option>`).join("");
    return `<div class="sx-maprow"><span class="sx-swatch" style="background:${c.cor}"></span>
      <span class="sx-nm" title="${esc(g.grupo)}">${esc(g.grupo)}</span>
      <span class="sx-ct">${g.registros} registro${g.registros>1?"s":""}</span>
      <select data-g="${esc(g.grupo)}" ${state.ctx.can_write?"":"disabled"}>${opcoes}</select></div>`;
  }).join("");
  $("maplist").querySelectorAll("select").forEach(s => s.onchange = async () => {
    const corpo = {mapa:{}};
    corpo.mapa[s.dataset.g] = s.value;
    const r = await chamar("mapa", {metodo:"PUT", payload:corpo});
    if(r.ok){ toast("Classificação salva."); carregar(); }
  });
}

/* Parâmetros do indicador: leitura para todos, gravação para Admin. */
function renderParametros(D){
  const c = D.config;
  const campos = {"p-titulo":c.titulo, "p-meta":c.meta, "p-des":c.desafio,
                  "p-metodo":c.metodo, "p-rank":c.ranking, "p-dec":String(c.decimais)};
  for(const id in campos){ if($(id)) $(id).value = campos[id]; }
  if($("p-ano")){
    const anos = (D.anos && D.anos.length) ? D.anos : [D.ano];
    $("p-ano").innerHTML = anos.map(a=>`<option value="${a}" ${a===D.ano?"selected":""}>${a}</option>`).join("");
  }
  const atualizado = c.atualizado_em ? new Date(c.atualizado_em).toLocaleString("pt-BR",{hour12:false}) : "—";
  if($("params-note")){
    $("params-note").textContent = state.ctx.can_write
      ? "Estes valores ficam no banco e valem para todos. Última alteração: " + atualizado + (c.atualizado_por ? " por " + c.atualizado_por : "") + "."
      : "Definidos por um Admin. Última alteração: " + atualizado + ".";
  }
}

function renderKPIs(D){
  const ac = D.acumGeral;
  const gap = ac==null?null:ac - state.cfg.meta;
  const acima = D.geral.filter(v=>v!=null && v>=state.cfg.desafio).length;
  const abaixo = D.geral.filter(v=>v!=null && v<state.cfg.meta).length;
  const comDados = D.geral.filter(v=>v!=null).length;
  const T = D.totais;
  const budget = T.orcamentoConsumido;
  const k = (c,l,v,s)=>`<div class="sx-kpi ${c?"sx-"+c:""}"><div class="sx-kpi-lab">${l}</div><div class="sx-kpi-val">${v}</div><div class="sx-kpi-sub">${s}</div></div>`;
  $("kpis").innerHTML =
    k(ac==null?"":ac>=state.cfg.desafio?"ok":ac>=state.cfg.meta?"warn":"bad",
      "Disponibilidade acumulada", fmt(ac), comDados+" mês(es) apurado(s) em "+state.cfg.ano) +
    k(gap==null?"":gap>=0?"ok":"bad", "Folga sobre a meta",
      gap==null?"—":(gap>=0?"+":"")+gap.toFixed(state.cfg.dec).replace(".",",")+" p.p.",
      "meta de "+fmt(state.cfg.meta)) +
    k(abaixo?"bad":"ok", "Meses fora da meta", abaixo+"", acima+" mês(es) acima do desafio") +
    k(budget==null?"":budget>100?"bad":budget>80?"warn":"ok", "Orçamento de erro consumido",
      budget==null?"—":Math.round(budget)+"%", "tempo parado sobre o tolerado") +
    k("", "Tempo parado", dur(T.downTotal), T.equipamentos+" equipamentos · "+fmtNum(T.incTotal)+" incidentes");
}

function renderMatriz(D){
  const dec = state.cfg.dec, T = $("matrix");
  const cols = 2 + 12 + 1;
  let h = `<thead><tr><th class="sx-title" colspan="${cols}">${esc(state.cfg.titulo.toUpperCase())} — ${state.cfg.ano}</th></tr>
    <tr><th style="width:130px">Categoria</th><th style="width:52px">Sigla</th>
    ${MES.map(m=>`<th>${m}</th>`).join("")}<th class="sx-acum">ACUM</th></tr></thead><tbody>`;

  for(const c of D.cats){
    const serie = D.porMesCat[c.id];
    h += `<tr><td class="sx-rowlabel" style="background:${c.cor}">${esc(c.nome)}</td>
      <td class="sx-sigla" style="background:${c.cor}">${esc(c.sigla)}</td>`;
    h += serie.map(v=>`<td class="${cls(v)}">${v==null?"":fmt(v,dec)}</td>`).join("");
    h += `<td class="sx-acum ${cls(D.acumCat[c.id])}">${fmt(D.acumCat[c.id],dec)}</td></tr>`;
  }
  h += `<tr class="sx-geral"><td class="sx-rowlabel" style="background:#5C6B7F">Geral</td><td>—</td>`;
  h += D.geral.map(v=>`<td>${v==null?"":fmt(v,dec)}</td>`).join("");
  h += `<td class="sx-acum">${fmt(D.acumGeral,dec)}</td></tr>`;

  h += `<tr class="sx-ref"><td class="sx-rowlabel">Meta</td><td>SLO</td>` +
       MES.map(()=>`<td style="color:var(--sx-ok)">${fmt(state.cfg.meta,dec)}</td>`).join("") +
       `<td class="sx-acum" style="color:var(--sx-ok)">${fmt(state.cfg.meta,dec)}</td></tr>`;
  h += `<tr class="sx-ref"><td class="sx-rowlabel">Desafio</td><td>—</td>` +
       MES.map(()=>`<td style="color:var(--sx-navy-2)">${fmt(state.cfg.desafio,dec)}</td>`).join("") +
       `<td class="sx-acum" style="color:var(--sx-navy-2)">${fmt(state.cfg.desafio,dec)}</td></tr></tbody>`;
  T.innerHTML = h;

  $("matrix-title").innerHTML = `Quadro executivo <span>${esc(state.cfg.titulo)} · ${state.cfg.ano}</span>`;
  $("annual-v").textContent = fmt(D.acumGeral,dec);
  $("annual-v").style.color = corDe(D.acumGeral);
  $("annual-f").textContent = D.acumGeral==null ? "—" :
    (D.acumGeral>=state.cfg.desafio ? "Acima do desafio" : D.acumGeral>=state.cfg.meta ? "Dentro da meta, abaixo do desafio" : "Abaixo da meta");
  $("matrix-note").textContent = state.cfg.metodo === "ponderada"
    ? "Cálculo ponderado pelo tempo: disponibilidade = 1 − (soma do tempo parado ÷ soma das janelas de cobertura). Equipamentos expurgados e sem trigger de disponibilidade ficam fora do cálculo ("
      + D.totais.expurgados + " expurgado(s), " + D.totais.semTrigger + " sem trigger)."
    : "Média simples entre os equipamentos de cada categoria, sem ponderação por tempo de cobertura.";
}

function renderGraficos(D){
  if(typeof Chart === "undefined"){
    document.querySelectorAll(".sx-chartbox").forEach(b => b.innerHTML =
      '<div class="sx-empty">Gráficos indisponíveis: a biblioteca Chart.js não pôde ser carregada. As tabelas continuam válidas.</div>');
    return;
  }
  const dec = state.cfg.dec;
  const vals = [].concat(D.geral, ...Object.values(D.porMesCat)).filter(v=>v!=null);
  const min = Math.min(state.cfg.meta, ...vals), max = Math.max(state.cfg.desafio, ...vals, 100);
  const folga = Math.max(0.05, (max-min)*0.25);
  const yMin = Math.max(0, min - folga), yMax = Math.min(100, max + folga*0.4);
  const eixo = {min:yMin, max:yMax, grid:{color:"#E8ECF2"}, ticks:{color:"#5B6879", callback:v=>v.toFixed(dec).replace(".",",")+"%"}};
  const eixoX = {grid:{display:false}, ticks:{color:"#5B6879"}};
  const tip = {callbacks:{label:c=>` ${c.dataset.label}: ${c.parsed.y==null?"—":c.parsed.y.toFixed(dec).replace(".",",")+"%"}`}};

  /* rótulos dentro das barras */
  const rotulo = {
    id:"rotulo",
    afterDatasetsDraw(ch,a,op){
      const ds = ch.data.datasets[op.idx]; if(!ds) return;
      const meta = ch.getDatasetMeta(op.idx); const g = ch.ctx;
      g.save(); g.font = "600 10px "+getComputedStyle(document.body).fontFamily; g.textAlign="center";
      meta.data.forEach((el,i)=>{
        const v = ds.data[i]; if(v==null) return;
        g.fillStyle = op.dentro ? "#fff" : "#25313F";
        g.fillText(v.toFixed(dec).replace(".",",")+"%", el.x, op.dentro ? el.y+16 : el.y-6);
      });
      g.restore();
    }
  };

  /* 1. combo geral + categorias */
  if(chMain) chMain.destroy();
  chMain = new Chart($("ch-main"), {
    data:{
      labels: MES,
      datasets: [
        {type:"bar", label:"Geral", data:D.geral, backgroundColor:"#C3CBD6", borderRadius:2, order:5},
        ...D.cats.map(c=>({type:"line", label:c.nome, data:D.porMesCat[c.id], borderColor:c.cor,
          backgroundColor:c.cor, borderWidth:2, pointRadius:3, tension:.25, spanGaps:true, order:1})),
        {type:"line", label:"Meta", data:MES.map(()=>state.cfg.meta), borderColor:"#0F7B47",
          borderWidth:1.5, borderDash:[3,3], pointRadius:0, order:2},
        {type:"line", label:"Desafio", data:MES.map(()=>state.cfg.desafio), borderColor:"#1E4380",
          borderWidth:1.5, borderDash:[6,3], pointRadius:0, order:2}
      ]
    },
    options:{responsive:true, maintainAspectRatio:false, interaction:{mode:"index",intersect:false},
      plugins:{legend:{position:"bottom", labels:{boxWidth:12, color:"#25313F", font:{size:11}}}, tooltip:tip},
      scales:{x:eixoX, y:eixo}}
  });

  /* 2. mensal geral com rótulos */
  if(chMonth) chMonth.destroy();
  chMonth = new Chart($("ch-month"), {
    type:"bar",
    data:{labels:MES, datasets:[{label:"Ponderada mensal", data:D.geral,
      backgroundColor:D.geral.map(v=>corDe(v)), borderRadius:2}]},
    options:{responsive:true, maintainAspectRatio:false,
      plugins:{legend:{display:false}, tooltip:tip, rotulo:{idx:0, dentro:false}},
      scales:{x:eixoX, y:eixo}},
    plugins:[rotulo]
  });

  /* 3. ranking */
  const top = D.rank.slice(0, 14);
  $("rank-title").innerHTML = `Ranking <span>${state.cfg.rank==="equip"?"por equipamento":state.cfg.rank==="cat"?"por categoria":"por grupo de hosts"} · acumulado do ano</span>`;
  if(chRank) chRank.destroy();
  chRank = new Chart($("ch-rank"), {
    type:"bar",
    data:{labels: top.map(r=>r.nome.length>26?r.nome.slice(0,24)+"…":r.nome),
      datasets:[{label:"Acumulado", data:top.map(r=>r.sla), backgroundColor:top.map(r=>corDe(r.sla)), borderRadius:2}]},
    options:{indexAxis:"y", responsive:true, maintainAspectRatio:false,
      plugins:{legend:{display:false}, tooltip:tip},
      scales:{x:eixo, y:{grid:{display:false}, ticks:{color:"#25313F", font:{size:10.5}}}}}
  });

  /* 4. Pareto do tempo parado */
  const pares = (D.pareto || []).slice(0,12).map(p => [p.grupo, p.down]);
  const totalDown = (D.pareto || []).reduce((a,p)=>a+p.down,0);
  let acum = 0;
  const cum = pares.map(p => { acum += p[1]; return totalDown ? acum/totalDown*100 : 0; });
  if(chPareto) chPareto.destroy();
  if(pares.length && totalDown > 0){
    chPareto = new Chart($("ch-pareto"), {
      data:{labels: pares.map(p=>p[0].length>18?p[0].slice(0,16)+"…":p[0]),
        datasets:[
          {type:"bar", label:"Tempo parado (h)", data:pares.map(p=>p[1]/3600), backgroundColor:"#E4652A", borderRadius:2, yAxisID:"y"},
          {type:"line", label:"Acumulado (%)", data:cum, borderColor:"#132A54", borderWidth:2, pointRadius:3, yAxisID:"y2", tension:.2}
        ]},
      options:{responsive:true, maintainAspectRatio:false,
        plugins:{legend:{position:"bottom", labels:{boxWidth:12, font:{size:11}}},
          tooltip:{callbacks:{label:c=>c.dataset.yAxisID==="y2"
            ? " Acumulado: "+c.parsed.y.toFixed(1).replace(".",",")+"%"
            : " Tempo parado: "+dur(c.parsed.y*3600)}}},
        scales:{x:{grid:{display:false}, ticks:{color:"#5B6879", font:{size:10}}},
          y:{position:"left", grid:{color:"#E8ECF2"}, ticks:{color:"#5B6879"}, title:{display:true,text:"horas"}},
          y2:{position:"right", min:0, max:100, grid:{display:false}, ticks:{color:"#5B6879", callback:v=>v+"%"}}}}
    });
  } else {
    $("ch-pareto").parentElement.innerHTML = '<div class="sx-empty">Sem tempo de indisponibilidade nos arquivos carregados. O Pareto exige a coluna de indisponibilidade em segundos.</div>';
  }
}

function renderBudget(D){
  const linhas = (D.budget || []).map(b => ({nome:b.nome, cor:b.cor, d:b.down, orc:b.orcamento, pct:b.pct}));
  if(!linhas.length){
    $("budget").innerHTML = '<div class="sx-empty">Disponível quando os arquivos trazem tempo de indisponibilidade.</div>';
    return;
  }
  const T = D.totais;
  const total = {d: T.downTotal, orc: T.janelaTotal ? T.janelaTotal*(100-state.cfg.meta)/100 : null};
  const barra = p => {
    const w = Math.min(100, p||0);
    const cor = p>100?"#B02020":p>80?"#C7891B":"#0F7B47";
    return `<div style="height:6px;background:#EDF0F4;border-radius:3px;overflow:hidden;margin-top:4px">
      <div style="height:6px;width:${w}%;background:${cor}"></div></div>`;
  };
  $("budget").innerHTML = linhas.map(l => `
    <div style="padding:8px 0;border-bottom:1px solid var(--sx-line-soft)">
      <div style="display:flex;justify-content:space-between;font-size:12.5px">
        <span><span class="sx-swatch" style="background:${l.cor};margin-right:6px"></span>${esc(l.nome)}</span>
        <b style="color:${l.pct>100?"var(--sx-bad)":l.pct>80?"var(--sx-warn)":"var(--sx-ok)"}">${l.pct==null?"—":Math.round(l.pct)+"%"}</b>
      </div>${barra(l.pct)}
      <div style="font-size:11px;color:var(--sx-muted);margin-top:3px">${dur(l.d)} de ${dur(l.orc)} tolerados</div>
    </div>`).join("") +
    (total.orc ? `<div style="padding-top:10px;font-size:12px;color:var(--sx-muted)">
      Total do ano: ${dur(total.d)} parados contra ${dur(total.orc)} de tolerância na meta de ${fmt(state.cfg.meta)}.</div>` : "");
}

function renderHeat(D){
  const dec = state.cfg.dec;
  const cor = v => {
    if(v==null) return "#F4F6F9";
    if(v >= state.cfg.desafio) return "#DDF3E5";
    if(v >= state.cfg.meta)    return "#FCEFCF";
    if(v >= state.cfg.meta-1)  return "#FADFDF";
    return "#F3B9B9";
  };
  let h = `<thead><tr><th class="sx-left">Grupo de hosts</th>${MES.map(m=>`<th>${m}</th>`).join("")}<th>ACUM</th></tr></thead><tbody>`;
  for(const r of D.heat){
    const acumG = r.acum == null ? null : r.acum;
    h += `<tr><td class="sx-left" title="${esc(r.grupo)}">${esc(r.grupo)}</td>` +
      r.valores.map(v=>`<td style="background:${cor(v)}">${v==null?"":v.toFixed(dec).replace(".",",")}</td>`).join("") +
      `<td style="background:${cor(acumG)};font-weight:600">${acumG==null?"":acumG.toFixed(dec).replace(".",",")}</td></tr>`;
  }
  $("heat").innerHTML = h + "</tbody>";
}

function renderFindings(D){
  const F = [], dec = state.cfg.dec, meta = state.cfg.meta, des = state.cfg.desafio;
  const comDados = D.geral.map((v,i)=>({v,i})).filter(x=>x.v!=null);
  const add = (t,tit,txt)=>F.push({t,tit,txt});

  if(D.acumGeral != null){
    if(D.acumGeral < meta) add("crit","Ano abaixo da meta",
      `O acumulado de ${fmt(D.acumGeral)} está ${Math.abs(D.acumGeral-meta).toFixed(dec).replace(".",",")} p.p. abaixo da meta de ${fmt(meta)}. Recuperar exige reduzir o tempo parado nos meses restantes.`);
    else if(D.acumGeral < des) add("aten","Meta cumprida, desafio em aberto",
      `Acumulado de ${fmt(D.acumGeral)}: acima da meta, ${Math.abs(des-D.acumGeral).toFixed(dec).replace(".",",")} p.p. abaixo do desafio de ${fmt(des)}.`);
    else add("bom","Desafio alcançado",
      `Acumulado de ${fmt(D.acumGeral)}, acima do desafio de ${fmt(des)}.`);
  }
  if(comDados.length){
    const pior = comDados.reduce((a,b)=>b.v<a.v?b:a), melhor = comDados.reduce((a,b)=>b.v>a.v?b:a);
    add(pior.v<meta?"crit":"info","Pior e melhor mês",
      `${MES_NOME[pior.i]} foi o mês mais fraco (${fmt(pior.v)}) e ${MES_NOME[melhor.i]} o mais forte (${fmt(melhor.v)}). Amplitude de ${(melhor.v-pior.v).toFixed(dec).replace(".",",")} p.p. entre eles.`);
  }
  const t = tendencia(D.geral);
  if(t){
    const sinal = t.slope > 0.01 ? "subindo" : t.slope < -0.01 ? "caindo" : "estável";
    add(t.slope < -0.01 ? "aten" : "info", "Tendência dos meses apurados",
      `A série está ${sinal}, a ${Math.abs(t.slope).toFixed(3).replace(".",",")} p.p. por mês. Mantida a inclinação, ${MES_NOME[Math.min(11,t.ultimoMes+1)]} fecharia em torno de ${fmt(Math.min(100,t.proximo))}.`);
  }
  for(const c of D.cats){
    const a = D.acumCat[c.id];
    if(a != null && a < meta) add("crit","Categoria "+c.nome+" abaixo da meta",
      `Acumulado de ${fmt(a)} contra a meta de ${fmt(meta)}. Puxa o resultado geral para baixo.`);
  }
  const foraMeta = D.rank.filter(r => r.sla < meta);
  if(foraMeta.length) add("aten", foraMeta.length+" unidade(s) fora da meta",
    `Menores acumulados: ${foraMeta.slice(-3).reverse().map(r=>`${r.nome} (${fmt(r.sla)})`).join(", ")}.`);

  /* Pareto */
  const ord = (D.pareto || []).map(p => [p.grupo, p.down]);
  const tot = ord.reduce((a,b)=>a+b[1],0);
  if(tot > 0){
    let s=0, n=0;
    for(const [,v] of ord){ s+=v; n++; if(s/tot >= 0.8) break; }
    add("info","Onde está o tempo parado",
      `${n} de ${ord.length} grupos concentram 80% da indisponibilidade do ano (${dur(tot)} no total). Atacar ${ord.slice(0,Math.min(3,n)).map(x=>x[0]).join(", ")} é o caminho mais curto para mover o indicador.`);
  }
  if(D.totais.expurgados || D.totais.semTrigger) add("info","Linhas fora do cálculo",
    `${D.totais.expurgados} equipamento(s) expurgado(s) por manutenção e ${D.totais.semTrigger} sem trigger de disponibilidade. Nenhum deles entra como 100%, para não inflar o resultado.`);

  const ordem = {crit:0, aten:1, bom:2, info:3};
  F.sort((a,b)=>ordem[a.t]-ordem[b.t]);
  const rot = {crit:"Crítico", aten:"Atenção", bom:"Positivo", info:"Contexto"};
  $("findings").innerHTML = F.map(f=>`<div class="sx-finding"><span class="sx-tag sx-${f.t}">${rot[f.t]}</span>
    <div><h4>${esc(f.tit)}</h4><p>${f.txt}</p></div></div>`).join("");
}

function renderDetail(D){
  const dec = state.cfg.dec;
  const linhas = D.detalhe || [];
  $("detail").innerHTML = `<thead><tr><th>Mês</th><th>Grupo</th><th>Categoria</th><th>Equipamento</th>
    <th class="sx-n">SLA</th><th class="sx-n">Tempo parado</th><th class="sx-n">Incidentes</th><th>Situação</th></tr></thead><tbody>` +
    linhas.map(r=>{
      const c = catDe(r.cat);
      const p = r.sla>=state.cfg.desafio?"ok":r.sla>=state.cfg.meta?"warn":"bad";
      return `<tr><td>${MES[r.mes]}</td><td>${esc(r.grupo)}</td>
        <td><span class="sx-swatch" style="background:${c.cor};margin-right:5px"></span>${esc(c.sigla)}</td>
        <td>${esc(r.equip)}</td><td class="sx-n"><span class="sx-pill sx-${p}">${r.sla.toFixed(dec).replace(".",",")}%</span></td>
        <td class="sx-n">${dur(r.down)}</td><td class="sx-n">${fmtNum(r.inc)}</td><td>${esc(r.sit||"—")}</td></tr>`;
    }).join("") + "</tbody>";
  const total = D.totais.linhas;
  if(total > linhas.length){
    $("detail").insertAdjacentHTML("beforeend",
      `<tfoot><tr><td colspan="8" style="color:var(--sx-muted);font-size:12px;padding-top:8px">Exibindo ${linhas.length} de ${fmtNum(total)} linhas. O CSV e o JSON trazem tudo.</td></tr></tfoot>`);
  }
}

/* ══════════════════════════════════════════════════════════════
   5. Ações
   ══════════════════════════════════════════════════════════════ */
async function importar(lista){
  const arquivos = [...lista].filter(f => /\.csv$/i.test(f.name) || (f.type || "").includes("csv"));
  if(!arquivos.length) return toast("Envie arquivos .csv.");

  toast("Enviando " + arquivos.length + " arquivo(s)...");
  const resposta = await chamar("importacoes", {metodo:"POST", arquivos:arquivos});

  if(resposta.json && resposta.json.resultados){
    const erros = resposta.json.resultados.filter(r => r.status !== "ok");
    const aceitos = resposta.json.importados || 0;
    toast(aceitos + " arquivo(s) importado(s)" + (erros.length ? ", " + erros.length + " recusado(s)" : "") + ".");
    if(erros.length) mostrarRecusas(erros);
  }

  carregar();
}

/* Arquivo recusado não pode sumir em silêncio: quem importou precisa saber
   se foi duplicado, formato errado ou coluna faltando. */
function mostrarRecusas(erros){
  const caixa = $("alerts");
  if(!caixa) return;
  caixa.innerHTML = `<div class="sx-alert sx-alert-warning">
    <strong>${erros.length} arquivo(s) não entraram</strong>
    ${erros.map(e => `<span>${esc(e.arquivo)}: ${esc(e.mensagem || e.status)}</span>`).join("")}
  </div>`;
}

async function salvarParametros(){
  const corpo = {
    titulo:  $("p-titulo").value.trim(),
    meta:    $("p-meta").value,
    desafio: $("p-des").value,
    metodo:  $("p-metodo").value,
    ranking: $("p-rank").value,
    decimais: $("p-dec").value,
    logo_url: (state.ctx.logo_url || "")
  };
  const resposta = await chamar("config", {metodo:"PUT", payload:corpo});
  if(resposta.ok){ toast("Parâmetros salvos no banco."); carregar(); }
}

async function carregarDemo(){
  if(!confirm("Carregar o conjunto de exemplo no banco? Serve para conhecer o painel; depois é só remover a importação."))
    return;
  const resposta = await chamar("demo", {metodo:"POST", ano: state.cfg.ano || ""});
  if(resposta.ok){ toast("Dados de exemplo carregados."); carregar(); }
}

async function limparTudo(){
  if(!confirm("Apagar TODAS as medições e importações do banco? Os parâmetros e as categorias ficam."))
    return;
  const resposta = await chamar("dados", {metodo:"DELETE"});
  if(resposta.ok){ toast("Base de medições limpa."); state.D = null; carregar(); }
}

async function baixar(formato){
  const resposta = await chamar("export/" + formato, {ano: state.cfg.ano || ""});
  if(!resposta.ok || !resposta.texto) return;
  const tipo = formato === "csv" ? "text/csv;charset=utf-8" : "application/json;charset=utf-8";
  download("sla-executivo-" + (state.cfg.ano || "") + "." + formato, resposta.texto, tipo);
  toast("Arquivo baixado.");
}

function baixarModelo(){
  const linhas = [
    '"Grupo de hosts";"Período inicial";"Período final";"Cobertura";"Expurgo";"Equipamento";"Situação";"SLA (%)";"Meta SLO (%)";"Indisponibilidade";"Indisponibilidade (segundos)";"Incidentes";"Observação"',
    '"Agências SP";"01/01/2026";"31/01/2026";"24x7";"Sem expurgo";"AG-SP-001";"Monitorado";"99,81";"99,00";"1h 24m";"5040";"3";""',
    '"Agências SP";"01/01/2026";"31/01/2026";"24x7";"Sem expurgo";"AG-SP-002";"Monitorado";"99,95";"99,00";"22m";"1320";"1";""',
    '"Escritórios";"01/01/2026";"31/01/2026";"24x7";"Sem expurgo";"ES-MATRIZ-01";"Monitorado";"99,88";"99,00";"53m";"3180";"2";""',
    '"Operação";"01/01/2026";"31/01/2026";"24x7";"Manutenção";"OP-CORE-01";"Expurgado";"";"99,00";"";"";"0";"Janela de manutenção"'
  ];
  download("modelo-sla.csv", "\ufeff" + linhas.join("\r\n"), "text/csv;charset=utf-8");
  toast("Modelo baixado. Mesmo layout do CSV do módulo de relatório.");
}

async function adicionarCategoria(){
  const nome = $("newcat").value.trim();
  const sigla = ($("newsig").value.trim() || nome.slice(0,2)).toUpperCase();
  if(!nome) return toast("Informe o nome da categoria.");

  const cores = ["#7A3E9D","#0F7B47","#B0651B","#2E6E8E","#8C1C4B"];
  const atuais = (state.categorias || []).slice();
  const nao_classificado = atuais.filter(c => c.id === "NC");
  const demais = atuais.filter(c => c.id !== "NC");
  demais.push({id:"C" + Date.now().toString(36).slice(-4), nome:nome, sigla:sigla,
               cor:cores[demais.length % cores.length], padrao:""});

  const resposta = await chamar("categorias", {metodo:"PUT", payload:{categorias: demais.concat(nao_classificado)}});
  if(resposta.ok){
    $("newcat").value = ""; $("newsig").value = "";
    toast("Categoria criada.");
    carregar();
  }
}

/* ══════════════════════════════════════════════════════════════
   6. Interface
   ══════════════════════════════════════════════════════════════ */
function graficos(){
  return [chMain, chMonth, chRank, chPareto].filter(c => c);
}

function redimensionarGraficos(){
  graficos().forEach(c => { try{ c.resize(); c.update("none"); } catch(e){} });
}

/* Dá um instante para o redesenho dos gráficos assentar antes de abrir a
   caixa de impressão — sem isso o Chrome fotografa o estado anterior. */
function imprimir(){
  redimensionarGraficos();
  setTimeout(() => window.print(), 150);
}

function init(){
  if(document.getElementById("sx-app") === null) return;
  state.ctx = lerContexto();

  if(!state.ctx.db_ok){
    $("report").style.display = "none";
    $("empty").style.display = "block";
    return;   /* o aviso de API fora do ar já vem montado pelo PHP */
  }

  const escolher = $("btn-pick"), entrada = $("filein");
  if(escolher) escolher.onclick = () => entrada.click();
  if(entrada) entrada.onchange = e => { importar(e.target.files); e.target.value = ""; };

  const area = $("drop");
  if(area){
    ["dragenter","dragover"].forEach(ev => area.addEventListener(ev, e => { e.preventDefault(); area.classList.add("sx-hot"); }));
    ["dragleave","drop"].forEach(ev => area.addEventListener(ev, e => { e.preventDefault(); area.classList.remove("sx-hot"); }));
    area.addEventListener("drop", e => importar(e.dataTransfer.files));
  }

  if($("btn-model")) $("btn-model").onclick = baixarModelo;
  if($("btn-demo"))  $("btn-demo").onclick  = carregarDemo;
  if($("btn-clear")) $("btn-clear").onclick = limparTudo;
  if($("btn-csv"))   $("btn-csv").onclick   = () => baixar("csv");
  if($("btn-json"))  $("btn-json").onclick  = () => baixar("json");
  if($("btn-print")) $("btn-print").onclick = imprimir;

  /* O Chart.js redesenha o canvas quando o contêiner muda de tamanho — e na
     impressão o contêiner muda (A4 paisagem, coluna única). Se o redesenho
     acontece no meio da renderização do PDF, o gráfico sai em branco ou
     cortado. Redimensionar de forma explícita antes e depois resolve. */
  window.addEventListener("beforeprint", redimensionarGraficos);
  window.addEventListener("afterprint", redimensionarGraficos);
  if($("btn-save"))  $("btn-save").onclick  = salvarParametros;
  if($("btn-addcat")) $("btn-addcat").onclick = adicionarCategoria;
  if($("p-ano")) $("p-ano").onchange = () => carregar($("p-ano").value);

  carregar();
}

if(document.readyState === "loading"){
  document.addEventListener("DOMContentLoaded", init);
} else {
  init();
}

/* Superfície exposta para tests/render.js. */
if(typeof module !== "undefined" && module.exports){
  module.exports = {render, state, catDe, tendencia};
}
})();
