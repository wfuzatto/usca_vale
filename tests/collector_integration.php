<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Collector.php';

function check(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function runOneDevice(Collector $collector,int $deviceId): array {
    q('UPDATE collector_state SET next_due_at=NULL WHERE device_id=?',[$deviceId]);
    foreach($collector->tick() as $result) if((int)$result['device']===$deviceId) return $result;
    throw new RuntimeException('Dispositivo temporário não foi processado');
}

// This temporary device uses an arbitrary address only to exercise local TCP
// failure handling. It never represents a DEIF AGC 150 register.
$probe=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if(!$probe) throw new RuntimeException('Não foi possível reservar uma porta local: '.$error);
$addr=stream_socket_get_name($probe,false);$port=(int)substr(strrchr($addr,':'),1);fclose($probe);
$deviceId=0;
try {
    q('INSERT INTO devices(name,role,host,port,unit_id,enabled,simulate,poll_seconds) VALUES(?,?,?,?,1,1,0,1)',[
      'TESTE local Modbus '.bin2hex(random_bytes(4)),'controller','127.0.0.1',$port,
    ]);
    $deviceId=(int)db()->lastInsertId();
    q('INSERT INTO mappings(device_id,metric,fc,address,words,value_type,scale_exp) VALUES(?,\'power_kw\',4,1234,1,\'u16\',0)',[$deviceId]);
    $collector=new Collector();

    $first=runOneDevice($collector,$deviceId);
    check(!$first['ok'],'A falha TCP inicial deveria ser registrada');
    $second=runOneDevice($collector,$deviceId);
    check(!$second['ok'],'A segunda falha TCP deveria continuar registrada');
    $event=q('SELECT id FROM events WHERE device_id=? AND event_key=\'communication\' AND ended_at IS NULL',[$deviceId])->fetch();
    check((bool)$event,'O evento de perda de comunicação deveria abrir após duas falhas');
    check((int)q('SELECT COUNT(*) FROM comm_logs WHERE device_id=? AND success=0',[$deviceId])->fetchColumn()>=1,'A falha deveria entrar no log de comunicação');

    // Switch only this disposable device to simulated mode to prove that the next
    // valid tick closes the event and clears last_error. This is not physical IO.
    q('UPDATE devices SET simulate=1 WHERE id=?',[$deviceId]);
    $recovered=runOneDevice($collector,$deviceId);
    check($recovered['ok'],'A coleta deveria se recuperar no próximo tick válido');
    $sample=q('SELECT simulated,values_json FROM samples WHERE device_id=? ORDER BY id DESC LIMIT 1',[$deviceId])->fetch();
    check($sample && (int)$sample['simulated']===1,'A amostra de recuperação deve permanecer explicitamente marcada como simulada');
    $values=json_decode($sample['values_json'],true);
    check((float)$values['power_kw']>0,'A recuperação deveria persistir valores válidos do simulador');
    check((float)$values['mains_voltage_l12']>350 && (float)$values['mains_power_kw']>0,'O cartão da rede precisa receber aliases explícitos na simulação normal');
    check((int)$values['tie_breaker']===1 && (float)$values['bus_voltage_l12']>350 && (float)$values['bus_frequency']===60.0,'O unifilar simulado precisa de TB e barramento confirmados');
    $event=q('SELECT ended_at FROM events WHERE device_id=? AND event_key=\'communication\' ORDER BY id DESC LIMIT 1',[$deviceId])->fetch();
    check($event && $event['ended_at']!==null,'O evento de comunicação deveria encerrar após recuperação');
    check((int)q('SELECT COUNT(*) FROM comm_logs WHERE device_id=? AND success=1',[$deviceId])->fetchColumn()>=1,'A recuperação deveria entrar no log de comunicação');
    check(q('SELECT last_error FROM devices WHERE id=?',[$deviceId])->fetchColumn()===null,'O último erro deveria ser limpo após a recuperação');
    echo "OK coletor: perda TCP local, log de falha, evento aberto e encerramento após tick válido simulado\n";
} finally {
    if($deviceId){q('DELETE FROM devices WHERE id=?',[$deviceId]);q('DELETE FROM app_settings WHERE name=?',['sim_epoch_'.$deviceId]);}
}
