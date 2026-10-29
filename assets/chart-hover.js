'use strict';
/* Tooltip de telemetria compartilhado entre os seis graficos e o historico.
 * Nenhum dado e gerado aqui: apenas as medias por bucket retornadas pela API. */
(function(){
  const registry=new WeakMap();
  const dateFormat=new Intl.DateTimeFormat('pt-BR',{
    timeZone:'America/Sao_Paulo',day:'2-digit',month:'2-digit',year:'numeric',
    hour:'2-digit',minute:'2-digit',second:'2-digit'
  });
  function number(value,unit){
    const n=Number(value);
    if(!Number.isFinite(n))return '—';
    const decimals=unit==='Hz'||unit==='PF'||unit===''?2:unit==='kV'||unit==='MW'||unit==='MVA'||unit==='Mvar'?3:1;
    return n.toLocaleString('pt-BR',{minimumFractionDigits:decimals,maximumFractionDigits:decimals})+(unit?' '+unit:'');
  }
  function isReal(value){return value!==null&&value!==undefined&&value!==''&&Number.isFinite(Number(value))}
  function hide(state){state.tooltip.hidden=true;state.guide.hidden=true;state.dots.replaceChildren()}
  function attach(canvas,config){
    if(!canvas)return;
    let state=registry.get(canvas);
    if(!state){
      const host=canvas.parentElement;
      host.classList.add('usca-chart-hover-host');
      const tooltip=document.createElement('div');
      tooltip.className='usca-chart-hover-tooltip';tooltip.hidden=true;
      tooltip.setAttribute('role','status');tooltip.setAttribute('aria-live','off');
      const guide=document.createElement('div');
      guide.className='usca-chart-hover-guide';guide.hidden=true;
      const dots=document.createElement('div');
      dots.className='usca-chart-hover-dots';dots.setAttribute('aria-hidden','true');
      host.append(guide,dots,tooltip);
      state={canvas,host,tooltip,guide,dots,config:null};
      registry.set(canvas,state);
      canvas.style.cursor='crosshair';
      canvas.addEventListener('mousemove',event=>show(state,event));
      canvas.addEventListener('mouseleave',()=>hide(state));
      canvas.addEventListener('blur',()=>hide(state));
    }
    state.config=config;
    hide(state);
  }
  function show(state,event){
    const cfg=state.config;
    if(!cfg||!cfg.rows||!cfg.rows.length||!cfg.series||!cfg.series.length)return hide(state);
    const {canvas,host,tooltip,guide,dots}=state;
    const rect=canvas.getBoundingClientRect();
    if(rect.width<=0||rect.height<=0)return hide(state);
    const scaleX=canvas.clientWidth?canvas.clientWidth/rect.width:1;
    const scaleY=canvas.clientHeight?canvas.clientHeight/rect.height:1;
    const mouseX=(event.clientX-rect.left)*scaleX;
    const mouseY=(event.clientY-rect.top)*scaleY;
    const plot=cfg.plot;
    if(mouseX<plot.left-7||mouseX>plot.left+plot.width+7||
       mouseY<plot.top-12||mouseY>plot.top+plot.height+12)return hide(state);
    let chosen=null,minDistance=Infinity;
    for(const row of cfg.rows){
      const ts=Number(row.ts);
      if(!Number.isFinite(ts))continue;
      if(!cfg.series.some(s=>isReal(row[s.key])))continue;
      const distance=Math.abs(cfg.x(ts)-mouseX);
      if(distance<minDistance){minDistance=distance;chosen=row}
    }
    // Nao exibir um ponto distante atraves de longas lacunas de comunicacao.
    if(!chosen||minDistance>Math.max(14,Math.min(25,plot.width/12)))return hide(state);
    const values=cfg.series.filter(s=>isReal(chosen[s.key]));
    if(!values.length)return hide(state);
    const nearestY=Math.min(...values.map(s=>Math.abs(cfg.y(Number(chosen[s.key]))-mouseY)));
    if(nearestY>Math.max(23,Math.min(38,plot.height*.35)))return hide(state);
    const heading=document.createElement('div');
    heading.className='usca-chart-hover-time';
    heading.textContent=dateFormat.format(new Date(Number(chosen.ts)))+' (Brasília)';
    const note=document.createElement('div');note.className='usca-chart-hover-note';
    note.textContent='Média do intervalo consultado';
    const list=document.createElement('div');list.className='usca-chart-hover-list';
    for(const item of values){
      const line=document.createElement('div');line.className='usca-chart-hover-value';
      const name=document.createElement('span');name.className='usca-chart-hover-name';
      const color=document.createElement('i');color.style.backgroundColor=item.color||'#147df0';
      const label=document.createElement('span');label.textContent=item.label||item.key;
      name.append(color,label);
      const val=document.createElement('strong');val.textContent=number(chosen[item.key],item.unit||'');
      line.append(name,val);list.append(line);
    }
    tooltip.replaceChildren(heading,note,list);
    tooltip.hidden=false;
    const hostRect=host.getBoundingClientRect();
    const mx=event.clientX-hostRect.left,my=event.clientY-hostRect.top;
    const width=tooltip.offsetWidth,height=tooltip.offsetHeight;
    const px=Math.max(5,Math.min(hostRect.width-width-5,mx+12));
    const py=my-height-12>=3?my-height-12:Math.min(hostRect.height-height-3,my+12);
    tooltip.style.left=px+'px';tooltip.style.top=Math.max(3,py)+'px';
    const canvasOffset=rect.left-hostRect.left;
    const canvasOffsetTop=rect.top-hostRect.top;
    const nearestX=canvasOffset+cfg.x(Number(chosen.ts))/scaleX;
    guide.style.left=nearestX+'px';
    guide.style.top=canvasOffsetTop+plot.top/scaleY+'px';
    guide.style.height=plot.height/scaleY+'px';
    guide.hidden=false;
    dots.replaceChildren();
    for(const item of values){
      const dot=document.createElement('i');dot.className='usca-chart-hover-dot';
      dot.style.backgroundColor=item.color||'#147df0';
      dot.style.left=nearestX+'px';
      dot.style.top=canvasOffsetTop+cfg.y(Number(chosen[item.key]))/scaleY+'px';
      dots.append(dot);
    }
  }
  window.USCAChartHover={attach};
})();