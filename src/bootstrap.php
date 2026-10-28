<?php
declare(strict_types=1);
// Operational timestamps are UTC in storage; JavaScript renders America/Sao_Paulo.
date_default_timezone_set('UTC');
function appConfig(): array {
    static $c = null;
    if ($c !== null) return $c;
    $f = dirname(__DIR__) . '/config.php';
    if (!is_file($f)) throw new RuntimeException('Não instalado. Abra install.php.');
    $c = require $f;
    return $c;
}
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = appConfig()['db'];
    $host = $cfg['host'] ?: 'localhost';
    $pdo = new PDO('mysql:host=' . $host . ';port=' . (int)($cfg['port'] ?? 3306) . ';dbname=' . $cfg['name'] . ';charset=utf8mb4', $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
function q(string $sql, array $args = []): PDOStatement { $s = db()->prepare($sql); $s->execute($args); return $s; }
function utc(): string { return gmdate('Y-m-d H:i:s') . sprintf('.%03d', (int)((microtime(true) * 1000) % 1000)); }
function mustDate(?string $s, string $default): string {
    if (!$s) return $default;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s, new DateTimeZone('UTC'));
    if (!$dt) throw new InvalidArgumentException('Data UTC inválida.');
    return $dt->format('Y-m-d H:i:s');
}
function setting(string $key, string $default = ''): string {
    $val = q('SELECT value FROM app_settings WHERE name=?', [$key])->fetchColumn();
    return $val === false ? $default : (string)$val;
}
function setSetting(string $key, string $value): void {
    q('INSERT INTO app_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$key,$value]);
}
function jsonOut($data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function startSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['httponly'=>true, 'secure'=>$secure, 'samesite'=>'Strict']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
}
function requireAuth(): void { startSession(); if (empty($_SESSION['uid'])) jsonOut(['error'=>'Não autenticado'],401); }
function csrf(): void { if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) jsonOut(['error'=>'CSRF inválido'],403); }
function cleanStr($v, int $max = 100): string { return mbOrSubstr(trim((string)$v),$max); }
function mbOrSubstr(string $s,int $len): string {return function_exists('mb_substr') ? mb_substr($s,0,$len) : substr($s,0,$len);}
function metricCatalog(): array {
    return [
      'mains_available'=>['Rede disponível','bool','Rede'], 'source_generator'=>['Carga no gerador','bool','Rede'],
      'generator_running'=>['Gerador ligado','bool','Gerador'], 'generator_fault'=>['Falha do gerador','bool','Gerador'],
      'mains_breaker'=>['Disjuntor da rede','bool','Rede'], 'generator_breaker'=>['Disjuntor do gerador','bool','Gerador'],
      'voltage_l12'=>['Tensão L1-L2','V','Elétrica'], 'voltage_l23'=>['Tensão L2-L3','V','Elétrica'], 'voltage_l31'=>['Tensão L3-L1','V','Elétrica'],
      'voltage_l1n'=>['Tensão L1-N','V','Elétrica'], 'voltage_l2n'=>['Tensão L2-N','V','Elétrica'], 'voltage_l3n'=>['Tensão L3-N','V','Elétrica'],
      'frequency'=>['Frequência','Hz','Elétrica'], 'current_l1'=>['Corrente L1','A','Elétrica'], 'current_l2'=>['Corrente L2','A','Elétrica'], 'current_l3'=>['Corrente L3','A','Elétrica'],
      'power_kw'=>['Potência ativa','kW','Potência'], 'power_kva'=>['Potência aparente','kVA','Potência'], 'power_kvar'=>['Potência reativa','kvar','Potência'],
      'power_factor'=>['Fator de potência','','Potência'], 'energy_kwh'=>['Energia acumulada','kWh','Potência'], 'load_pct'=>['Carga do gerador','%','Gerador'],
      'engine_rpm'=>['Rotação','rpm','Motor'], 'engine_hours'=>['Horímetro','h','Motor'], 'engine_temp'=>['Temperatura motor','°C','Motor'],
      'oil_pressure'=>['Pressão do óleo','bar','Motor'], 'battery_voltage'=>['Bateria','V','Motor'], 'starts'=>['Quantidade de partidas','','Motor'],
      'mains_frequency'=>['Frequência da concessionária','Hz','Rede'], 'generator_frequency'=>['Frequência do gerador','Hz','Gerador'],
    ];
}
