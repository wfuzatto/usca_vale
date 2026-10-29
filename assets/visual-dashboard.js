'use strict';
/* Layout approved in the visual reference. No demonstration values are inserted:
   every number and state below is from the authenticated API. */
(function(){
  const graphColors=['#147df0','#12b957','#ed3449'];
  const graphKeys={
    Voltage:{id:'chartVoltage',legend:'legendVoltage',keys:['voltage_l12','voltage_l23','voltage_l31'],labels:['L1-L2','L2-L3','L3-L1'],title:'Tensão',unit:'V'},
    Frequency:{id:'chartFrequency',legend:'legendFrequency',keys:['mains_frequency','generator_frequency'],labels:['Concessionária','Gerador']},
    Current:{id:'chartCurrent',legend:'legendCurrent',keys:['current_l1','current_l2','current_l3'],labels:['Fase L1','Fase L2','Fase L3']},
    Power:{id:'chartPower',legend:'legendPower',keys:['power_kw'],labels:['Potência ativa'],title:'Potência Ativa',unit:'kW'},
    Factor:{id:'chartFactor',legend:'legendFactor',keys:['power_factor'],labels:['Fator de potência']},
    Demand:{id:'chartDemand',legend:'legendDemand',keys:['power_kva'],labels:['Demanda'],title:'Demanda',unit:'kVA'}
  };
  const vState={hours:1/12,chartLast:0,chartLoading:false,chartData:null,chartDevice:null,topology:{mains:'Concessionária',bus:'Barramento',loads:'Cargas do Parque'}};
  const icon=name=>`<svg class="ic" aria-hidden="true"><use href="#ic-${name}"></use></svg>`;
  const valid=d=>!!d && Number(d.enabled)===1 && !d.stale && !!d.latest && d.latest.values && typeof d.latest.values==='object';
  const value=(d,k)=>{if(!valid(d))return null;const n=d.latest.values[k];return n===undefined||n===null||n===''||!Number.isFinite(Number(n))?null:Number(n)};
  const bit=(d,k)=>{const n=value(d,k);return n===1?true:n===0?false:null};
  const firstValue=(d,keys)=>{for(const k of keys){const n=value(d,k);if(n!==null)return n}return null};
  const number=(v,dec=1)=>v===null?'—':Number(v).toLocaleString('pt-BR',{minimumFractionDigits:dec,maximumFractionDigits:dec});
  const engineeringUnit=unit=>({V:'kV',kW:'MW',kVA:'MVA',kvar:'Mvar',kWh:'MWh'})[unit];
  const reading=(n,unit='',dec=1)=>{
    if(n===null)return '—';
    const scaled=engineeringUnit(unit);
    if(scaled&&Math.abs(n)>=1000)return `${number(n/1000,Math.max(2,dec))} ${scaled}`;
    return `${number(n,dec)}${unit?' '+unit:''}`;
  };
  const metric=(d,keys,unit='',dec=1)=>reading(firstValue(d,keys),unit,dec);
  const txt=x=>esc(String(x??''));
  const shortTime=x=>{if(!x)return '—';const d=new Date(String(x).replace(' ','T').replace(/Z?$/,'Z'));return Number.isFinite(d.getTime())?d.toLocaleTimeString('pt-BR',{timeZone:'America/Sao_Paulo',hour:'2-digit',minute:'2-digit',second:'2-digit'}):'—'};
  const valsSource=(d,gen)=>gen ?
     [[['voltage_l12'],'Tensão L1-L2','V',1],[['generator_frequency','frequency'],'Frequência','Hz',2],[['current_l1'],'Corrente','A',0]] :
     [[['mains_voltage_l12'],'Tensão L1-L2','V',1],[['mains_voltage_l23'],'Tensão L2-L3','V',1],[['mains_voltage_l31'],'Tensão L3-L1','V',1]];
  const valsPower=(d,gen)=>gen ?
     [[['power_kw'],'Potência Ativa','kW',0],[['power_kva'],'Potência Aparente','kVA',0],[['power_factor'],'Fator de Potência','',2],[['engine_hours'],'Horas de Operação','h',0]] :
     [[['mains_frequency'],'Frequência','Hz',2],[['mains_power_kw'],'Potência Ativa','kW',0],[['mains_power_factor'],'Fator de Potência','',2]];
  function getMain(devices){return devices.find(d=>d.role==='mains' && Number(d.enabled)===1) || devices.find(d=>valid(d) && bit(d,'mains_available')!==null)|| devices.find(d=>d.role==='simulator' && valid(d)) ||null;}
  function getGenerators(devices){const list=devices.filter(d=>d.role==='generator');for(const d of devices){if(list.length>=3)break;if(['controller','simulator'].includes(d.role) && valid(d) && bit(d,'generator_running')!==null)list.push(d)}return list.slice(0,3)}
  function cardStatus(d,gen){
    if(!d)return gen?{state:'NÃO CADASTRADO',note:'Cadastrar equipamento',color:'grey'}:{state:'SEM LEITURA',note:'Medição da concessionária não configurada',color:'grey'};
    if(Number(d.enabled)!==1)return {state:'DESABILITADO',note:'Coleta desabilitada',color:'grey'};
    if(!valid(d))return {state:d.last_seen?'SEM COMUNICAÇÃO':'SEM LEITURA',note:'Dados indisponíveis',color:d.last_seen?'red':'grey'};
    const fault=bit(d,'generator_fault');if(gen && fault===true)return {state:'FALHA',note:'Alarme informado pelo controlador',color:'red'};
    const running=bit(d,'generator_running');if(gen){
      if(running===true)return {state:'EM OPERAÇÃO',note:'Motor funcionando',color:'green'};
      if(running===false)return {state:'PARADO',note:'Motor desligado',color:'grey'};
    }else{
      const mains=bit(d,'mains_available');if(mains===true)return {state:'NORMAL',note:'Rede presente',color:'green'};
      if(mains===false)return {state:'FALHA',note:'Rede indisponível',color:'red'};
    }
    return {state:'MONITORANDO',note:'Estado não mapeado',color:'blue'};
  }
  function card(d,title,isGenerator){
    const s=cardStatus(d,isGenerator),simulate=!!d&&Number(d.enabled)===1&&Number(d.simulate)===1;
    function readings(rows){return `<div class="reading-column">${rows.map(([keys,label,unit,dec])=>{const result=metric(d,keys,unit,dec);return `<div class="reading-line"><span class="reading-label" title="${txt(label)}">${txt(label)}</span><b class="reading-value ${result==='—'?'missing':''}">${txt(result)}</b></div>`}).join('')}</div>`}
    return `<article class="generator-card" aria-label="${txt(title)}: ${txt(s.state)}">
      <div class="generator-card-header"><span class="generator-card-icon ${s.color}">${icon(isGenerator?'zapbox':'tower')}</span><div class="generator-card-titles"><h2>${txt(title)}</h2><strong class="state ${s.color}">${txt(s.state)}</strong><p title="${txt(s.note)}">${simulate?'SIMULAÇÃO · ':''}${txt(s.note)}</p></div>${s.color==='red'?`<span class="generator-card-alert">${icon('alert')}</span>`:''}</div>
      <div class="generator-readings">${readings(valsSource(d,isGenerator))}${readings(valsPower(d,isGenerator))}</div>
    </article>`;
  }
  function tdStatus(e){let status=e.severity==='critical'?'critical':e.severity==='warning'?'warning':'info';return `<span class="severity-pill ${status}">${status==='critical'?'Alta':status==='warning'?'Média':'Info'}</span>`}
  const noRows=(n,msg)=>`<tr><td colspan="${n}" class="empty-state">${txt(msg)}</td></tr>`;
  function renderAlarms(data){
    $('alarmCounter').textContent=data.active.length;
    const alarms=data.active.slice(0,3);$('activeAlarms').innerHTML=alarms.length?alarms.map(e=>`<tr><td class="time-cell">${shortTime(e.started_at)}</td><td title="${txt(e.device)}">${txt(e.device)}</td><td title="${txt(e.message)}">${txt(e.message)}</td><td>${tdStatus(e)}</td></tr>`).join(''):noRows(4,'Nenhum alarme ativo');
    const count=data.active.length;
    const bell=$('bellCounter');bell.hidden=count===0;bell.textContent=count>99?'99+':String(count);
    const recent=(data.recent||[]).slice(0,10);$('recentEvents').innerHTML=recent.length?recent.map(e=>{
      const v=e.observed===null||e.observed===undefined?'—':number(Number(e.observed),1);
      const c=e.severity==='critical'?'red':e.severity==='warning'?'warning':'green';
      return `<tr><td class="time-cell"><i class="status-point ${c==='red'?'red':c==='warning'?'grey':''}" style="${c==='warning'?'background:#ffbc21':''}"></i> ${shortTime(e.started_at)}</td><td title="${txt(e.device)}">${txt(e.device)}</td><td title="${txt(e.message)}">${txt(e.message)}</td><td>${txt(v)}</td></tr>`;
    }).join(''):noRows(4,'Nenhum evento registrado');
  }
  function renderComm(devices){
    const adminDev=state.admin?.devices||[];
    $('commTable').innerHTML=devices.length?devices.slice(0,5).map(d=>{
      const active=valid(d),enabled=Number(d.enabled)===1;
      const host=adminDev.find(a=>Number(a.id)===Number(d.id))?.host||'—';
      const status=!enabled?'Inativo':active?'Online':d.last_seen?'Offline':'Sem leitura';
      const tone=active?'online':enabled&&d.last_seen?'offline':'unknown';
      return `<tr><td title="${txt(d.name)}">${txt(d.name)}</td><td title="${txt(host)}">${txt(host)}</td><td class="time-cell">${shortTime(d.last_seen)}</td><td class="${tone}"><i class="status-point ${active?'':enabled&&d.last_seen?'red':'grey'}"></i>${status}</td></tr>`;
    }).join(''):noRows(4,'Nenhum equipamento cadastrado');
  }
  function statusPill(x,y,label,color='grey'){
    const text=txt(label.length>19?label.slice(0,19):label),width=Math.max(57,Math.min(126,Math.round(label.length*6.4+18)));
    return `<rect class="state-block ${color}" x="${x-width/2}" y="${y}" rx="3" width="${width}" height="19"/><text class="state-label" x="${x}" y="${y+13}" text-anchor="middle">${text}</text>`;
  }
  function diagram(data){
    const dev=data.devices,main=getMain(dev),gens=getGenerators(dev);
    const sourceState=bit(main,'mains_available'),mainBreaker=bit(main,'mains_breaker'),tieBreaker=bit(main,'tie_breaker');
    const busVoltage=firstValue(main,['bus_voltage_l12']),busFrequency=firstValue(main,['bus_frequency']);
    const busDead=busVoltage!==null&&busVoltage<=0;
    const mainsSupply=sourceState===true&&mainBreaker===true&&tieBreaker===true;
    const activeGen=gens.find(d=>bit(d,'generator_breaker')===true&&bit(d,'generator_running')===true)||null;
    const supply=!busDead&&(mainsSupply||!!activeGen);
    const busLabel=busVoltage!==null||busFrequency!==null?`${reading(busVoltage,'V',1)} / ${reading(busFrequency,'Hz',2)}`:supply?'Alimentação confirmada; barramento sem medição':'Alimentação não confirmada';
    const topo=vState.topology;
    let html=`<svg class="energy-schematic" viewBox="0 0 950 280" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Diagrama unifilar conceitual. Os contatos verdes só indicam estados quando validados por telemetria.">
    <g class="tower" transform="translate(0,-9) scale(1,.71)"><path d="M80 42 50 158 M80 42 111 158 M60 95h40M53 122h55M46 157h70M67 76l13-12 13 12M65 104l31 24M95 104l-31 24M54 138l52 19M106 138l-52 19"/></g>
    <text class="diagram-title" x="81" y="124" text-anchor="middle">${txt(topo.mains.toUpperCase())}</text>
    ${statusPill(81,133,sourceState===true?'NORMAL':sourceState===false?'FALHA':'SEM LEITURA',sourceState===true?'green':sourceState===false?'red':'grey')}
    <path class="wire" d="M120 98H190M228 98H298V145H918"/>
    <rect class="element-box ${mainBreaker===true?'is-active':mainBreaker===false?'faulty':'inactive'}" x="190" y="88" width="38" height="19"/>
    <text class="diagram-sub" x="209" y="69" text-anchor="middle">Rede (MB)</text>
    <path class="wire" d="M198 106l22-17"/><text class="diagram-sub" x="294" y="52" text-anchor="middle">Interligação (TB)</text>
    <rect class="element-box ${tieBreaker===true?'is-active':tieBreaker===false?'faulty':'inactive'}" x="282" y="80" width="24" height="37"/>
    <path class="wire" d="M292 69v11"/>
    <text class="diagram-title" x="410" y="132" text-anchor="middle">${txt(topo.bus.toUpperCase())}</text>
    <text class="bus-label" x="410" y="161" text-anchor="middle">${txt(busLabel)}</text>`;
    const xs=[518,683,846];
    for(let i=0;i<3;i++){
      const d=gens[i]||null;const x=xs[i];const s=cardStatus(d,true);const breaker=bit(d,'generator_breaker');
      const title=d?d.name:`Gerador ${i+1}`;
      const short=title.length>20?title.slice(0,20)+'…':title;
      html+=`<g transform="translate(0,-23)"><text class="diagram-title" x="${x}" y="32" text-anchor="middle">${txt(short)}</text>
        ${statusPill(x,40,s.state==='EM OPERAÇÃO'?'EM OPERAÇÃO':s.state==='PARADO'?'PARADO':s.state==='FALHA'?'FALHA':'SEM LEITURA',s.color)}
        <rect class="generator-icon ${s.color}" x="${x-19}" y="76" width="38" height="29" rx="5"/>
        <circle class="generator-icon ${s.color}" cx="${x}" cy="90" r="8" stroke-width="2"/><path class="wire" d="M${x-11} 90h4m14 0h4"/>
        <path class="wire" d="M${x} 105v16m0 26v21"/>
        <rect class="element-box ${breaker===true?'is-active':breaker===false?'faulty':'inactive'}" x="${x-9}" y="121" width="18" height="26"/>
        <path class="wire" d="M${x-6} 137l12-9"/><circle fill="#0c2a4b" cx="${x}" cy="168" r="3"/>
        </g>`;
    }
    html+=`<path class="wire" d="M480 145v56"/><path d="M474 194l6 9 6-9" fill="#0d2945"/><g transform="translate(463,202)" stroke="#14334c" stroke-width="3" fill="none"><path d="M0 30v-20h22v20M5 10V3h12v7M3 20h4m5 0h5m-14 5h4m5 0h5"/></g>
      <text class="diagram-title" x="605" y="223" text-anchor="middle">${txt(topo.loads.toUpperCase())}</text>
      ${statusPill(605,231,supply?'ALIMENTADAS':'NÃO CONFIRMADO',supply?'green':'grey')}
    </svg>`;
    $('unifilar').innerHTML=html;
  }
  window.uscaVisualRenderOverview=function(data){
    state.overview=data;
    const devices=data.devices||[],main=getMain(devices),gens=getGenerators(devices);
    $('summaryCards').innerHTML=card(main,'Concessionária',false)+[0,1,2].map(i=>card(gens[i]||null,`Gerador ${i+1}`,true)).join('');
    diagram(data);renderAlarms(data);renderComm(devices);
    const validDev=devices.filter(valid),enabled=devices.filter(d=>Number(d.enabled)===1);
    const healthy=enabled.length>0&&enabled.every(valid),mixed=enabled.some(d=>Number(d.simulate)===1),real=enabled.some(d=>Number(d.simulate)!==1);
    const health=$('collectorDot').parentElement;
    health.className='command-health '+(healthy?'':validDev.length?'warning':'error');
    $('collectorLabel').textContent=healthy?(mixed&&!real?'Simulador Online':'Sistema Online'):validDev.length?'Comunicação parcial':'Sem leitura recente';
    $('environmentLabel').textContent=mixed?(real?'Misto • incluindo simulação':'Simulação'):'Produção';
    $('footerPoll').textContent=enabled.length?`${Math.min(...enabled.map(d=>Number(d.poll_seconds)||5))} segundos`:'não configurada';
    const selected=$('metricDevice').value;
    $('metricDevice').innerHTML=devices.map(d=>`<option value="${d.id}">${txt(d.name)}${d.simulate?' [SIMULADO]':''}</option>`).join('');
    $('metricDevice').value=devices.some(d=>String(d.id)===selected)?selected:String(validDev[0]?.id??devices[0]?.id??'');
    renderMetricGroups();
  };
  function hourText(n){return new Date(n).toLocaleTimeString('pt-BR',{timeZone:'America/Sao_Paulo',hour:'2-digit',minute:'2-digit'})}
  function chart(canvas,rows,defs,bucket){
    const bounds=canvas.getBoundingClientRect(),w=Math.max(80,bounds.width||260),h=Math.max(100,bounds.height||105),dpr=Math.min(2,window.devicePixelRatio||1);
    canvas.width=Math.floor(w*dpr);canvas.height=Math.floor(h*dpr);
    const ctx=canvas.getContext('2d');ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,w,h);
    const L=31,R=4,T=7,B=20,W=w-L-R,H=h-T-B;
    ctx.font='10px Segoe UI,Arial,sans-serif';ctx.fillStyle='#586d85';
    const ts=rows.map(r=>Date.parse(r.bucket));
    const series=defs.filter(d=>rows.some(r=>r[d.key]!==null&&r[d.key]!==undefined&&Number.isFinite(Number(r[d.key]))));
    if(!rows.length||!series.length){ctx.fillStyle='#98a6b7';ctx.textAlign='center';ctx.fillText('Sem dados no período',w/2,h/2);return []}
    const vals=rows.flatMap(r=>series.map(s=>r[s.key])).filter(v=>v!==null&&v!==undefined&&Number.isFinite(Number(v))).map(Number);
    let lo=Math.min(...vals),hi=Math.max(...vals);
    if(lo===hi){const bump=Math.max(0.02,Math.abs(lo)*.015);lo-=bump;hi+=bump}
    const pad=(hi-lo)*.12;lo-=pad;hi+=pad;
    if(lo>0 && lo<0.12)lo=0;
    const tmin=ts[0],tmax=ts[ts.length-1],x=t=>L+(t-tmin)/Math.max(1,tmax-tmin)*W,y=v=>T+H-(v-lo)/(hi-lo)*H;
    ctx.lineWidth=.8;for(let i=0;i<=4;i++){
      const yy=T+i*H/4;ctx.strokeStyle='#e5edf5';ctx.beginPath();ctx.moveTo(L,yy);ctx.lineTo(w-R,yy);ctx.stroke();
      ctx.fillStyle='#5a6f86';ctx.textAlign='right';ctx.fillText((hi-i*(hi-lo)/4).toLocaleString('pt-BR',{maximumFractionDigits:(hi-lo)<2?2:0}),L-4,yy+3);
    }
    for(let i=0;i<=5;i++){const xx=L+W*i/5;ctx.strokeStyle='#eff3f8';ctx.beginPath();ctx.moveTo(xx,T);ctx.lineTo(xx,T+H);ctx.stroke()}
    ctx.fillStyle='#65788b';ctx.textAlign='center';
    for(const i of [0,2,4]){const tt=tmin+(tmax-tmin)*i/4;ctx.fillText(hourText(tt),L+W*i/4,h-5)}
    series.forEach(d=>{
      ctx.strokeStyle=d.color;ctx.lineWidth=1.9;ctx.lineJoin='round';ctx.beginPath();let prior=null;
      rows.forEach((r,i)=>{
        const n=r[d.key];if(n===null||n===undefined||!Number.isFinite(Number(n))){prior=null;return}
        const xx=x(ts[i]),yy=y(Number(n));if(prior!==null&&ts[i]-prior<=bucket*2600)ctx.lineTo(xx,yy);else ctx.moveTo(xx,yy);prior=ts[i];
      });ctx.stroke();
    });
    return series;
  }
  function renderAllCharts(){if(!vState.chartData)return;for(const g of Object.values(graphKeys)){
    const el=$(g.id),legend=$(g.legend);if(!el||!legend)continue;
    const all=g.keys.map((k,i)=>({key:k,color:graphColors[i%3],label:g.labels[i]}));
    const rows=vState.chartData.rows;
    const scaled=g.unit&&engineeringUnit(g.unit)&&rows.some(r=>g.keys.some(k=>r[k]!==null&&r[k]!==undefined&&Math.abs(Number(r[k]))>=1000));
    const displayRows=scaled?rows.map(r=>({...r,...Object.fromEntries(g.keys.map(k=>[k,r[k]===null||r[k]===undefined?null:Number(r[k])/1000]))})):rows;
    if(g.title)el.parentElement.querySelector('h3').textContent=`${g.title} (${scaled?engineeringUnit(g.unit):g.unit})`;
    const found=chart(el,displayRows,all,vState.chartData.bucket_seconds);
    legend.innerHTML=found.map(s=>`<span class="chart-legend-item"><i class="chart-legend-line" style="background:${s.color}"></i>${txt(s.label)}</span>`).join('');
  }}
  window.uscaRefreshCharts=async function(data,force=false){
    if(!data||vState.chartLoading||state.page!=='overview')return;
    const devices=data.devices||[];
    const chosen=devices.find(d=>String(d.id)===$('metricDevice').value);
    const source=valid(chosen)?chosen:devices.find(valid);
    const now=Date.now();if(!force&&now-vState.chartLast<15000 && String(source?.id||'')===String(vState.chartDevice||''))return;
    vState.chartLast=now;vState.chartDevice=source?.id||null;
    if(!source){vState.chartData={rows:[],bucket_seconds:5};renderAllCharts();return}
    vState.chartLoading=true;
    try{
      const to=new Date(now).toISOString().slice(0,19).replace('T',' '),from=new Date(now-vState.hours*3600000).toISOString().slice(0,19).replace('T',' ');
      const metrics=[...new Set(Object.values(graphKeys).flatMap(c=>c.keys))];
      vState.chartData=await api('history',null,{device:String(source.id),metrics:metrics.join(','),from,to});
      if(state.page==='overview')renderAllCharts();
    }catch(e){vState.chartData={rows:[],bucket_seconds:5};renderAllCharts();console.warn('Falha ao carregar gráficos:',e.message)}finally{vState.chartLoading=false}
  };
  function setClock(){const now=new Date();$('headerClock').textContent=now.toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}).replace(',','');}
  function init(){
    setClock();setInterval(setClock,1000);
    $('bellEvents').addEventListener('click',()=>navTo('events'));
    document.querySelectorAll('[data-overview-hours]').forEach(b=>b.addEventListener('click',()=>{
      vState.hours=Number(b.dataset.overviewHours)||1/12;
      document.querySelectorAll('[data-overview-hours]').forEach(x=>x.classList.toggle('active',x===b));
      vState.chartLast=0;window.uscaRefreshCharts?.(state.overview,true);
    }));
    $('editTopology').addEventListener('click',()=>{
      $('topoMains').value=vState.topology.mains;$('topoBus').value=vState.topology.bus;$('topoLoads').value=vState.topology.loads;$('topologyDialog').showModal();
    });
    $('closeTopology').addEventListener('click',()=>$('topologyDialog').close());
    $('topologyForm').addEventListener('submit',async ev=>{
      ev.preventDefault();const labels={mains:$('topoMains').value.trim(),bus:$('topoBus').value.trim(),loads:$('topoLoads').value.trim()};
      if(!labels.mains||!labels.bus||!labels.loads){notice('Preencha os três rótulos',true);return}
      try{await api('topology_save',{labels});vState.topology=labels;$('topologyDialog').close();if(state.overview)diagram(state.overview);notice('Topologia conceitual salva')}
      catch(e){notice(e.message,true)}
    });
    api('topology').then(data=>{if(data.labels&&typeof data.labels==='object'){vState.topology={...vState.topology,...data.labels};if(state.overview)diagram(state.overview)}}).catch(e=>console.warn('Topologia indisponível:',e.message));
    api('devices').then(a=>{state.admin=a;if(state.overview)renderComm(state.overview.devices)}).catch(()=>{});
    window.addEventListener('resize',()=>{if(state.page==='overview')renderAllCharts()});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
