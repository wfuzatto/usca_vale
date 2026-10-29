<?php
declare(strict_types=1);
// Regression references: the user's AGC150MA display photos (28 October 2026).
// Standalone: no controller connection or database writes.
require_once dirname(__DIR__).'/src/ModbusClient.php';
$profile=json_decode(file_get_contents(dirname(__DIR__).'/profiles/deif-agc150-mains-13k8.json'),true,512,JSON_THROW_ON_ERROR);
$maps=array_column($profile['mappings'],null,'metric');
function checkProfile(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
checkProfile($profile['role']==='mains','O controlador MAINS não pode aparecer como gerador');
foreach(array_keys($maps) as $metric) checkProfile(!str_starts_with($metric,'generator_') && $metric!=='engine_hours','Telemetria de gerador não comprovada no perfil MAINS');
checkProfile($maps['mains_voltage_l12']['address']===501,'A rede MAINS é medida em 501, não no barramento B');
checkProfile($maps['mains_power_kw']['address']===519,'A potência MAINS deve usar 519, não o transdutor 592');
checkProfile($maps['tie_breaker']['fc']===2 && $maps['tie_breaker']['address']===0,'No MAINS, FC02/0 é TB, não GB');
$vectors=[
    ['mains_voltage_l12',[1416],14160.0], // display: 14.16 kV
    ['voltage_l1n',[807],8070.0],       // display: 8.07 kV
    ['frequency',[6002],60.02],
    ['current_l1',[33],33.0],
    ['power_kw',[2],20.0],             // display: 0.02 MW
    ['power_kvar',[65529],-70.0],      // display: -0.07 Mvar
    ['power_kva',[80],800.0],          // display: 0.80 MVA
    ['energy_kwh',[0,1536],15360.0],   // display: 15.36 MWh
    ['power_factor',[2],0.02],
    ['battery_voltage',[243],24.3],
    ['mains_available',[0],1.0],
    ['bus_voltage_l12',[0],0.0],
];
foreach($vectors as [$metric,$raw,$expected]) {
    $m=$maps[$metric];$actual=ModbusClient::decode($raw,$m['value_type'],$m['scale_exp']);
    checkProfile(abs($actual-$expected)<1e-8,'Conversão divergente do visor: '.$metric);
}
checkProfile(13950.0<$profile['voltage_rules']['high'],'Leitura normal de 13.95 kV não deve disparar sobretensão de 380 V');
checkProfile(15200.0>$profile['voltage_rules']['high'],'A regra corrigida ainda deve detectar tensão acima do limite');
echo "OK perfil MAINS: fonte, endereços e unidades conferidos com as fotos; limites relativos a 13.8 kV\n";
