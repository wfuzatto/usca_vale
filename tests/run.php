<?php
declare(strict_types=1);
// Safe standalone unit tests; no USCA connection or DB writes.
require_once dirname(__DIR__).'/src/ModbusClient.php';
function eq($expected,$actual,$name){if($expected!==$actual){fwrite(STDERR,"FAIL $name: expected ".var_export($expected,true)." got ".var_export($actual,true)."\n");exit(1);}echo "OK $name\n";}
eq(0.0,ModbusClient::decode([0],'u16',0),'zero preservado');
eq(-1.0,ModbusClient::decode([65535],'s16',0),'signed 16');
eq(-1.0,ModbusClient::decode([65535,65535],'s32',0),'signed 32');
eq(65536.0,ModbusClient::decode([1,0],'u32',0),'ABCD 32-bit');
eq(60.0,ModbusClient::decode([6000],'u16',2),'escala DEIF frequência');
eq(1.0,ModbusClient::decode([1],'bool',0),'bool');
try{ModbusClient::decode([1],'u32',0);throw new RuntimeException('Validação de 32 bits falhou');}catch(RuntimeException $e){if($e->getMessage()==='Validação de 32 bits falhou')throw $e;echo "OK validação words\n";}
echo "TESTES DE DECODIFICAÇÃO OK — integração MySQL/DEIF requer ambiente real.\n";
