<?php
declare(strict_types=1);
/** Called by the MikroTik scheduler. Modbus read-only, on the Locaweb server. */
require_once __DIR__.'/src/bootstrap.php';
require_once __DIR__.'/src/TriggerAgent.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    header('Allow: POST'); jsonOut(['ok'=>false,'error'=>'Método não permitido'],405);
}
if (!triggerHostIsTls($_SERVER)) jsonOut(['ok'=>false,'error'=>'HTTPS obrigatório'],403);
$sent=(string)($_SERVER['HTTP_X_COLLECTOR_TOKEN']??'');
if (!triggerTokenValid($sent,setting('collector_trigger_sha256',''))) {
    // No token contents or network target are ever logged.
    jsonOut(['ok'=>false,'error'=>'Agente não autorizado'],401);
}
$locked=false;
try {
    // MySQL advisory lock prevents overlapping HTTP requests and duplicate writes.
    $lock=q("SELECT GET_LOCK('usca_web_trigger_v1',0)")->fetchColumn();
    if ((int)$lock!==1) jsonOut(['ok'=>false,'error'=>'Coleta anterior ainda em execução'],409);
    $locked=true;
    $last=(int)setting('collector_trigger_last_start','0');
    if ($last > 0 && time()-$last < 20) jsonOut(['ok'=>true,'skipped'=>true,'reason'=>'intervalo mínimo']);
    setSetting('collector_trigger_last_start',(string)time());
    require_once __DIR__.'/src/Collector.php';
    // Never collect simulator data on a public trigger.
    $rows=(new Collector())->tick(null,null,true);
    $attempts=count($rows);
    $success=count(array_filter($rows,static fn($r)=>($r['ok']??false)===true));
    $failure=$attempts-$success;
    // No IP or internal error detail in externally visible responses.
    $status=$failure>0 && $success===0 ? 502 : 200;
    jsonOut(['ok'=>$failure===0,'attempted'=>$attempts,'success'=>$success,'failed'=>$failure],$status);
} catch(Throwable $e) {
    error_log('USCA trigger: falha operacional (dados sensíveis omitidos)');
    jsonOut(['ok'=>false,'error'=>'Falha na coleta; consulte eventos e logs do servidor'],503);
} finally {
    if ($locked) {try {q("SELECT RELEASE_LOCK('usca_web_trigger_v1')");}catch(Throwable $ignored){}}
}
