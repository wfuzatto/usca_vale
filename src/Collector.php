<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/ModbusClient.php';
final class Collector {
    private PDO $db;
    public function __construct(){ $this->db=db(); }
    public function tick(?string $forcedPhase=null): array {
        $now=utc(); $results=[];
        $devices=q('SELECT d.* FROM devices d LEFT JOIN collector_state s ON s.device_id=d.id WHERE d.enabled=1 AND (s.next_due_at IS NULL OR s.next_due_at<=?) ORDER BY d.id',[$now])->fetchAll();
        foreach ($devices as $d) {
            $id=(int)$d['id'];
            // Lease is not a high availability lock: run only ONE collector service at once.
            q('INSERT INTO collector_state(device_id,next_due_at,last_attempt_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE next_due_at=VALUES(next_due_at),last_attempt_at=VALUES(last_attempt_at)',
              [$id,gmdate('Y-m-d H:i:s',time()+max(1,(int)$d['poll_seconds'])), $now]);
            try {
                $data=((int)$d['simulate']===1) ? $this->simulate($id,$forcedPhase) : $this->poll($d);
                if (!$data) throw new RuntimeException('Nenhum valor configurado/lido (revise mapeamento)');
                q('INSERT INTO samples(device_id,taken_at,simulated,values_json) VALUES(?,?,?,?)',[$id,$now,(int)$d['simulate'],json_encode($data,JSON_THROW_ON_ERROR)]);
                $wasError=!empty($d['last_error']);
                q('UPDATE devices SET last_seen=?,last_error=NULL WHERE id=?',[$now,$id]);
                q('UPDATE collector_state SET failure_count=0 WHERE device_id=?',[$id]);
                $this->closeEvent($id,'communication',$now);
                if ($wasError) $this->log($id,true,'Conexão recuperada');
                $this->applyRules($d,$now,$data);
                $this->transitions($d,$now,$data);
                $results[]=['device'=>$id,'ok'=>true,'metrics'=>count(array_filter(array_keys($data),fn($key)=>!str_starts_with((string)$key,'_')))];
            } catch(Throwable $e) {
                $msg=mbOrSubstr($e->getMessage(),240);
                q('UPDATE devices SET last_error=? WHERE id=?',[$msg,$id]);
                q('UPDATE collector_state SET failure_count=failure_count+1 WHERE device_id=?',[$id]);
                $fails=(int)q('SELECT failure_count FROM collector_state WHERE device_id=?',[$id])->fetchColumn();
                if ($fails===1 || $fails%12===0) $this->log($id,false,$msg);
                if ($fails>=2) $this->openEvent($id,'communication','system','critical',$now,'Comunicação indisponível: '.$msg,null);
                $results[]=['device'=>$id,'ok'=>false,'error'=>$msg];
            }
        }
        return $results;
    }
    private function log(int $id,bool $ok,string $msg): void {
        q('INSERT INTO comm_logs(device_id,at_utc,success,detail) VALUES(?,?,?,?)',[$id,utc(),$ok?1:0,$msg]);
    }
    private function poll(array $device): array {
        $id=(int)$device['id'];
        $maps=q('SELECT * FROM mappings WHERE device_id=? AND enabled=1 ORDER BY fc,address',[$id])->fetchAll();
        if (!$maps) throw new RuntimeException('Mapeamento vazio: configure os endereços oficiais do controlador');
        $client=new ModbusClient((string)$device['host'],(int)$device['port']);
        $cache=[];
        $read=function(int $fc,int $address,int $count) use($client,$device,&$cache): array {
            $key=$fc.':'.$address.':'.$count;
            return $cache[$key]??= $client->read((int)$device['unit_id'],$fc,$address,$count);
        };
        $profile=json_decode(setting('modbus_profile_'.$id,'{}'),true);
        if (!is_array($profile)) throw new RuntimeException('Perfil Modbus inválido');
        foreach($profile['guards']??[] as $guard) {
            $raw=$read((int)$guard['fc'],(int)$guard['address'],(int)$guard['words']);
            if ($raw!==$guard['expected']) throw new RuntimeException('Configuração DEIF alterada: '.($guard['label']??$guard['address']).'. Revise o perfil Modbus antes de coletar.');
        }
        $out=[];$rawValues=[];
        foreach($maps as $m) {
            $fc=(int)$m['fc'];$count=(int)$m['words'];
            if (!in_array($fc,[1,2,3,4],true) || $count<1 || $count>2) throw new RuntimeException('Mapeamento inválido');
            $raw=$read($fc,(int)$m['address'],$count);
            $out[$m['metric']]=ModbusClient::decode($raw,$m['value_type'],(int)$m['scale_exp']);
            $rawValues[$m['metric']]=['fc'=>$fc,'address'=>(int)$m['address'],'words'=>$raw,'scale_exp'=>(int)$m['scale_exp'],'value_type'=>$m['value_type']];
        }
        // Controller alarm list (documented FC04 / addresses 28000..28099).
        // If not accessible on this firmware, do not invent alarms.
        try {
            $alarms=$client->read((int)$device['unit_id'],4,28000,100);
            $alarms=array_values(array_unique(array_filter(array_map('intval',$alarms),fn($v)=>$v>0)));
            $this->syncControllerAlarms($id,$alarms);
        } catch(Throwable $ignored) { /* alarm list unavailable; no alarm-state inference */ }
        if (!empty($profile['id'])) $out['_provenance']=['mapping_version'=>$profile['id'],'raw'=>$rawValues];
        return $out;
    }
    private function syncControllerAlarms(int $id,array $alarms): void {
        $now=utc(); $keys=[];
        foreach($alarms as $address) {
            $key='deif_'.(int)$address; $keys[]=$key;
            $this->openEvent($id,$key,'controller_alarm','critical',$now,'Alarme DEIF, endereço '.$address.' (ver identificação no USW)',null,'controller');
        }
        $existing=q("SELECT event_key FROM events WHERE device_id=? AND category='controller_alarm' AND ended_at IS NULL",[$id])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($existing as $k) if (!in_array($k,$keys,true)) $this->closeEvent($id,(string)$k,$now);
    }
    private function simulate(int $id, ?string $phase): array {
        $epoch=(int)setting('sim_epoch_'. $id,'0');
        if (!$epoch) { $epoch=time(); setSetting('sim_epoch_'.$id,(string)$epoch); }
        $cycle=($phase===null) ? (time()-$epoch)%300 : -1;
        $mode=$phase ?? ($cycle<70?'normal':($cycle<105?'sag':($cycle<140?'outage':($cycle<155?'starting':($cycle<210?'generator':($cycle<240?'restore':'normal'))))));
        $mains=!in_array($mode,['outage','starting','generator'],true);
        $running=in_array($mode,['starting','generator','restore'],true);
        $gensupply=in_array($mode,['generator','restore'],true);
        $fault=$mode==='startfail';
        $v=match($mode){'sag'=>320.0,'outage','starting','generator','startfail'=>0.0,default=>380.0};
        $freq=$mains?60.0:0.0;
        $t=time();$j=sin($t/6)*1.6;
        return [
            'mains_available'=>$mains?1:0,'source_generator'=>$gensupply?1:0,
            'generator_running'=>$running?1:0,'generator_fault'=>$fault?1:0,
            'mains_breaker'=>$mains&&!$gensupply?1:0,'generator_breaker'=>$gensupply?1:0,
            'tie_breaker'=>$mains&&!$gensupply?1:0,
            'mains_voltage_l12'=>$mains?round($v+$j,1):0,'mains_voltage_l23'=>$mains?round($v-$j*.7,1):0,'mains_voltage_l31'=>$mains?round($v+$j*.3,1):0,
            'mains_power_kw'=>$mains&&!$gensupply?330:0,'mains_power_factor'=>$mains&&!$gensupply?0.92:0,
            'bus_voltage_l12'=>$gensupply?380:($mains?round($v+$j,1):0),'bus_voltage_l23'=>$gensupply?380:($mains?round($v-$j*.7,1):0),'bus_voltage_l31'=>$gensupply?380:($mains?round($v+$j*.3,1):0),
            'bus_frequency'=>$gensupply?59.9:$freq,
            'voltage_l12'=>round($v+$j,1),'voltage_l23'=>round($v-$j*.7,1),'voltage_l31'=>round($v+$j*.3,1),
            'voltage_l1n'=>round($v/1.732,1),'voltage_l2n'=>round($v/1.732,1),'voltage_l3n'=>round($v/1.732,1),
            'frequency'=>$gensupply?59.9:$freq, 'mains_frequency'=>$freq, 'generator_frequency'=>$running?60:0,
            'current_l1'=>$gensupply?540:($mains?550:0), 'current_l2'=>$gensupply?525:($mains?538:0), 'current_l3'=>$gensupply?530:($mains?542:0),
            'power_kw'=>($mains||$gensupply)?330:0,'power_kva'=>($mains||$gensupply)?360:0,'power_kvar'=>($mains||$gensupply)?144:0,
            'power_factor'=>0.92, 'energy_kwh'=>round(300000 + (time()-$epoch)*0.09,2),
            'load_pct'=>$gensupply?55:0, 'engine_rpm'=>$running?1800:0, 'engine_hours'=>1250+max(0,time()-$epoch)/3600,
            'engine_temp'=>$running?85:34,'oil_pressure'=>$running?4.2:0.1,'battery_voltage'=>25.1,'starts'=>120,
        ];
    }
    private function applyRules(array $device,string $at,array $values): void {
        $id=(int)$device['id'];
        foreach(q('SELECT * FROM rules WHERE enabled=1')->fetchAll() as $r) {
            $metric=$r['metric']; if (!array_key_exists($metric,$values) || !is_numeric($values[$metric])) continue;
            $val=(float)$values[$metric]; $lim=(float)$r['threshold']; $h=(float)$r['hysteresis'];
            $bad=$r['comparison']==='below' ? $val<$lim : $val>$lim;
            $recovered=$r['comparison']==='below' ? $val>=$lim+$h : $val<=$lim-$h;
            $state=q('SELECT * FROM rule_state WHERE device_id=? AND rule_id=?',[$id,(int)$r['id']])->fetch();
            $key='rule_'.(int)$r['id'];
            if ($bad) {
                if (!$state) {
                    q('INSERT INTO rule_state(device_id,rule_id,pending_at) VALUES(?,?,?)',[$id,$r['id'],$at]);
                    $pending=$at;
                } else $pending=$state['pending_at']??$at;
                $elapsed=(strtotime($at)-strtotime($pending));
                if ($elapsed >= (int)$r['hold_seconds']) {
                    $this->openEvent($id,$key,'threshold',(string)$r['severity'],$pending,(string)$r['name'], $val,((int)$device['simulate'])?'simulation':'polling');
                }
            } elseif ($recovered) {
                $this->closeEvent($id,$key,$at);
                q('DELETE FROM rule_state WHERE device_id=? AND rule_id=?',[$id,$r['id']]);
            }
        }
    }
    private function transitions(array $device,string $at,array $values): void {
        $id=(int)$device['id'];
        $last=q('SELECT values_json FROM samples WHERE device_id=? ORDER BY id DESC LIMIT 1 OFFSET 1',[$id])->fetchColumn();
        if (!$last) return;
        $prev=json_decode($last,true)?:[];
        $labels=['mains_available'=>['Concessionária indisponível','Concessionária restabelecida'],
          'generator_running'=>['Gerador parou','Gerador entrou em funcionamento'],
          'source_generator'=>['Carga deixou o gerador','Carga transferida para gerador'],
          'generator_fault'=>['Falha de gerador cessou','Falha de gerador detectada'],
          'mains_breaker'=>['Disjuntor da rede aberto','Disjuntor da rede fechado'],
          'generator_breaker'=>['Disjuntor do gerador aberto','Disjuntor do gerador fechado']];
        foreach($labels as $key=>$names) {
            if (!isset($prev[$key],$values[$key]) || (int)$prev[$key]===(int)$values[$key]) continue;
            q('INSERT INTO events(device_id,event_key,category,severity,started_at,ended_at,message,source) VALUES(?,?,?,?,?,?,?,?)',
                [$id,'transition_'.$key,'transition','info',$at,$at,$names[(int)(!!$values[$key])],((int)$device['simulate'])?'simulation':'polling']);
        }
    }
    private function openEvent(int $id,string $key,string $cat,string $severity,string $at,string $message,?float $value,string $source='system'): void {
        $e=q('SELECT id FROM events WHERE device_id=? AND event_key=? AND ended_at IS NULL LIMIT 1',[$id,$key])->fetchColumn();
        if ($e) {
            if ($value!==null) q('UPDATE events SET observed=?,minimum=LEAST(COALESCE(minimum,?),?),maximum=GREATEST(COALESCE(maximum,?),?) WHERE id=?',[$value,$value,$value,$value,$value,$e]);
        } else q('INSERT INTO events(device_id,event_key,category,severity,started_at,minimum,maximum,observed,message,source) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [$id,$key,$cat,$severity,$at,$value,$value,$value,$message,$source]);
    }
    private function closeEvent(int $id,string $key,string $at): void {
        q('UPDATE events SET ended_at=? WHERE device_id=? AND event_key=? AND ended_at IS NULL',[$at,$id,$key]);
    }
    public function prune(): void {
        $days=max(30,min(3650,(int)setting('retention_days','365')));
        // Aggregation/archival can be added if storage demands it; by default RAW samples retained 365 days.
        q('DELETE FROM samples WHERE taken_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.$days.' DAY) LIMIT 10000');
        q('DELETE FROM comm_logs WHERE at_utc < DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.$days.' DAY) LIMIT 10000');
    }
}
