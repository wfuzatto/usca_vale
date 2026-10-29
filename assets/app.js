'use strict';
const $=id=>document.getElementById(id);
const state={page:'overview', overview:null, admin:null, device:0, historyData:null};
const COLORS=['#2b7cf1','#23b5ad','#df8b2b','#805bea','#eb6170','#688cb5','#67a466','#b660aa'];
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const num=(v,d=1)=>v===null||v===undefined||v===''?'—':Number(v).toLocaleString('pt-BR',{minimumFractionDigits:d,maximumFractionDigits:d});
const when=v=>!v?'—':new Date(String(v).replace(' ','T').replace(/Z?$/,'Z')).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo',dateStyle:'short',timeStyle:'medium'});
const isoLocal=date=>{const pad=n=>String(n).padStart(2,'0');return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`};
const utcParam=v=>v?new Date(v).toISOString().slice(0,19).replace('T',' '):'';
const minutesAgo=iso=>{if(!iso)return null;return Math.floor((Date.now()-new Date(iso.replace(' ','T')+'Z').getTime())/60000)};
function notice(txt,error=false){const el=$('notice');el.hidden=false;el.classList.toggle('danger',error);el.textContent=txt;clearTimeout(notice.timer);notice.timer=setTimeout(()=>el.hidden=true,6500)}
async function api(action,body=null,params={}){
  const query=new URLSearchParams({action,...params});const opt={credentials:'same-origin',cache:'no-store'};
  if(body!==null){opt.method='POST';opt.headers={'Content-Type':'application/json','X-CSRF-Token':window.USCA_CSRF};opt.body=JSON.stringify(body)}
  const res=await fetch('api.php?'+query,opt);if(res.status===401){location.href='login.php';throw Error('Autenticação encerrada')}
  const json=await res.json();if(!res.ok||json.error)throw Error(json.error||'Falha HTTP '+res.status);return json;
}
const navTo=(page,scrollto=null,control=null)=>{
 state.page=page;
 document.querySelectorAll('.page').forEach(el=>el.classList.toggle('active',el.id==='page-'+page));
 document.querySelectorAll('.sidenav button[data-page]').forEach(el=>el.classList.toggle('active',el===(control||document.querySelector(`.sidenav button[data-page="${page}"]`))));
 const titles={overview:'Dashboard',graphs:'Gráficos',history:'Histórico',events:'Eventos e Alarmes',equipment:'Equipamentos',settings:'Configurações',backup:'Backup',about:'Sobre'};
 $('pageTitle').textContent=titles[page]||'USCA Vale';$('sidebar').classList.remove('open');
 if(page==='history')searchHistory();if(page==='events')searchEvents();if(page==='equipment'||page==='settings')loadAdmin();
 if(page==='overview')window.uscaRefreshCharts?.(state.overview,true);
 if(scrollto){requestAnimationFrame(()=>{document.getElementById(scrollto)?.scrollIntoView({block:'start',behavior:'smooth'})})}
 else window.scrollTo({top:0,behavior:'instant'});
};
document.querySelectorAll('[data-page]').forEach(b=>b.addEventListener('click',()=>navTo(b.dataset.page,b.dataset.scrollto||null,b.closest('.sidenav')?b:null)));
document.querySelectorAll('[data-go]').forEach(b=>b.addEventListener('click',()=>navTo(b.dataset.go,b.dataset.scrollto||null)));
$('menuToggle').onclick=()=>$('sidebar').classList.toggle('open');
const sev=s=>({critical:'Crítica',warning:'Atenção',info:'Informação'})[s]||s;
const source=s=>({polling:'Amostragem',controller:'DEIF',system:'Sistema',simulation:'Simulação'})[s]||s;
function badge(s){return `<span class="severity ${esc(s)}">${esc(sev(s))}</span>`}
function stat(title,value,sub,klass=''){return `<div class="stat"><label>${esc(title)}</label><div class="stat-value">${esc(value)}</div><div class="stat-sub ${klass}">${esc(sub)}</div></div>`}
function alive(d){return d&&d.enabled&&!d.stale&&d.latest}
function statusFrom(d,metric){if(!alive(d)||d.latest.values?.[metric]===undefined)return null;return !!d.latest.values[metric]}
function availableMetric(d,k){return alive(d)&&typeof d.latest.values?.[k]==='number'?d.latest.values[k]:null}
function renderOverview(data){
 if(typeof window.uscaVisualRenderOverview==='function')window.uscaVisualRenderOverview(data);
 else state.overview=data;
}
function renderMetricGroups(){const d=state.overview?.devices?.find(d=>String(d.id)===$('metricDevice').value);if(!d){$('metricGroups').innerHTML='<div class="empty-state">Nenhum dispositivo.</div>';return}
 const catalog=state.overview.catalog||{};const vals=alive(d)?d.latest.values:{};
 let groups={};for(const [key,v] of Object.entries(catalog)){const val=vals[key];if(val===undefined||val===null)continue;const group=v[2];(groups[group]??=[]).push(`<div class="metric"><label>${esc(v[0])}</label><strong>${v[1]==='bool'?(val?'Ativo':'Inativo'):num(val,v[1]==='Hz'||v[1]===''?2:1)} <small>${esc(v[1]==='bool'?'':v[1])}</small></strong></div>`)}
 $('metricGroups').innerHTML=Object.entries(groups).map(([group,cards])=>`<h4>${esc(group)}</h4><div class="metric-grid">${cards.join('')}</div>`).join('')||'<div class="empty-state">Sem medições válidas. Verifique o coletor/mapeamento ou a idade dos dados.</div>';
}
$('metricDevice').addEventListener('change',renderMetricGroups);
function fillDeviceSelect(select,includeAll=true){const devices=state.overview?.devices||[];const before=select.value;select.innerHTML=(includeAll?'<option value="0">Todos os equipamentos</option>':'')+devices.map(d=>`<option value="${d.id}">${esc(d.name)}${d.simulate?' [SIMULADO]':''}</option>`).join('');if([...select.options].some(x=>x.value===before))select.value=before;else if(!includeAll&&devices.length)select.value=String(devices[0].id)}
function setPeriod(fromId,toId,hours){const now=new Date(),past=new Date(now.getTime()-hours*3600e3);$(fromId).value=isoLocal(past);$(toId).value=isoLocal(now)}
function queryPeriod(fromId,toId){return{from:utcParam($(fromId).value),to:utcParam($(toId).value)}}
function metricsSelected(){return [...document.querySelectorAll('#metricPicks input:checked')].map(x=>x.value)}
function buildMetricPicker(){const cat=state.overview?.catalog||{};const keys=['voltage_l12','voltage_l23','voltage_l31','frequency','current_l1','current_l2','current_l3','power_kw','power_kva','power_factor','load_pct','engine_rpm','engine_temp','oil_pressure'];$('metricPicks').innerHTML=keys.filter(k=>cat[k]).map((k,i)=>`<label><input type="checkbox" value="${k}" ${[0,1,2].includes(i)?'checked':''}> ${esc(cat[k][0])}</label>`).join('')}
async function searchHistory(){try{
 const metrics=metricsSelected();if(!metrics.length){notice('Selecione uma grandeza',true);return}
 const device=$('historyDevice').value;
 const data=await api('history',null,{device,metrics:metrics.join(','),...queryPeriod('dateFrom','dateTo')});state.historyData=data;
 $('historySubtitle').textContent=`${data.rows.length} pontos agregados`; $('bucketInfo').textContent=`Amostras por ${data.bucket_seconds} s`;
 $('historyLegend').innerHTML=metrics.map((key,i)=>`<span><i style="background:${COLORS[i%COLORS.length]}"></i>${esc(state.overview.catalog[key][0])}</span>`).join('');
 drawChart($('historyChart'),data.rows,metrics,data.bucket_seconds,data.poll_seconds);
 const ev=await api('events',null,{device,...queryPeriod('dateFrom','dateTo')});$('historyEvents').innerHTML=miniEventTable(ev.rows.slice(0,15));
 }catch(e){notice(e.message,true)}}
function miniEventTable(rows){return rows.length?`<div class="table-responsive"><table class="table-custom"><thead><tr><th>Horário</th><th>Equipamento</th><th>Evento</th><th>Severidade</th></tr></thead><tbody>${rows.map(e=>`<tr><td>${when(e.started_at)}</td><td>${esc(e.device)}</td><td>${esc(e.message)}</td><td>${badge(e.severity)}</td></tr>`).join('')}</tbody></table></div>`:'<div class="empty-state">Nenhum evento encontrado no intervalo</div>'}
function drawChart(canvas,rows,series,bucket,pollSeconds){const box=canvas.getBoundingClientRect();const w=Math.max(360,box.width||800),h=Math.max(180,box.height||240),dpr=window.devicePixelRatio||1;canvas.width=Math.round(w*dpr);canvas.height=Math.round(h*dpr);const ctx=canvas.getContext('2d');ctx.scale(dpr,dpr);
 ctx.clearRect(0,0,w,h);const L=68,R=20,T=19,B=38;const pw=w-L-R,ph=h-T-B;
 const points=rows.map(r=>({ts:Date.parse(r.bucket),...r}));const vals=points.flatMap(r=>series.map(key=>r[key]===null?null:Number(r[key]))).filter(v=>v!==null&&Number.isFinite(v));
 if(!vals.length){ctx.fillStyle='#93a1af';ctx.textAlign='center';ctx.fillText('Sem dados para o período selecionado',w/2,h/2);window.USCAChartHover?.attach(canvas,{rows:[],series:[]});return}
 let ymin=Math.min(...vals),ymax=Math.max(...vals);if(ymin===ymax){ymin-=Math.abs(ymin)*.02||1;ymax+=Math.abs(ymax)*.02||1}const pad=(ymax-ymin)*.10;ymin-=pad;ymax+=pad;
 const tmin=points[0]?.ts||0,tmax=points[points.length-1]?.ts||1;
 const x=t=>L+(t-tmin)/Math.max(1,tmax-tmin)*pw,y=v=>T+(1-(v-ymin)/(ymax-ymin))*ph;
 ctx.font='11px "Segoe UI",sans-serif';ctx.textAlign='right';ctx.lineWidth=1;
 for(let i=0;i<=4;i++){const yy=T+ph*i/4;ctx.strokeStyle='#edf2f8';ctx.beginPath();ctx.moveTo(L,yy);ctx.lineTo(w-R,yy);ctx.stroke();ctx.fillStyle='#90a2b4';ctx.fillText(num(ymax-(ymax-ymin)*i/4,1),L-12,yy+4)}
 ctx.textAlign='center';for(let i=0;i<=4;i++){const tt=tmin+(tmax-tmin)*i/4;const xx=L+pw*i/4;ctx.fillStyle='#8ba0b4';ctx.fillText(new Date(tt).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}),xx,h-11)}
 series.forEach((key,index)=>{ctx.lineWidth=2.4;ctx.strokeStyle=COLORS[index%COLORS.length];ctx.beginPath();let previous=null;for(const p of points){const val=p[key]===null?null:Number(p[key]);if(val===null||!Number.isFinite(val)){previous=null;continue}const xx=x(p.ts),yy=y(val);if(previous!==null&&window.USCAChartSeries.joins(previous,p.ts,bucket,pollSeconds)){ctx.lineTo(xx,yy)}else ctx.moveTo(xx,yy);previous=p.ts}ctx.stroke();
 // Make isolated samples visible without interpolating a communication gap.
 let prevPoint=null;
 for(let i=0;i<points.length;i++){
  const p=points[i],n=p[key];
  if(n===null||n===undefined||!Number.isFinite(Number(n))){prevPoint=null;continue}
  const next=points[i+1],connectPrev=prevPoint!==null&&window.USCAChartSeries.joins(prevPoint,p.ts,bucket,pollSeconds);
  const connectNext=next&&next[key]!==null&&next[key]!==undefined&&Number.isFinite(Number(next[key]))&&window.USCAChartSeries.joins(p.ts,next.ts,bucket,pollSeconds);
  if(!connectPrev&&!connectNext){ctx.beginPath();ctx.arc(x(p.ts),y(Number(n)),3.2,0,Math.PI*2);ctx.fillStyle=COLORS[index%COLORS.length];ctx.fill()}
  prevPoint=p.ts;
 }});
 window.USCAChartHover?.attach(canvas,{
  rows:points,
  series:series.map((key,index)=>({
   key,color:COLORS[index%COLORS.length],
   label:state.overview?.catalog?.[key]?.[0]||key,
   unit:state.overview?.catalog?.[key]?.[1]||''
  })),
  plot:{left:L,top:T,width:pw,height:ph},x,y
 });
}
async function searchEvents(){try{const d=await api('events',null,{device:$('eventDevice').value,severity:$('eventSeverity').value,...queryPeriod('eventFrom','eventTo')});
 $('eventCount').textContent=d.rows.length+' ocorrência(s)'+(d.truncated?' (limite de exibição)':'');
 $('eventTable').innerHTML=d.rows.map(e=>{
 const a=Date.parse(e.started_at.replace(' ','T')+'Z'),b=e.ended_at?Date.parse(e.ended_at.replace(' ','T')+'Z'):Date.now();let sec=Math.max(0,Math.round((b-a)/1000));const duration=sec<60?sec+' s':sec<3600?Math.floor(sec/60)+' min '+sec%60+' s':(sec/3600).toFixed(1)+' h';
 return `<tr><td><b>${esc(e.message)}</b><div class="device-meta">${esc(e.category)} • ${esc(source(e.source))}</div></td><td>${esc(e.device)}</td><td>${when(e.started_at)}</td><td>${e.ended_at?when(e.ended_at):'<span class="severity critical">EM ABERTO</span>'}<div class="device-meta">${duration}</div></td><td>${num(e.minimum,1)} / ${num(e.maximum,1)}</td><td>${badge(e.severity)}</td><td>${e.acknowledged_at?'✓ Reconhecido':`<button data-ack="${e.id}">Reconhecer</button>`}</td></tr>`}).join('')||'<tr><td colspan="7" class="empty-state">Sem eventos registrados</td></tr>';
 }catch(e){notice(e.message,true)}}
$('eventTable').addEventListener('click',async e=>{const id=e.target.dataset.ack;if(!id)return;try{await api('ack_event',{id:Number(id)});notice('Evento reconhecido');searchEvents()}catch(err){notice(err.message,true)}});
function csvExport(){const p=new URLSearchParams({action:'events_csv',device:$('eventDevice').value,...queryPeriod('eventFrom','eventTo')});location.href='api.php?'+p}
async function loadAdmin(){try{state.admin=await api('devices');renderAdmin()}catch(e){notice(e.message,true)}}
function renderAdmin(){if(!state.admin)return;const {devices,mappings,rules,catalog}=state.admin;
 const mapDevice=$('mapDevice').value;$('mapDevice').innerHTML=devices.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join('');if(devices.some(d=>String(d.id)===mapDevice))$('mapDevice').value=mapDevice;
 $('equipmentList').innerHTML=devices.map(d=>`<div class="device-row"><div><div class="device-name">${esc(d.name)} ${d.simulate?'<span class="severity info">SIMULADO</span>':''}</div><div class="device-meta">${esc(d.role)} · ${esc(d.host||'sem IP')} : ${d.port} · Unit ${d.unit_id} · a cada ${d.poll_seconds}s ${d.enabled?'· ativo':'· desabilitado'}</div></div><div class="device-row-actions"><button data-test="${d.id}" ${d.simulate?'disabled':''}>Testar TCP</button><button data-edit="${d.id}">Editar</button></div></div>`).join('');
 const metrics=Object.entries(catalog).map(([k,v])=>`<option value="${esc(k)}">${esc(v[0])} (${esc(v[1])})</option>`).join('');$('mapMetric').innerHTML=metrics;$('ruleMetric').innerHTML=metrics;
 $('mapTable').innerHTML=mappings.map(m=>`<tr><td>${esc(devices.find(d=>d.id==m.device_id)?.name||'?')}</td><td>${esc(catalog[m.metric]?.[0]||m.metric)}</td><td>${m.fc}</td><td>${m.address}</td><td>${esc(m.value_type)}</td><td>${m.scale_exp}</td><td><button data-remap="${m.id}">Excluir</button></td></tr>`).join('')||'<tr><td colspan="7">Nenhum registro real mapeado</td></tr>';
 $('retentionDays').value=state.admin.retention_days;
 $('rulesTable').innerHTML=rules.map(r=>`<tr><td>${esc(r.name)}</td><td>${esc(catalog[r.metric]?.[0]||r.metric)}</td><td>${r.comparison==='below'?'Abaixo':'Acima'}</td><td>${num(r.threshold,2)}</td><td>${r.hold_seconds} s</td><td>${badge(r.severity)}</td></tr>`).join('');
}
$('equipmentList').addEventListener('click',async e=>{const id=Number(e.target.dataset.edit||0),testId=Number(e.target.dataset.test||0);if(id)openDevice(id);if(testId){try{notice((await api('test_connection',{id:testId})).message)}catch(err){notice(err.message,true)}}});
$('mapTable').addEventListener('click',async e=>{const id=Number(e.target.dataset.remap||0);if(id&&confirm('Excluir este mapeamento?')){try{await api('mapping_delete',{id});await loadAdmin();notice('Removido')}catch(err){notice(err.message,true)}}});
function openDevice(id=0){const d=state.admin?.devices?.find(d=>d.id==id)||{};$('deviceId').value=d.id||0;$('deviceName').value=d.name||'';$('deviceRole').value=d.role||'controller';$('deviceHost').value=d.host||'';$('devicePort').value=d.port||502;$('deviceUnit').value=d.unit_id??1;$('devicePoll').value=d.poll_seconds||5;$('deviceKva').value=d.rated_kva||'';$('deviceSim').checked=!!Number(d.simulate);$('deviceEnabled').checked=!!Number(d.enabled);$('deviceDialog').showModal()}
$('newDevice').onclick=()=>openDevice();$('closeDevice').onclick=()=>$('deviceDialog').close();
$('deviceForm').addEventListener('submit',async ev=>{ev.preventDefault();try{
 await api('device_save',{id:Number($('deviceId').value),name:$('deviceName').value,role:$('deviceRole').value,host:$('deviceHost').value,port:Number($('devicePort').value),unit_id:Number($('deviceUnit').value),poll_seconds:Number($('devicePoll').value),rated_kva:$('deviceKva').value,simulate:$('deviceSim').checked,enabled:$('deviceEnabled').checked});$('deviceDialog').close();await loadAdmin();await loadOverview();notice('Equipamento salvo');
 }catch(e){notice(e.message,true)}});
$('testDevice').onclick=async()=>{const id=Number($('deviceId').value);if(!id)return notice('Salve o dispositivo antes do teste',true);try{notice((await api('test_connection',{id})).message)}catch(e){notice(e.message,true)}};
$('mapSave').onclick=async()=>{try{await api('mapping_save',{device_id:Number($('mapDevice').value),metric:$('mapMetric').value,fc:Number($('mapFc').value),address:Number($('mapAddress').value),value_type:$('mapType').value,scale_exp:Number($('mapScale').value)});notice('Mapeamento salvo');await loadAdmin()}catch(e){notice(e.message,true)}};
$('ruleSave').onclick=async()=>{try{await api('rule_save',{name:$('ruleName').value,metric:$('ruleMetric').value,comparison:$('ruleComparison').value,threshold:Number($('ruleThreshold').value),hysteresis:Number($('ruleHysteresis').value),hold_seconds:Number($('ruleHold').value),severity:$('ruleSeverity').value});notice('Regra registrada');await loadAdmin()}catch(e){notice(e.message,true)}};
$('saveRetention').onclick=async()=>{try{await api('set_retention',{days:Number($('retentionDays').value)});notice('Retenção atualizada')}catch(e){notice(e.message,true)}};
$('changePassword').onclick=async()=>{try{await api('change_password',{old_password:$('oldPass').value,new_password:$('newPass').value});$('oldPass').value='';$('newPass').value='';notice('Senha atualizada')}catch(e){notice(e.message,true)}};
$('historySearch').onclick=searchHistory;$('eventSearch').onclick=searchEvents;$('exportEvents').onclick=csvExport;$('printHistory').onclick=()=>window.print();$('printEvents').onclick=()=>window.print();
document.querySelectorAll('[data-hours]').forEach(b=>b.onclick=()=>{document.querySelectorAll('[data-hours]').forEach(c=>c.classList.toggle('active',c===b));setPeriod('dateFrom','dateTo',Number(b.dataset.hours));searchHistory()});
async function loadOverview(){try{
 const d=await api('overview');renderOverview(d);
 for(const id of ['historyDevice','eventDevice']) fillDeviceSelect($(id),false);
 if(state.page==='overview')window.uscaRefreshCharts?.(d);
 }catch(e){notice('Não foi possível carregar a supervisão: '+e.message,true)}
}
function init(){setPeriod('dateFrom','dateTo',24);setPeriod('eventFrom','eventTo',24);loadOverview().then(buildMetricPicker);setInterval(()=>{if(!document.hidden)loadOverview()},5000);window.addEventListener('resize',()=>{if(state.historyData&&state.page==='history')drawChart($('historyChart'),state.historyData.rows,state.historyData.metrics,state.historyData.bucket_seconds,state.historyData.poll_seconds)});}
init();

$('generateMikrotikAgent').addEventListener('click',async()=>{
  const btn=$('generateMikrotikAgent'),status=$('agentDiagnostic');
  if(window.location.protocol!=='https:'){status.textContent='Ative HTTPS no domínio Locaweb antes de gerar o agente.';notice('HTTPS obrigatório para o agente MikroTik',true);return}
  if(!confirm('Criar novo token e revogar o agente anterior? O arquivo .rsc contém uma credencial secreta.'))return;
  btn.disabled=true;
  try {
    const response=await api('agent_provision',{endpoint_url:$('agentEndpointUrl').value.trim()});
    const blob=new Blob([response.rsc],{type:'text/plain;charset=utf-8'});
    const link=document.createElement('a'),url=URL.createObjectURL(blob);
    link.href=url;link.download=response.filename||'usca-vale-mikrotik.rsc';
    document.body.appendChild(link);link.click();link.remove();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
    status.textContent='Agente gerado. Importe o .rsc no RouterOS e guarde-o com segurança; ele contém o token. A USCA deve estar habilitada e mapeada.';
    notice('Agente MikroTik gerado e baixado');
  }catch(e){status.textContent=e.message;notice(e.message,true)}finally{btn.disabled=false}
});
$('probeMikrotikModbus').addEventListener('click',async()=>{
  const id=Number($('mapDevice').value),status=$('agentDiagnostic'),btn=$('probeMikrotikModbus');
  if(!id){notice('Selecione o equipamento real no mapeamento',true);return}
  btn.disabled=true;status.textContent='Testando DNS, TCP e FC04 a partir da Locaweb...';
  try {
    const result=await api('agent_probe',{device_id:id});
    status.textContent='DNS: '+(result.dns?'OK':'Falha')+' · TCP: '+(result.tcp?'OK':'Falha')+' · FC04: '+(result.modbus?'OK':'Falha')+'. '+(result.message||result.error||'')+(result.modbus?' '+result.metric+': '+result.value:'');
    notice(result.modbus?'Leitura FC04 confirmada':'Diagnóstico incompleto, verifique os detalhes',!result.modbus);
  }catch(e){status.textContent=e.message;notice(e.message,true)}finally{btn.disabled=false}
});

$('applyMainsProfile').addEventListener('click',async()=>{
  const id=Number($('mapDevice').value),device=state.admin?.devices?.find(d=>Number(d.id)===id);
  const status=$('profileMainsStatus'),btn=$('applyMainsProfile');
  if(!device||Number(device.simulate)===1){status.textContent='Selecione um controlador real, nunca o simulador.';notice(status.textContent,true);return}
  const previous=state.admin.mappings.filter(m=>Number(m.device_id)===id);
  const message='Aplicar o perfil JÁ VALIDADO DEIF AGC150MA 13,8 kV a '+device.name+'?\n'+
    'Serão gravadas 29 grandezas, cinco verificações, regras de 13,8 kV e a classificação MAINS.\n'+
    (previous.length?'Os '+previous.length+' mapeamentos atuais serão substituídos com backup no MySQL.':'Não há mapeamentos existentes neste dispositivo.')+
    '\nAs medições históricas NÃO serão apagadas. Não aplique em geradores.';
  if(!confirm(message))return;
  btn.disabled=true;status.textContent='Aplicando mapa existente com transação e cópia das configurações anteriores...';
  try{
    const answer=await api('mains_profile_apply',{device_id:id,
      profile_id:'deif-agc150-mains-13k8-20261028-r2',confirmation:'CONFIRMO AGC150MA 13.8KV',
      replace:previous.length>0});
    await loadAdmin();
    $('mapDevice').value=String(id);
    status.textContent=(answer.already_applied?'Perfil já estava aplicado. ':'Perfil salvo no MySQL. ')+
      answer.mappings+' grandezas, '+answer.profile+'. '+(answer.warning||'')+
      ' Agora execute o teste DNS/TCP/Modbus FC04 e confira a tensão com o visor da DEIF.';
    notice(answer.already_applied?'Perfil já aplicado':'Mapa DEIF MAINS aplicado; valide a leitura real');
  }catch(e){status.textContent=e.message;notice(e.message,true)}
  finally{btn.disabled=false}
});
