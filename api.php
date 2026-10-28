<?php
declare(strict_types=1);
require_once __DIR__.'/src/bootstrap.php';
require_once __DIR__.'/src/ModbusClient.php';
requireAuth();
$action=(string)($_GET['action']??'overview');
try {
 if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf();
    $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('JSON inválido');
    switch($action) {
    case 'topology_save':
        $labels=$input['labels']??null;
        if (!is_array($labels)) throw new InvalidArgumentException('Rótulos de topologia inválidos');
        $safe=[];
        foreach (['mains','bus','loads'] as $key){
            $v=$labels[$key]??null;
            if (!is_string($v)) throw new InvalidArgumentException('Rótulo inválido: '.$key);
            $v=cleanStr(strip_tags($v),50);
            if($v==='') throw new InvalidArgumentException('Rótulo obrigatório: '.$key);
            $safe[$key]=$v;
        }
        setSetting('topology_labels',json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        jsonOut(['ok'=>true,'labels'=>$safe]);
    case 'device_save':
        $id=(int)($input['id']??0);$name=cleanStr($input['name']??'Controlador');$host=cleanStr($input['host']??'',255);
        $port=(int)($input['port']??502);$unit=(int)($input['unit_id']??1);$poll=(int)($input['poll_seconds']??5);
        $role=(string)($input['role']??'controller');$enabled=empty($input['enabled'])?0:1;
        $sim=!empty($input['simulate'])?1:0;
        if (!$name || !in_array($role,['mains','generator','controller','simulator'],true)) throw new InvalidArgumentException('Nome/tipo inválido');
        if ($port<1||$port>65535||$unit<0||$unit>247||$poll<1||$poll>300) throw new InvalidArgumentException('Porta, Unit ID ou intervalo inválido');
        if (!$sim && $host!=='' && !filter_var($host,FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,252}$/D',$host)) throw new InvalidArgumentException('Host inválido');
        if (!$sim && $enabled && !$host) throw new InvalidArgumentException('Endereço USCA obrigatório para habilitar');
        $rated=isset($input['rated_kva']) && $input['rated_kva']!==''?max(0,(float)$input['rated_kva']):null;
        if ($id>0) {
            $existing=q('SELECT simulate FROM devices WHERE id=?',[$id])->fetchColumn();
            if ($existing===false) throw new InvalidArgumentException('Equipamento não encontrado');
            if ((int)$existing!==$sim && q('SELECT id FROM samples WHERE device_id=? LIMIT 1',[$id])->fetchColumn()) throw new InvalidArgumentException('Crie um novo equipamento ao alternar real/simulado para não misturar históricos');
            q('UPDATE devices SET name=?,role=?,host=?,port=?,unit_id=?,poll_seconds=?,rated_kva=?,enabled=?,simulate=? WHERE id=?',[$name,$role,$host,$port,$unit,$poll,$rated,$enabled,$sim,$id]);
        } else {
            q('INSERT INTO devices(name,role,host,port,unit_id,poll_seconds,rated_kva,enabled,simulate) VALUES(?,?,?,?,?,?,?,?,?)',[$name,$role,$host,$port,$unit,$poll,$rated,$enabled,$sim]);
            $id=(int)db()->lastInsertId();
        }
        jsonOut(['ok'=>true,'id'=>$id]);
    case 'mapping_save':
        $device=(int)($input['device_id']??0);$metric=(string)($input['metric']??'');
        $fc=(int)($input['fc']??4);$addr=(int)($input['address']??-1);$type=(string)($input['value_type']??'u16');$scale=(int)($input['scale_exp']??0);
        if (!array_key_exists($metric,metricCatalog())||!in_array($fc,[1,2,3,4],true)||!in_array($type,['u16','s16','u32','s32','bool'],true)||$addr<0||$addr>65534||$scale<-9||$scale>9) throw new InvalidArgumentException('Registro inválido');
        if (in_array($fc,[1,2],true) && $type!=='bool') throw new InvalidArgumentException('FC01/FC02 requer bool');
        $words=in_array($type,['u32','s32'],true)?2:1;
        if (!q('SELECT id FROM devices WHERE id=?',[$device])->fetchColumn()) throw new InvalidArgumentException('Dispositivo inexistente');
        q('INSERT INTO mappings(device_id,metric,fc,address,words,value_type,scale_exp) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE fc=VALUES(fc),address=VALUES(address),words=VALUES(words),value_type=VALUES(value_type),scale_exp=VALUES(scale_exp),enabled=1',[$device,$metric,$fc,$addr,$words,$type,$scale]);
        jsonOut(['ok'=>true]);
    case 'mapping_delete':
        q('DELETE FROM mappings WHERE id=?',[(int)($input['id']??0)]);jsonOut(['ok'=>true]);
    case 'rule_save':
        $metric=(string)($input['metric']??'');$cmp=(string)($input['comparison']??'below');$severity=(string)($input['severity']??'warning');
        if (!isset(metricCatalog()[$metric])||!in_array($cmp,['below','above'],true)||!in_array($severity,['warning','critical'],true)) throw new InvalidArgumentException('Regra inválida');
        $threshold=(float)($input['threshold']??0);$h=(float)($input['hysteresis']??0);$hold=(int)($input['hold_seconds']??5);
        if (!is_finite($threshold)||$h<0||$h>100000||$hold<0||$hold>3600) throw new InvalidArgumentException('Limite/histerese inválido');
        $id=(int)($input['id']??0);$name=cleanStr($input['name']??'Regra');
        if($id) q('UPDATE rules SET name=?,metric=?,comparison=?,threshold=?,hysteresis=?,hold_seconds=?,severity=?,enabled=? WHERE id=?',[$name,$metric,$cmp,$threshold,$h,$hold,$severity,!empty($input['enabled'])?1:0,$id]);
        else q('INSERT INTO rules(name,metric,comparison,threshold,hysteresis,hold_seconds,severity,enabled) VALUES(?,?,?,?,?,?,?,?)',[$name,$metric,$cmp,$threshold,$h,$hold,$severity,1]);
        jsonOut(['ok'=>true]);
    case 'ack_event':
        q('UPDATE events SET acknowledged_at=UTC_TIMESTAMP(),acknowledged_by=? WHERE id=?',[(int)$_SESSION['uid'],(int)($input['id']??0)]);jsonOut(['ok'=>true]);
    case 'set_retention':
        $days=(int)($input['days']??365);if($days<30||$days>3650) throw new InvalidArgumentException('Retenção: 30 a 3650 dias');
        setSetting('retention_days',(string)$days);jsonOut(['ok'=>true]);
    case 'change_password':
        $new=(string)($input['new_password']??'');$old=(string)($input['old_password']??'');
        if(strlen($new)<12) throw new InvalidArgumentException('A senha precisa de 12 caracteres');
        $oldHash=q('SELECT password_hash FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetchColumn();
        if(!password_verify($old,(string)$oldHash)) throw new InvalidArgumentException('Senha atual incorreta');
        q('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),(int)$_SESSION['uid']]);jsonOut(['ok'=>true]);
    case 'test_connection':
        $device=q('SELECT * FROM devices WHERE id=?',[(int)($input['id']??0)])->fetch();
        if(!$device || (int)$device['simulate']) throw new InvalidArgumentException('Selecione dispositivo real');
        $client=new ModbusClient((string)$device['host'],(int)$device['port']);
        jsonOut(['ok'=>true,'message'=>'TCP conectado. Isso não confirma registradores, Unit ID nem escalas.']);
    default: jsonOut(['error'=>'Ação não permitida'],404);
    }
 }
 switch ($action) {
 case 'topology':
    $labels=json_decode(setting('topology_labels','{}'),true);
    jsonOut(['labels'=>is_array($labels)?$labels:[]]);
 case 'overview':
    $devices=q('SELECT d.*,s.failure_count FROM devices d LEFT JOIN collector_state s ON s.device_id=d.id ORDER BY d.id')->fetchAll();
    $latest=q('SELECT x.device_id,x.taken_at,x.simulated,x.values_json FROM samples x JOIN (SELECT device_id,MAX(id) id FROM samples GROUP BY device_id) m ON m.id=x.id')->fetchAll();
    $lookup=[];foreach($latest as $r) $lookup[(int)$r['device_id']]=['timestamp'=>$r['taken_at'].'Z','simulated'=>(bool)$r['simulated'],'values'=>json_decode($r['values_json'],true)];
    foreach($devices as &$d) {
      $d['latest']=$lookup[(int)$d['id']]??null;
      $d['stale']= !$d['last_seen'] || time()-strtotime($d['last_seen'])>max(15,(int)$d['poll_seconds']*3);
      unset($d['host']); // equipment credentials/network restricted to administration endpoint
    }unset($d);
    $active=q('SELECT e.*,d.name device FROM events e JOIN devices d ON d.id=e.device_id WHERE e.ended_at IS NULL ORDER BY e.started_at DESC LIMIT 50')->fetchAll();
    $recent=q('SELECT e.*,d.name device FROM events e JOIN devices d ON d.id=e.device_id ORDER BY e.id DESC LIMIT 15')->fetchAll();
    jsonOut(['devices'=>$devices,'active'=>$active,'recent'=>$recent,'catalog'=>metricCatalog(),'server_time'=>gmdate('c')]);
 case 'devices':
    jsonOut(['devices'=>q('SELECT * FROM devices ORDER BY id')->fetchAll(), 'mappings'=>q('SELECT m.* FROM mappings m ORDER BY device_id,metric')->fetchAll(), 'rules'=>q('SELECT * FROM rules ORDER BY id')->fetchAll(),'catalog'=>metricCatalog(),'retention_days'=>(int)setting('retention_days','365')]);
 case 'history':
    $device=(int)($_GET['device']??0);$from=mustDate($_GET['from']??null,gmdate('Y-m-d H:i:s',time()-86400));$to=mustDate($_GET['to']??null,gmdate('Y-m-d H:i:s'));
    if(strtotime($to)<=strtotime($from) || (strtotime($to)-strtotime($from))>3660*86400) throw new InvalidArgumentException('Intervalo inválido (máximo 10 anos)');
    $list=explode(',',(string)($_GET['metrics']??'voltage_l12,frequency,power_kw'));
    $metrics=array_values(array_filter(array_unique($list),fn($k)=>isset(metricCatalog()[$k])));
    if (!$metrics || count($metrics)>12) throw new InvalidArgumentException('Escolha entre 1 e 12 grandezas');
    $bucket=max(1,(int)ceil((strtotime($to)-strtotime($from))/480));
    $cols=[]; foreach($metrics as $m) $cols[]='AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(values_json,\'$."'.$m.'"\')) AS DECIMAL(20,5))) AS `'.$m.'`';
    $filter=$device>0?' AND device_id=?':'';
    $params=[$from,$to];if($device>0) $params[]=$device;
    $sql='SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(taken_at)/'.$bucket.')*'.$bucket.') AS bucket,'.implode(',',$cols).',COUNT(*) AS readings FROM samples WHERE taken_at>=? AND taken_at<=?'.$filter.' GROUP BY bucket ORDER BY bucket LIMIT 550';
    $rows=q($sql,$params)->fetchAll();
    foreach($rows as &$r) $r['bucket'].='Z';unset($r);
    jsonOut(['rows'=>$rows,'metrics'=>$metrics,'bucket_seconds'=>$bucket,'from'=>$from.'Z','to'=>$to.'Z','count'=>count($rows)]);
 case 'events':
    $from=mustDate($_GET['from']??null,gmdate('Y-m-d H:i:s',time()-86400));$to=mustDate($_GET['to']??null,gmdate('Y-m-d H:i:s'));
    $device=(int)($_GET['device']??0);$severity=(string)($_GET['severity']??'');
    $where='e.started_at<=? AND (e.ended_at IS NULL OR e.ended_at>=?)';$args=[$to,$from];
    if($device>0){$where.=' AND e.device_id=?';$args[]=$device;}
    if(in_array($severity,['info','warning','critical'],true)){$where.=' AND e.severity=?';$args[]=$severity;}
    $rows=q('SELECT e.*,d.name device FROM events e JOIN devices d ON d.id=e.device_id WHERE '.$where.' ORDER BY e.started_at DESC LIMIT 1000',$args)->fetchAll();
    jsonOut(['rows'=>$rows,'truncated'=>count($rows)===1000]);
 case 'events_csv':
    $from=mustDate($_GET['from']??null,gmdate('Y-m-d H:i:s',time()-86400));$to=mustDate($_GET['to']??null,gmdate('Y-m-d H:i:s'));
    $device=(int)($_GET['device']??0);$where='e.started_at<=? AND (e.ended_at IS NULL OR e.ended_at>=?)';$args=[$to,$from];if($device>0){$where.=' AND e.device_id=?';$args[]=$device;}
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="usca_eventos.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Dispositivo','Categoria','Severidade','Início UTC','Fim UTC','Mínimo','Máximo','Mensagem','Origem'],';');
    foreach(q('SELECT e.*,d.name device FROM events e JOIN devices d ON d.id=e.device_id WHERE '.$where.' ORDER BY e.started_at DESC LIMIT 100000',$args) as $e) {
      $cells=[$e['device'],$e['category'],$e['severity'],$e['started_at'],$e['ended_at'],$e['minimum'],$e['maximum'],$e['message'],$e['source']];
      // Protect spreadsheet users from formula injection.
      $cells=array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-]/',$v)?"'".$v:$v,$cells);fputcsv($out,$cells,';');
    }exit;
 default: jsonOut(['error'=>'Ação desconhecida'],404);
 }
} catch(Throwable $e){ jsonOut(['error'=>$e->getMessage()],400); }
