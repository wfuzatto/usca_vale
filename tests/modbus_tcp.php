<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/ModbusClient.php';
if (($argv[1]??'')==='--server') {
    $server=stream_socket_server('tcp://127.0.0.1:'.(int)($argv[2]??0),$errno,$error);
    if (!$server) {fwrite(STDERR,$error."\n");exit(4);}
    fwrite(STDOUT,"READY\n");fflush(STDOUT);
    $conn=stream_socket_accept($server,10);
    if (!$conn) exit(5);
    $request='';while(strlen($request)<12&&!feof($conn)){$part=fread($conn,12-strlen($request));if($part===false||$part==='')break;$request.=$part;}
    if(strlen($request)!==12)exit(6); // MBAP 7 + PDU 5
    $header=unpack('ntx/nproto/nlen/Cunit/Cfc/naddr/ncount',$request);
    if($header['proto']!==0||$header['fc']!==4||$header['addr']!==1234||$header['count']!==1)exit(7);
    fwrite($conn,pack('nnnC',$header['tx'],0,5,$header['unit']).pack('CCn',4,2,6000));
    fclose($conn);fclose($server);exit(0);
}
$probe=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if (!$probe) throw new RuntimeException($error);
$address=stream_socket_get_name($probe,false);$port=(int)substr(strrchr($address,':'),1);fclose($probe);
$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--server',(string)$port],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
if(!is_resource($process))throw new RuntimeException('Não foi possível iniciar servidor Modbus local');
fclose($pipes[0]);
try{
    $ready=fgets($pipes[1]);fclose($pipes[1]);
    if(trim((string)$ready)!=='READY')throw new RuntimeException('Servidor Modbus local não iniciou: '.stream_get_contents($pipes[2]));
    $client=new ModbusClient('127.0.0.1',$port,3.0);
    $v=$client->read(1,4,1234,1);
    if($v!==[6000]||ModbusClient::decode($v,'u16',2)!==60.0)throw new RuntimeException('Erro de leitura no servidor simulado');
    try{$client->read(1,6,0,1);throw new RuntimeException('Aceitou write Modbus!');}catch(InvalidArgumentException $expected){}
    unset($client);
    $serverError=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($process);
    if($status!==0)throw new RuntimeException('Resposta FC04 inválida no servidor simulado: '.$serverError);
    echo "OK Modbus/TCP FC04 e rejeição FC06 (servidor simulado local, multiplataforma)\n";
} finally {if(is_resource($process))proc_terminate($process);}
