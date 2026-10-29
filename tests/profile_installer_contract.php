<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/ProfileInstaller.php';
$p=mainsLocalProfile();
if(($p['role']??'')!=='mains'||count($p['mappings'])!==29||count($p['guards'])!==5) throw new RuntimeException('Perfil incompleto');
$maps=array_column($p['mappings'],null,'metric');
foreach(['mains_voltage_l12'=>501,'mains_voltage_l23'=>502,'mains_voltage_l31'=>503,'current_l1'=>513,'current_l2'=>514,'current_l3'=>515,'mains_power_kw'=>519,'power_kva'=>527,'power_factor'=>538,'battery_voltage'=>567,'bus_voltage_l12'=>539,'bus_frequency'=>545] as $metric=>$addr){
 if(($maps[$metric]['address']??null)!==$addr)throw new RuntimeException('Endereço incorreto: '.$metric);
}
if(($maps['mains_available']['fc']??null)!==2||($maps['mains_available']['value_type']??null)!=='bool_not') throw new RuntimeException('FC02 rede indisponível inválido');
if($p['voltage_rules']['low']!==12420||$p['voltage_rules']['high']!==15180)throw new RuntimeException('Limites de 13.8kV alterados');
echo "OK: perfil AGC150MA r2, 29 mapas, verificações, estados e limites recuperados do repositório.\\n";
