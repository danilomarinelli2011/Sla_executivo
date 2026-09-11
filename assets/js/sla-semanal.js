/**
 * SLA Executivo — acompanhamento semanal por unidade.
 *
 * Reproduz o quadro da diretoria: um bloco por unidade com 1..6 SEM, ACUM e
 * PONDERADA (ACUM × peso de negócio), gráfico combinado por unidade e ranking
 * das unidades pela ponderada. Os números chegam prontos do banco
 * (sla.executivo.api, path=semanal); aqui só se desenha.
 */
(function(){
"use strict";

const MES = ["JAN","FEV","MAR","ABR","MAI","JUN","JUL","AGO","SET","OUT","NOV","DEZ"];
const MES_NOME = ["janeiro","fevereiro","março","abril","maio","junho","julho","agosto","setembro","outubro","novembro","dezembro"];
const ACAO = "zabbix.php?action=sla.executivo.api";

const state = {ctx:{}, D:null, cfg:{meta:99, desafio:99.7, dec:2}, charts:{}, ocupado:false};

/* ── Utilitários ─────────────────────────────────────────────────────────── */
const $ = id => document.getElementById("sx-" + id);
const esc = s => (s==null?"":String(s)).replace(/[&<>"]/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));

function toast(msg){ const t=$("toast"); if(!t) return; t.textContent=msg; t.classList.add("sx-on"); clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove("sx-on"),2600); }
function fmt(v,d){ if(v==null||!isFinite(v)) return "—"; return v.toFixed(d===undefined?state.cfg.dec:d).replace(".",",")+"%"; }
function num(v,d){ if(v==null||!isFinite(v)) return "—"; return v.toFixed(d===undefined?state.cfg.dec:d).replace(".",","); }
function dur(seg){
  if(seg==null||!isFinite(seg)) return "—";
  seg=Math.round(seg); const d=Math.floor(seg/86400), h=Math.floor(seg%86400/3600), m=Math.floor(seg%3600/60);
  if(d) return d+"d "+h+"h "+m+"m"; if(h) return h+"h "+m+"m"; return m+"m";
}
function cls(v){ if(v==null||!isFinite(v)) return "sx-v-none"; if(v>=state.cfg.desafio) return "sx-v-ok"; if(v>=state.cfg.meta) return "sx-v-warn"; return "sx-v-bad"; }
function corDe(v){ if(v==null||!isFinite(v)) return "#C3CAD4"; if(v>=state.cfg.desafio) return "#0F7B47"; if(v>=state.cfg.meta) return "#C7891B"; return "#B02020"; }
function download(nome, conteudo, mime){
  const blob=new Blob([conteudo],{type:mime||"text/plain;charset=utf-8"}); const a=document.createElement("a");
  a.href=URL.createObjectURL(blob); a.download=nome; document.body.appendChild(a); a.click();
  setTimeout(()=>{URL.revokeObjectURL(a.href); a.remove();},500);
}

/* ── API ─────────────────────────────────────────────────────────────────── */
function lerContexto(){
  const app=document.getElementById("sx-app"); if(!app) return {};
  try{ return JSON.parse(app.getAttribute("data-sx-config")||"{}"); } catch(e){ return {}; }
}

async function chamar(caminho, opcoes){
  opcoes=opcoes||{}; const metodo=opcoes.metodo||"GET"; const forma=new FormData();
  forma.append("path",caminho); forma.append("metodo",metodo);
  if(opcoes.ano) forma.append("ano",String(opcoes.ano));
  if(opcoes.mes) forma.append("mes",String(opcoes.mes));
  if(opcoes.payload) forma.append("payload",JSON.stringify(opcoes.payload));
  if(metodo!=="GET") forma.append(state.ctx.csrf_field||"_csrf_token", state.ctx.csrf_token||"");
  try{
    const r=await fetch(ACAO,{method:"POST",body:forma,credentials:"same-origin"});
    if(!r.ok) return {ok:false, erro:"HTTP "+r.status};
    const corpo=await r.json(); if(!corpo.ok) toast(corpo.erro||"Operação recusada."); return corpo;
  }catch(e){ toast("Falha de rede."); return {ok:false, erro:String(e)}; }
}

async function carregar(ano, mes){
  if(state.ocupado) return; state.ocupado=true;
  const r=await chamar("semanal",{ano:ano||"", mes:mes||""});
  state.ocupado=false;
  if(!r.ok||!r.json){ $("report").style.display="none"; $("empty").style.display="block"; $("empty").textContent="Não foi possível ler o quadro semanal: "+(r.erro||"resposta vazia")+"."; return; }
  render(r.json);
}

/* ── Renderização ────────────────────────────────────────────────────────── */
function render(D){
  state.D=D; state.cfg={meta:D.config.meta, desafio:D.config.desafio, dec:D.config.decimais};
  renderSeletorMes(D); renderPesos(D); renderUnidadesForm(D);

  if(!D.unidades.length){
    $("report").style.display="none"; $("empty").style.display="block";
    $("empty").textContent = D.meses_disponiveis.length
      ? "Nenhuma medição semanal em "+MES_NOME[D.mes-1]+"/"+D.ano+". Escolha outro mês."
      : "Nenhuma medição com semana. Importe os CSVs do Relatório de Disponibilidade gerados por semana (período inicial e final dentro do mês).";
    return;
  }
  $("empty").style.display="none"; $("report").style.display="block";
  if($("print-title")) $("print-title").textContent = D.config.titulo+" — "+MES_NOME[D.mes-1]+" de "+D.ano;

  renderKPIs(D); renderRanking(D); renderRede(D); renderUnidades(D); renderFindings(D);
}

function renderSeletorMes(D){
  const sel=$("p-mes"); if(!sel) return;
  const lista = D.meses_disponiveis.length ? D.meses_disponiveis : [{ano:D.ano, mes:D.mes}];
  sel.innerHTML = lista.map(m=>`<option value="${m.ano}-${m.mes}" ${m.ano===D.ano&&m.mes===D.mes?"selected":""}>${MES[m.mes-1]}/${m.ano}</option>`).join("");
}

function semanasVisiveis(D){ return D.calendario.length; }   /* 4 a 6 conforme o mês */

function renderKPIs(D){
  const R=D.rede, melhor=D.ranking[0], pior=D.ranking[D.ranking.length-1];
  const acima=D.ranking.filter(r=>r.ponderada>=state.cfg.meta).length, abaixo=D.ranking.length-acima;
  const k=(c,l,v,s)=>`<div class="sx-kpi ${c?"sx-"+c:""}"><div class="sx-kpi-lab">${l}</div><div class="sx-kpi-val">${v}</div><div class="sx-kpi-sub">${s}</div></div>`;
  const cor=v=>v==null?"":v>=state.cfg.desafio?"ok":v>=state.cfg.meta?"warn":"bad";
  $("kpis").innerHTML =
    k(cor(R.acum), "Rede no mês", fmt(R.acum), R.unidades+" unidade(s) · "+R.equipamentos+" equipamentos") +
    k(cor(R.ponderada_media), "Média das ponderadas", fmt(R.ponderada_media), "meta "+fmt(state.cfg.meta)+" · desafio "+fmt(state.cfg.desafio)) +
    k(melhor?cor(melhor.ponderada):"", "Melhor unidade", melhor?esc(melhor.unidade):"—", melhor?fmt(melhor.ponderada)+" ponderada":"") +
    k(pior?cor(pior.ponderada):"", "Unidade a atacar", pior?esc(pior.unidade):"—", pior?fmt(pior.ponderada)+" ponderada":"") +
    k(abaixo?"bad":"ok", "Unidades fora da meta", abaixo+"", acima+" dentro da meta") +
    k("", "Tempo parado", dur(R.down), R.incidentes+" incidentes no mês");
}

function destruir(chave){ if(state.charts[chave]){ state.charts[chave].destroy(); delete state.charts[chave]; } }

/* Rótulo dentro/acima da barra, como na planilha. */
const rotulo = {
  id:"rotulo",
  afterDatasetsDraw(ch,a,op){
    const idx=op.idx||0, ds=ch.data.datasets[idx]; if(!ds) return;
    const meta=ch.getDatasetMeta(idx), g=ch.ctx, dec=state.cfg.dec;
    g.save(); g.font="600 10px "+getComputedStyle(document.body).fontFamily; g.textAlign="center";
    meta.data.forEach((el,i)=>{ const v=ds.data[i]; if(v==null) return;
      g.fillStyle=op.dentro?"#fff":"#25313F"; g.fillText(v.toFixed(dec).replace(".",",")+"%", el.x, op.dentro?el.y+14:el.y-5); });
    g.restore();
  }
};

function eixoY(valores){
  const v=valores.filter(x=>x!=null); const min=Math.min(state.cfg.meta,...v), max=Math.max(state.cfg.desafio,...v,100);
  const folga=Math.max(0.05,(max-min)*0.25);
  return {min:Math.max(0,min-folga), max:Math.min(100.2,max+folga*0.4), grid:{color:"#E8ECF2"},
          ticks:{color:"#5B6879", callback:x=>x.toFixed(state.cfg.dec).replace(".",",")+"%"}};
}
const tip = {callbacks:{label:c=>` ${c.dataset.label}: ${c.parsed.y==null?"—":c.parsed.y.toFixed(state.cfg.dec).replace(".",",")+"%"}`}};

function renderRanking(D){
  if(typeof Chart==="undefined") return;
  destruir("ranking");
  const R=D.ranking;
  $("ranking-title").innerHTML = `Ranking semanal de disponibilidade ponderada <span>${MES_NOME[D.mes-1]} de ${D.ano} · ${R.length} unidade(s)</span>`;
  state.charts.ranking = new Chart($("ch-ranking"), {
    type:"bar",
    data:{labels:R.map((r,i)=>[r.unidade, String(i+1)]),
      datasets:[{label:"Ponderada", data:R.map(r=>r.ponderada), backgroundColor:R.map(r=>corDe(r.ponderada)), borderRadius:3, maxBarThickness:70}]},
    options:{responsive:true, maintainAspectRatio:false,
      plugins:{legend:{display:false}, tooltip:tip, rotulo:{idx:0, dentro:true}},
      scales:{x:{grid:{display:false}, ticks:{color:"#25313F", font:{size:11, weight:"600"}}},
              y:eixoY(R.map(r=>r.ponderada))}},
    plugins:[rotulo]
  });
}

function cabecalhoSemanas(D, extra){
  const n=semanasVisiveis(D);
  return `<th style="width:120px">Categoria</th><th style="width:52px">Sigla</th>`
    + D.semanas.slice(0,n).map(s=>`<th>${s}</th>`).join("")
    + `<th class="sx-acum">ACUM</th>` + (extra||"");
}

function renderRede(D){
  const n=semanasVisiveis(D), dec=state.cfg.dec;
  let h=`<thead><tr><th class="sx-title" colspan="${n+4}">REDE — ${esc(D.config.titulo)} · ${MES[D.mes-1]}/${D.ano}</th></tr>
    <tr><th style="width:120px">Unidade</th><th style="width:52px">Posição</th>${D.semanas.slice(0,n).map(s=>`<th>${s}</th>`).join("")}<th class="sx-acum">ACUM</th><th class="sx-acum">PONDERADA</th></tr></thead><tbody>`;
  D.unidades.forEach((u,i)=>{
    h+=`<tr><td class="sx-rowlabel" style="background:var(--sx-navy-2)">${esc(u.unidade)}</td><td class="sx-sigla" style="background:var(--sx-navy-2)">${u.ponderada==null?"—":i+1}º</td>`
      + u.geral.semanas.slice(0,n).map(v=>`<td class="${cls(v)}">${v==null?"":fmt(v,dec)}</td>`).join("")
      + `<td class="sx-acum ${cls(u.geral.acum)}">${fmt(u.geral.acum,dec)}</td><td class="sx-acum ${cls(u.ponderada)}"><b>${fmt(u.ponderada,dec)}</b></td></tr>`;
  });
  h+=`<tr class="sx-geral"><td class="sx-rowlabel" style="background:#5C6B7F">Rede</td><td>—</td>`
    + D.rede.semanas.slice(0,n).map(v=>`<td>${v==null?"":fmt(v,dec)}</td>`).join("")
    + `<td class="sx-acum">${fmt(D.rede.acum,dec)}</td><td class="sx-acum">${fmt(D.rede.ponderada_media,dec)}</td></tr>`;
  h+=`<tr class="sx-ref"><td class="sx-rowlabel">Meta</td><td>SLO</td>${Array(n).fill(`<td style="color:var(--sx-ok)">${fmt(state.cfg.meta,dec)}</td>`).join("")}<td class="sx-acum" style="color:var(--sx-ok)">${fmt(state.cfg.meta,dec)}</td><td class="sx-acum"></td></tr>`;
  h+=`<tr class="sx-ref"><td class="sx-rowlabel">Desafio</td><td>—</td>${Array(n).fill(`<td style="color:var(--sx-navy-2)">${fmt(state.cfg.desafio,dec)}</td>`).join("")}<td class="sx-acum" style="color:var(--sx-navy-2)">${fmt(state.cfg.desafio,dec)}</td><td class="sx-acum"></td></tr></tbody>`;
  $("rede-tabela").innerHTML=h;

  if(typeof Chart==="undefined") return;
  destruir("rede");
  const labels=D.semanas.slice(0,n).concat(["ACUM"]);
  const paleta=["#B02020","#C7891B","#1E4380","#7A3E9D","#0F7B47","#2E6E8E","#8C1C4B","#B0651B","#4B5563","#0E7490"];
  state.charts.rede = new Chart($("ch-rede"), {
    data:{labels, datasets:[
      {type:"bar", label:"Rede", data:D.rede.semanas.slice(0,n).concat([D.rede.acum]), backgroundColor:"#C3CBD6", borderRadius:2, order:5},
      ...D.unidades.map((u,i)=>({type:"line", label:u.unidade, data:u.geral.semanas.slice(0,n).concat([u.geral.acum]),
        borderColor:paleta[i%paleta.length], backgroundColor:paleta[i%paleta.length], borderWidth:1.5, pointRadius:2.5, tension:.2, spanGaps:true, order:1})),
      {type:"line", label:"Meta", data:Array(n+1).fill(state.cfg.meta), borderColor:"#0F7B47", borderWidth:1.5, borderDash:[3,3], pointRadius:0, order:2},
      {type:"line", label:"Desafio", data:Array(n+1).fill(state.cfg.desafio), borderColor:"#1E4380", borderWidth:1.5, borderDash:[6,3], pointRadius:0, order:2}
    ]},
    options:{responsive:true, maintainAspectRatio:false, interaction:{mode:"index",intersect:false},
      plugins:{legend:{position:"bottom", labels:{boxWidth:12, color:"#25313F", font:{size:11}}}, tooltip:tip},
      scales:{x:{grid:{display:false}, ticks:{color:"#5B6879"}}, y:eixoY(D.rede.semanas.concat(D.unidades.flatMap(u=>u.geral.semanas)))}}
  });
}

function renderUnidades(D){
  const n=semanasVisiveis(D), dec=state.cfg.dec;
  const caixa=$("unidades");
  caixa.innerHTML = D.unidades.map((u,i)=>`
    <section class="sx-card sx-unidade">
      <h2 class="sx-card-title">${esc(u.unidade)} <span>${u.ponderada==null?"sem ponderada":(i+1)+"º no ranking · ponderada "+fmt(u.ponderada,dec)} · ${u.equipamentos} equipamentos</span></h2>
      <div class="sx-card-body">
        <div class="sx-tablewrap"><table class="sx-matrix sx-matrix-semanal">${tabelaUnidade(D,u,n,dec)}</table></div>
        <div class="sx-chartbox" style="margin-top:14px"><canvas id="sx-ch-u-${i}"></canvas></div>
      </div>
    </section>`).join("");
  if(typeof Chart==="undefined") return;
  D.unidades.forEach((u,i)=>graficoUnidade(D,u,i,n));
}

function tabelaUnidade(D,u,n,dec){
  let h=`<thead><tr><th class="sx-title" colspan="${n+5}">${esc(u.unidade).toUpperCase()}</th></tr>
    <tr>${cabecalhoSemanas(D, '<th class="sx-acum">PONDERADA</th>')}</tr></thead><tbody>`;
  for(const c of u.cats){
    h+=`<tr><td class="sx-rowlabel" style="background:${c.cor}">${esc(c.nome)}</td><td class="sx-sigla" style="background:${c.cor}">${esc(c.sigla)}</td>`
      + c.semanas.slice(0,n).map(v=>`<td class="${cls(v)}">${v==null?"":fmt(v,dec)}</td>`).join("")
      + `<td class="sx-acum ${cls(c.acum)}">${fmt(c.acum,dec)}</td>`
      + `<td class="sx-acum" title="ACUM × ${num(c.peso,0)}%">${c.contribuicao==null?"—":fmt(c.contribuicao,dec)}</td></tr>`;
  }
  h+=`<tr class="sx-geral"><td class="sx-rowlabel" style="background:#5C6B7F">Geral</td><td>—</td>`
    + u.geral.semanas.slice(0,n).map(v=>`<td>${v==null?"":fmt(v,dec)}</td>`).join("")
    + `<td class="sx-acum">${fmt(u.geral.acum,dec)}</td><td class="sx-acum"><b>${fmt(u.ponderada,dec)}</b></td></tr>`;
  h+=`<tr class="sx-ref"><td class="sx-rowlabel">Meta</td><td>SLO</td>${Array(n).fill(`<td style="color:var(--sx-ok)">${fmt(state.cfg.meta,dec)}</td>`).join("")}<td class="sx-acum" style="color:var(--sx-ok)">${fmt(state.cfg.meta,dec)}</td><td class="sx-acum"></td></tr>`;
  h+=`<tr class="sx-ref"><td class="sx-rowlabel">Desafio</td><td>—</td>${Array(n).fill(`<td style="color:var(--sx-navy-2)">${fmt(state.cfg.desafio,dec)}</td>`).join("")}<td class="sx-acum" style="color:var(--sx-navy-2)">${fmt(state.cfg.desafio,dec)}</td><td class="sx-acum"></td></tr></tbody>`;
  return h;
}

function graficoUnidade(D,u,i,n){
  const chave="u"+i; destruir(chave);
  const labels=D.semanas.slice(0,n).concat(["ACUM"]);
  const geral=u.geral.semanas.slice(0,n).concat([u.geral.acum]);
  state.charts[chave]=new Chart(document.getElementById("sx-ch-u-"+i), {
    data:{labels, datasets:[
      {type:"bar", label:"Geral", data:geral, backgroundColor:"#4F7BD9", borderRadius:2, order:5, maxBarThickness:60},
      ...u.cats.map(c=>({type:"line", label:c.sigla, data:c.semanas.slice(0,n).concat([c.acum]), borderColor:c.cor, backgroundColor:c.cor, borderWidth:2, pointRadius:3, tension:.2, spanGaps:true, order:1})),
      {type:"line", label:"Meta", data:Array(n+1).fill(state.cfg.meta), borderColor:"#0F7B47", borderWidth:1.5, borderDash:[3,3], pointRadius:0, order:2},
      {type:"line", label:"Desafio", data:Array(n+1).fill(state.cfg.desafio), borderColor:"#1E4380", borderWidth:1.5, borderDash:[6,3], pointRadius:0, order:2}
    ]},
    options:{responsive:true, maintainAspectRatio:false, interaction:{mode:"index",intersect:false},
      plugins:{legend:{position:"bottom", labels:{boxWidth:12, color:"#25313F", font:{size:11}}}, tooltip:tip, rotulo:{idx:0, dentro:true}},
      scales:{x:{grid:{display:false}, ticks:{color:"#5B6879"}}, y:eixoY(geral.concat(u.cats.flatMap(c=>c.semanas)))}},
    plugins:[rotulo]
  });
}

function renderPesos(D){
  const caixa=$("pesos-form"); if(!caixa) return;
  caixa.innerHTML = D.categorias.filter(c=>c.id!=="NC").map(c=>`
    <div class="sx-field sx-peso-row"><label for="sx-peso-${c.id}"><span class="sx-swatch" style="background:${c.cor};margin-right:5px"></span>${esc(c.nome)} (${esc(c.sigla)})</label>
      <div class="sx-peso-row-controls">
        <input id="sx-peso-${c.id}" data-peso="${c.id}" type="number" step="0.01" min="0" max="100" value="${num(c.peso,2).replace(",",".")}" ${state.ctx.can_write?"":"disabled"} style="min-width:90px">
        ${state.ctx.can_write?`<button type="button" class="sx-btn sx-cat-remove" data-cat-remove="${c.id}" title="Remover categoria" aria-label="Remover ${esc(c.nome)}">&times;</button>`:""}
      </div></div>`).join("")
    + `<div class="sx-field"><label>Soma</label><input id="sx-peso-soma" disabled style="min-width:80px" value="${num(D.categorias.reduce((a,c)=>a+(c.id!=="NC"?c.peso:0),0),2)}"></div>`;
  caixa.querySelectorAll("[data-peso]").forEach(i=>i.oninput=()=>{
    let soma=0; caixa.querySelectorAll("[data-peso]").forEach(x=>soma+=parseFloat(x.value)||0);
    $("peso-soma").value=num(soma,2);
  });
  caixa.querySelectorAll("[data-cat-remove]").forEach(b=>b.onclick=()=>removerCategoria(b.dataset.catRemove));
}

async function salvarPesos(){
  const pesos={}; document.querySelectorAll("[data-peso]").forEach(i=>pesos[i.dataset.peso]=i.value);
  const r=await chamar("pesos",{metodo:"PUT", payload:{pesos}});
  if(r.ok){ toast("Pesos salvos."); carregar(state.D.ano, state.D.mes); }
}

const CORES_NOVA_CATEGORIA=["#7A3E9D","#0F7B47","#B0651B","#2E6E8E","#8C1C4B"];

/**
 * Cadastra uma categoria nova sem sair da página semanal. O peso começa em
 * zero — a mesma regra do backend para toda categoria recém-criada — e o
 * quadro de pesos, recarregado logo em seguida, já mostra a linha nova com a
 * soma recalculada automaticamente (a mesma lógica de renderPesos()).
 */
async function adicionarCategoria(){
  const campoNome=$("newcat"), campoSigla=$("newsig");
  if(!campoNome) return;
  const nome=campoNome.value.trim();
  if(!nome) return toast("Informe o nome da categoria.");

  const atuais=(state.D&&state.D.categorias||[]).slice();
  if(atuais.filter(c=>c.id!=="NC").length>=11) return toast("Limite de 12 categorias atingido.");

  const sigla=((campoSigla&&campoSigla.value.trim())||nome.slice(0,2)).toUpperCase();
  const naoClassificado=atuais.filter(c=>c.id==="NC");
  const demais=atuais.filter(c=>c.id!=="NC");
  demais.push({id:"C"+Date.now().toString(36).slice(-4), nome:nome, sigla:sigla,
               cor:CORES_NOVA_CATEGORIA[demais.length%CORES_NOVA_CATEGORIA.length], padrao:""});

  const r=await chamar("categorias",{metodo:"PUT", payload:{categorias: demais.concat(naoClassificado)}});
  if(r.ok){
    campoNome.value=""; if(campoSigla) campoSigla.value="";
    toast("Categoria criada.");
    carregar(state.D&&state.D.ano, state.D&&state.D.mes);
  }
}

/**
 * Remove uma categoria. O backend faz DELETE FROM sla_categoria WHERE id NOT
 * IN (...) ao salvar a lista sem ela — o que arrasta junto (ON DELETE
 * CASCADE) qualquer classificação manual de grupo que apontava para essa
 * categoria. O aviso existe para essa consequência nunca ser surpresa.
 */
async function removerCategoria(id){
  const atuais=(state.D&&state.D.categorias||[]);
  const alvo=atuais.find(c=>c.id===id);
  if(!alvo||id==="NC") return;

  if(!confirm(`Remover "${alvo.nome}"? Grupos classificados manualmente nela voltam a cair pela expressão da categoria, ou em "Não classificado".`)) return;

  const restantes=atuais.filter(c=>c.id!==id);
  const r=await chamar("categorias",{metodo:"PUT", payload:{categorias: restantes}});
  if(r.ok){
    toast("Categoria removida.");
    carregar(state.D&&state.D.ano, state.D&&state.D.mes);
  }
}

function renderUnidadesForm(D){
  const sec=$("sec-unidades"), lista=$("unidades-list"); if(!sec||!lista) return;
  const gs=D.grupos||[]; sec.style.display=gs.length?"block":"none"; if(!gs.length) return;
  lista.innerHTML = gs.map(g=>`<div class="sx-maprow">
      <span class="sx-nm" title="${esc(g.grupo)}">${esc(g.grupo)}</span>
      <span class="sx-ct">${esc(g.cat)} · ${g.registros} registro${g.registros>1?"s":""}</span>
      <input data-unidade="${esc(g.grupo)}" value="${g.manual?esc(g.unidade):""}" placeholder="auto: ${esc(g.unidade)}" ${state.ctx.can_write?"":"disabled"}
        style="border:1px solid var(--sx-line);border-radius:6px;padding:5px 8px;min-width:120px"></div>`).join("");
  lista.querySelectorAll("[data-unidade]").forEach(i=>i.onchange=async()=>{
    const corpo={unidades:{}}; corpo.unidades[i.dataset.unidade]=i.value;
    const r=await chamar("unidades",{metodo:"PUT", payload:corpo});
    if(r.ok){ toast("Unidade salva."); carregar(state.D.ano, state.D.mes); }
  });
}

/* Leitura executiva: o que a diretoria pergunta ao olhar o quadro. */
function renderFindings(D){
  const F=[], meta=state.cfg.meta, des=state.cfg.desafio, dec=state.cfg.dec, R=D.rede, n=semanasVisiveis(D);
  const add=(t,tit,txt)=>F.push({t,tit,txt});
  const pp=v=>Math.abs(v).toFixed(dec).replace(".",",")+" p.p.";

  if(R.acum!=null){
    if(R.acum<meta) add("crit","Rede abaixo da meta no mês",`A rede fechou ${MES_NOME[D.mes-1]} em ${fmt(R.acum)}, ${pp(R.acum-meta)} abaixo da meta de ${fmt(meta)}.`);
    else if(R.acum<des) add("aten","Rede dentro da meta, abaixo do desafio",`Rede em ${fmt(R.acum)}: acima da meta, faltam ${pp(des-R.acum)} para o desafio de ${fmt(des)}.`);
    else add("bom","Rede acima do desafio",`Rede em ${fmt(R.acum)} em ${MES_NOME[D.mes-1]}, acima do desafio de ${fmt(des)}.`);
  }

  const fora=D.ranking.filter(r=>r.ponderada<meta);
  if(fora.length) add("crit",fora.length+" unidade(s) fora da meta na ponderada",
    fora.map(r=>`${r.unidade} (${fmt(r.ponderada)}, ${pp(r.ponderada-meta)} abaixo)`).join(", ")+".");
  const entre=D.ranking.filter(r=>r.ponderada>=meta&&r.ponderada<des);
  if(entre.length) add("aten",entre.length+" unidade(s) entre meta e desafio", entre.map(r=>`${r.unidade} (${fmt(r.ponderada)})`).join(", ")+".");

  if(D.ranking.length>=2){
    const m=D.ranking[0], p=D.ranking[D.ranking.length-1];
    add("info","Amplitude entre unidades",`${m.unidade} lidera com ${fmt(m.ponderada)} e ${p.unidade} fecha com ${fmt(p.ponderada)}: ${pp(m.ponderada-p.ponderada)} de distância.`);
  }

  /* pior semana da rede */
  const semanas=R.semanas.slice(0,n).map((v,i)=>({v,i})).filter(x=>x.v!=null);
  if(semanas.length>=2){
    const pior=semanas.reduce((a,b)=>b.v<a.v?b:a), melhor=semanas.reduce((a,b)=>b.v>a.v?b:a);
    const cal=D.calendario[pior.i];
    add(pior.v<meta?"crit":"info","Semana mais fraca da rede",
      `${D.semanas[pior.i]} (${cal?cal.inicio.slice(8)+"/"+cal.inicio.slice(5,7)+" a "+cal.fim.slice(8)+"/"+cal.fim.slice(5,7):""}) ficou em ${fmt(pior.v)}; a melhor foi ${D.semanas[melhor.i]} com ${fmt(melhor.v)}.`);
  }

  /* categoria que mais derruba: menor ACUM entre as categorias das unidades fora da meta */
  const culpadas={};
  D.unidades.forEach(u=>{ if(u.ponderada!=null&&u.ponderada<meta){ const c=u.cats.filter(c=>c.acum!=null).sort((a,b)=>a.acum-b.acum)[0]; if(c) culpadas[c.nome]=(culpadas[c.nome]||0)+1; } });
  const lista=Object.entries(culpadas).sort((a,b)=>b[1]-a[1]);
  if(lista.length) add("aten","Onde a ponderada está sendo perdida",
    lista.map(([nome,qtd])=>`${nome} é a categoria mais fraca em ${qtd} unidade(s) fora da meta`).join("; ")+".");

  /* dependência de peso: unidade sem alguma categoria com peso */
  const incompletas=D.unidades.filter(u=>u.pesos_presentes>0 && u.pesos_presentes<D.categorias.reduce((a,c)=>a+c.peso,0)-0.01);
  if(incompletas.length) add("info","Unidades sem todas as categorias",
    incompletas.map(u=>u.unidade).join(", ")+": a ponderada foi normalizada pelos pesos presentes — compare com cuidado com as unidades completas.");

  const geral=D.unidades.filter(u=>u.unidade==="Geral");
  if(geral.length) add("aten","Grupos sem unidade identificada",
    `Há medições em "Geral": o nome do grupo não termina num código de unidade. Aponte a unidade no cartão "Unidade de cada grupo de hosts".`);

  const semDado=D.semanas_com_dado.slice(0,n).map((v,i)=>v?null:D.semanas[i]).filter(Boolean);
  if(semDado.length) add("info","Semanas ainda sem importação", semDado.join(", ")+" de "+MES_NOME[D.mes-1]+" não têm CSV importado — o ACUM considera só as semanas presentes.");

  const ordem={crit:0,aten:1,bom:2,info:3}, rot={crit:"Crítico",aten:"Atenção",bom:"Positivo",info:"Contexto"};
  F.sort((a,b)=>ordem[a.t]-ordem[b.t]);
  $("findings").innerHTML=F.map(f=>`<div class="sx-finding"><span class="sx-tag sx-${f.t}">${rot[f.t]}</span><div><h4>${esc(f.tit)}</h4><p>${f.txt}</p></div></div>`).join("");
}

/* ── Ações ───────────────────────────────────────────────────────────────── */
async function baixarCsv(){
  const r=await chamar("export/semanal-csv",{ano:state.D.ano, mes:state.D.mes});
  if(r.ok&&r.texto){ download(`sla-semanal-${state.D.ano}-${String(state.D.mes).padStart(2,"0")}.csv`, r.texto, "text/csv;charset=utf-8"); toast("CSV baixado."); }
}
function redimensionar(){ Object.values(state.charts).forEach(c=>{ try{ c.resize(); c.update("none"); }catch(e){} }); }
/* ── Impressão em janela própria ─────────────────────────────────────────
   O frontend do Zabbix prende a página num contêiner rolável de 100vh, com
   sidebar fixa e main de 1200px. Imprimir a página inteira depende de vencer
   esse layout por CSS, e isso se mostrou frágil (cortava; depois esvaziou).
   Em vez disso, o relatório é copiado para uma janela limpa — só a faixa de
   identidade e o conteúdo, com a mesma folha de estilo — e os gráficos entram
   como imagem, congelados do estado que está na tela. */
function folhaDoModulo(){
  const link = [...document.querySelectorAll('link[rel="stylesheet"]')].find(l => /sla-executivo\.css/.test(l.href));
  return link ? link.href : "";
}

function imprimir(){
  const app = document.getElementById("sx-app");
  const relatorio = $("report");
  if(!app || !relatorio || relatorio.style.display === "none"){ toast("Não há relatório na tela para imprimir."); return; }

  const copia = relatorio.cloneNode(true);
  const originais = relatorio.querySelectorAll("canvas");
  copia.querySelectorAll("canvas").forEach((c, i) => {
    const img = document.createElement("img");
    try{ img.src = originais[i].toDataURL("image/png", 1.0); }catch(e){ img.alt = "gráfico"; }
    img.style.cssText = "width:100%;height:100%;object-fit:contain;display:block";
    c.parentNode.replaceChild(img, c);
  });

  /* A janela nasce em about:blank: caminhos relativos (o logo, por exemplo)
     não resolveriam. Os src viram absolutos antes de copiar. */
  const cabecalho = app.querySelector(".sx-print-head") ? app.querySelector(".sx-print-head").cloneNode(true) : null;
  if(cabecalho) cabecalho.querySelectorAll("img").forEach(i => i.setAttribute("src", i.src));
  const titulo = (cabecalho ? cabecalho.textContent : "SLA Executivo").replace(/\s+/g, " ").trim();
  const css = folhaDoModulo();

  const html = `<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>${esc(titulo)}</title>
    ${css ? `<link rel="stylesheet" href="${esc(css)}">` : ""}
    <style>
      @page{size:A4 landscape;margin:10mm}
      html,body{margin:0;padding:0;background:#fff;height:auto;overflow:visible}
      body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#16202E;padding:12px 14px}
      .sx-noprint{display:none!important}
      .sx-app .sx-card{overflow:visible!important;break-inside:avoid;page-break-inside:avoid}
      .sx-app .sx-tablewrap{max-height:none!important;overflow:visible!important}
      .sx-app .sx-matrix,.sx-app .sx-heat,.sx-app .sx-matrix-semanal{min-width:0!important}
      .sx-app .sx-chartbox{height:250px}
      .sx-app .sx-kpis{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
      .sx-print-head{border-radius:0;box-shadow:none}
      @media print{ .sx-print-head{background:none!important;color:#132A54} .sx-print-head div,.sx-print-head span{color:#132A54!important}
        .sx-print-head img{filter:none!important} }
    </style></head><body><div class="sx-app">${cabecalho ? cabecalho.outerHTML : ""}${copia.outerHTML}</div></body></html>`;

  const janela = window.open("", "_blank");
  if(!janela){ toast("O navegador bloqueou a janela de impressão. Libere pop-ups para este endereço."); return; }
  janela.document.open(); janela.document.write(html); janela.document.close();

  const disparar = () => { try{ janela.focus(); janela.print(); }catch(e){} };
  let disparado = false;
  const umaVez = () => { if(!disparado){ disparado = true; setTimeout(disparar, 250); } };
  janela.addEventListener("load", umaVez);
  setTimeout(umaVez, 1500);                       /* rede lenta para a folha de estilo */
  janela.addEventListener("afterprint", () => setTimeout(() => janela.close(), 300));
}

function init(){
  const app=document.getElementById("sx-app");
  if(app===null||!app.classList.contains("sx-semanal")) return;
  state.ctx=lerContexto();
  if(!state.ctx.db_ok){ $("report").style.display="none"; $("empty").style.display="block"; return; }
  if($("p-mes")) $("p-mes").onchange=()=>{ const [a,m]=$("p-mes").value.split("-"); carregar(a,m); };
  if($("btn-csv")) $("btn-csv").onclick=baixarCsv;
  if($("btn-print")) $("btn-print").onclick=imprimir;
  if($("btn-pesos")) $("btn-pesos").onclick=salvarPesos;
  if($("btn-addcat")) $("btn-addcat").onclick=adicionarCategoria;
  carregar();
}

if(document.readyState==="loading") document.addEventListener("DOMContentLoaded", init); else init();

if(typeof module!=="undefined"&&module.exports) module.exports={render, state, adicionarCategoria, removerCategoria};
})();
