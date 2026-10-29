<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

/**
 * Loads the ALREADY documented local AGC150MA profile, not a newly guessed map.
 * No Modbus write request is used. A separate collector validates the guards on first read.
 */
function mainsLocalProfile(): array {
    $path=dirname(__DIR__).'/profiles/deif-agc150-mains-13k8.json';
    if(!is_file($path)) throw new RuntimeException('Perfil MAINS 13,8 kV não foi publicado na hospedagem.');
    $profile=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($profile) || ($profile['id']??'')!=='deif-agc150-mains-13k8-20261028-r2'
      || ($profile['role']??'')!=='mains' || count($profile['mappings']??[])!==29
      || count($profile['guards']??[])!==5) {
        throw new RuntimeException('Perfil MAINS inválido ou versão diferente da validada localmente.');
    }
    $catalog=metricCatalog();$names=[];
    foreach($profile['mappings'] as $m){
        $key=$m['metric']??'';
        if(!isset($catalog[$key]) || isset($names[$key])
          || !in_array($m['fc']??0,[1,2,3,4],true)
          || !in_array($m['value_type']??'', ['s16','u16','s32','u32','bool','bool_not'],true)
          || !is_int($m['address']??null) || $m['address']<0 || $m['address']>65535
          || !in_array($m['words']??0,[1,2],true)
          || !is_int($m['scale_exp']??null) || abs($m['scale_exp'])>9) {
          throw new RuntimeException('Grandeza inválida no perfil salvo: '.(string)$key);
        }
        $names[$key]=true;
    }
    return $profile;
}

/**
 * Apply only after user explicitly confirms the specific 13.8 kV MAINS device.
 * Snapshot existing mappings, keep historic samples, no controller configuration writes.
 */
function applyMainsLocalProfile(int $deviceId, bool $replace): array {
    if($deviceId<=0) throw new InvalidArgumentException('Selecione um equipamento real.');
    $p=mainsLocalProfile();
    $db=db();
    $gotLock=(int)q("SELECT GET_LOCK('usca_web_trigger_v1',0)")->fetchColumn()===1;
    if(!$gotLock) throw new RuntimeException('Coleta em andamento. Repita a operação após alguns segundos.');
    try{
      $db->beginTransaction();
      $device=q('SELECT id,name,role,simulate FROM devices WHERE id=? FOR UPDATE',[$deviceId])->fetch();
      if(!$device || (int)$device['simulate']!==0 || !in_array($device['role'],['controller','mains'],true)){
          throw new RuntimeException('O perfil MAINS só pode ser aplicado a um equipamento real do tipo Controlador ou Concessionária.');
      }
      $existing=q('SELECT metric,fc,address,words,value_type,scale_exp,enabled FROM mappings WHERE device_id=? ORDER BY metric',[$deviceId])->fetchAll();
      $savedVersion=setting('modbus_profile_'.$deviceId,'');
      $savedId=json_decode($savedVersion,true);
      // An identical mapping is idempotent. Do not erase the last known good cutoff.
      $target=[];
      foreach($p['mappings'] as $m){
          $target[$m['metric']]=[$m['fc'],$m['address'],$m['words'],$m['value_type'],$m['scale_exp'],1];
      }
      $current=[];
      foreach($existing as $m){$current[$m['metric']]=[(int)$m['fc'],(int)$m['address'],(int)$m['words'],$m['value_type'],(int)$m['scale_exp'],(int)$m['enabled']];}
      $same=($current==$target && ($savedId['id']??'')===$p['id'] && $device['role']==='mains');
      if($same){
          $db->commit();
          return ['ok'=>true,'already_applied'=>true,'mappings'=>count($p['mappings']),'profile'=>$p['id']];
      }
      if(count($existing)>0 && !$replace) {
          throw new RuntimeException('O equipamento já possui mapeamentos. Confirme a substituição pelo perfil MAINS 13,8 kV.');
      }
      // Single bounded backup slot per device; historical samples and events are untouched.
      $backup=json_encode(['at'=>utc(),'device_role'=>$device['role'],'mappings'=>$existing,
          'profile'=>$savedId,'cutoff'=>setting('mapping_valid_from_'.$deviceId,'')],JSON_THROW_ON_ERROR);
      setSetting('modbus_mapping_backup_'.$deviceId,$backup);
      q('DELETE FROM mappings WHERE device_id=?',[$deviceId]);
      foreach($p['mappings'] as $m){
          q('INSERT INTO mappings(device_id,metric,fc,address,words,value_type,scale_exp,enabled) VALUES(?,?,?,?,?,?,?,1)',
             [$deviceId,$m['metric'],$m['fc'],$m['address'],$m['words'],$m['value_type'],$m['scale_exp']]);
      }
      q("UPDATE devices SET role='mains',last_error=NULL WHERE id=?",[$deviceId]);
      setSetting('modbus_profile_'.$deviceId,json_encode([
          'id'=>$p['id'],'guards'=>$p['guards'],'nominal_voltage_v'=>$p['nominal_voltage_v']
      ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
      setSetting('mapping_valid_from_'.$deviceId,utc());
      // The generic 380V thresholds installed by the wizard are wrong at 13.8 kV.
      // Do not change shared rules if other active real equipment may rely on them.
      $other=(int)q('SELECT COUNT(*) FROM devices WHERE id<>? AND simulate=0 AND enabled=1',[$deviceId])->fetchColumn();
      if($other===0){
          q("UPDATE rules SET enabled=0 WHERE metric='voltage_l12' AND ((comparison='below' AND threshold=342) OR (comparison='above' AND threshold=418))");
      }
      // Add/update MAINS-specific limits, never modify protection settings of the DEIF.
      foreach(['below'=>(float)$p['voltage_rules']['low'],'above'=>(float)$p['voltage_rules']['high']] as $comparison=>$threshold){
          $ruleName='AGC150 MAINS 13k8 / '.$comparison;
          $oldId=q('SELECT id FROM rules WHERE name=? LIMIT 1',[$ruleName])->fetchColumn();
          if($oldId){
              q('UPDATE rules SET metric=?,comparison=?,threshold=?,hysteresis=?,hold_seconds=5,severity=?,enabled=1 WHERE id=?',
                ['mains_voltage_l12',$comparison,$threshold,(float)$p['voltage_rules']['hysteresis'],'critical',$oldId]);
          }else{
              q('INSERT INTO rules(name,metric,comparison,threshold,hysteresis,hold_seconds,severity,enabled) VALUES(?,?,?,?,?,5,?,1)',
                [$ruleName,'mains_voltage_l12',$comparison,$threshold,(float)$p['voltage_rules']['hysteresis'],'critical']);
          }
      }
      $db->commit();
      return ['ok'=>true,'already_applied'=>false,'mappings'=>count($p['mappings']),'profile'=>$p['id'],
          'device'=>$deviceId,'warning'=>$other>0
             ?'Revise regras genéricas de 380 V, compartilhadas com outros dispositivos.'
             :'Limites locais ajustados para 13,8 kV. Verifique a leitura real antes de confiar nos alarmes.'];
    }catch(Throwable $e){
        if($db->inTransaction()) $db->rollBack();
        throw $e;
    }finally{
        q("SELECT RELEASE_LOCK('usca_web_trigger_v1')");
    }
}
