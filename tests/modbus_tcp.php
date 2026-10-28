<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/ModbusClient.php';
if (!function_exists('pcntl_fork')) {echo "SKIP: pcntl indisponível nesta plataforma\n";exit(0);}
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if (!$server) throw new RuntimeException($error);
$address=stream_socket_get_name($server,false);
$port=(int)substr(strrchr($address,':'),1);
$pid=pcntl_fork();
if($pid===-1) throw new RuntimeException('Fork indisponível');
if($pid===0){
    $conn=stream_socket_accept($server,5);
    if (!$conn)exit(5);
    $request=fread($conn,12); // MBAP 7 + PDU 5
    if(strlen($request)!==12)exit(6);
    $header=unpack('ntx/nproto/nlen/Cunit/Cfc/naddr/ncount',$request);
    if($header['proto']!==0||$header['fc']!==4||$header['addr']!==1234||$header['count']!==1)exit(7);
    fwrite($conn,pack('nnnC',$header['tx'],0,5,$header['unit']).pack('CCn',4,2,6000));
    fclose($conn);fclose($server);exit(0);
}
try{
    $client=new ModbusClient('127.0.0.1',$port,3.0);
    $v=$client->read(1,4,1234,1);
    if($v!==[6000]||ModbusClient::decode($v,'u16',2)!==60.0)throw new RuntimeException('Erro de leitura no servidor simulado');
    try{$client->read(1,6,0,1);throw new RuntimeException('Aceitou write Modbus!');}catch(InvalidArgumentException $expected){}
    pcntl_waitpid($pid,$status);
    if(pcntl_wexitstatus($status)!==0)throw new RuntimeException('Resposta FC04 inválida no servidor simulado');
    echo "OK Modbus/TCP FC04 e rejeição FC06 (testado com servidor simulado local)\n";
} finally {fclose($server);}
