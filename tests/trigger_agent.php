<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/TriggerAgent.php';
function ok(bool $condition,string $msg): void {if (!$condition) throw new RuntimeException($msg);}
$token=str_repeat('a',64);$hash=hash('sha256',$token);
ok(triggerTokenValid($token,$hash),'valid token rejected');
ok(!triggerTokenValid(str_repeat('b',64),$hash),'bad token accepted');
ok(!triggerTokenValid('',$hash),'empty token accepted');
ok(!triggerTokenValid($token,''),'empty secret accepted');
ok(triggerHostIsTls(['HTTPS'=>'on']),'TLS on rejected');
ok(triggerHostIsTls(['SERVER_PORT'=>'443']),'TLS port rejected');
ok(!triggerHostIsTls(['HTTPS'=>'off','SERVER_PORT'=>'80']),'HTTP accepted');
$url='https://valedamantiqueira.energia.ws/collect-trigger.php';
ok(triggerEndpointUrl($url)===$url,'valid URL rejected');
foreach (['http://valedamantiqueira.energia.ws/collect-trigger.php','https://x.test:37502/collect-trigger.php',
'https://x.test@bad.example/collect-trigger.php','https://example.test/collect-trigger.php?token=x',
'https://example.test/api.php','https://example.test/collect-trigger.php#x'] as $bad){
 try {triggerEndpointUrl($bad);throw new RuntimeException('bad URL accepted: '.$bad);}
 catch(InvalidArgumentException $expected){}
}
$rsc=triggerRsc($url,$token);
ok(str_contains($rsc,'check-certificate=yes'),'TLS verification missing');
ok(str_contains($rsc,'http-method=post'),'POST missing');
ok(str_contains($rsc,'interval=30s'),'scheduler wrong');
ok(str_contains($rsc,$token),'generated token missing');
ok(!str_contains($rsc,'/ip firewall') && !str_contains($rsc,'/system reboot'),'unexpected router changes');
echo "OK: TLS, autenticação, URL, token e RSC do MikroTik.\n";
