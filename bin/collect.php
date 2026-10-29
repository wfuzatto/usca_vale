<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Collector.php';
// Keep the lock in the private project runtime directory so SYSTEM and logged-in
// users share the same lock on Windows (their temporary folders are different).
$lockDir=dirname(__DIR__).'/var';
if (!is_dir($lockDir) && !mkdir($lockDir,0700,true) && !is_dir($lockDir)) throw new RuntimeException('Não foi possível criar diretório do lock');
$lockFile=$lockDir.'/collector.lock';
$lock=fopen($lockFile,'c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { fwrite(STDERR,"Coletor já ativo\n"); exit(2); }
$once=in_array('--once',$argv,true);
$phase=null;
foreach($argv as $arg) if (str_starts_with($arg,'--phase=')) $phase=substr($arg,8);
try {
    $collector=new Collector(); $i=0;
    do {
        $out=$collector->tick($phase);
        if ($once) echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
        if ((++$i)%3600===0) $collector->prune();
        if ($once) break;
        sleep(1);
    } while (true);
} catch(Throwable $e) { fwrite(STDERR,'Coletor: '.$e->getMessage()."\n"); exit(1); }
