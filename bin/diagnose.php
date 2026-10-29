<?php
declare(strict_types=1);
/**
 * Diagnóstico somente leitura das amostras persistidas no MySQL/MariaDB.
 * Execute pelo PHP CLI do XAMPP; não expõe credenciais nem altera a USCA.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Apenas CLI'); }
require_once dirname(__DIR__) . '/src/bootstrap.php';
$delay = 12;
foreach ($argv as $arg) {
    if ($arg === '--no-wait') $delay = 0;
    if (preg_match('/^--observe=(\d{1,3})$/', $arg, $m)) $delay = min(60, (int)$m[1]);
}
try {
    $devices = q('SELECT d.id,d.name,d.role,d.enabled,d.simulate,d.poll_seconds,d.last_seen,d.last_error,s.last_attempt_at,s.failure_count FROM devices d LEFT JOIN collector_state s ON s.device_id=d.id ORDER BY d.id')->fetchAll();
    if (!$devices) { echo "Nenhum equipamento cadastrado.\n"; exit(1); }
    $cutoffs = mappingCutoffs();
    $snapshot = static function(array $device) use ($cutoffs): array {
        $id = (int)$device['id'];
        $cutoff = $cutoffs[$id] ?? null;
        $params = [$id];
        $filter = '';
        if ($cutoff !== null) { $filter = ' AND taken_at>=?'; $params[] = $cutoff; }
        $counts = q(
            'SELECT COUNT(*) total, MIN(taken_at) first_at, MAX(taken_at) last_at,'
            . ' SUM(taken_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)) five_minutes,'
            . ' SUM(taken_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)) one_hour,'
            . ' SUM(taken_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)) one_day'
            . ' FROM samples WHERE device_id=?' . $filter,
            $params
        )->fetch();
        $latest = q('SELECT id,taken_at,simulated,values_json FROM samples WHERE device_id=?' . $filter . ' ORDER BY id DESC LIMIT 1', $params)->fetch();
        $data = $latest ? json_decode((string)$latest['values_json'], true) : null;
        if ($latest && !is_array($data)) throw new RuntimeException('JSON inválido na amostra '.$latest['id']);
        $metrics = is_array($data) ? array_values(array_filter(array_keys($data), static fn($k) => $k !== '_provenance' && $k[0] !== '_')) : [];
        $age = $latest ? max(0, time() - (int)strtotime((string)$latest['taken_at'] . ' UTC')) : null;
        return [
            'latest_id' => (int)($latest['id'] ?? 0),
            'latest_at' => $latest['taken_at'] ?? null,
            'age_seconds' => $age,
            'total_valid' => (int)$counts['total'],
            'last_5min' => (int)$counts['five_minutes'],
            'last_1h' => (int)$counts['one_hour'],
            'last_24h' => (int)$counts['one_day'],
            'first_at' => $counts['first_at'],
            'cutoff_utc' => $cutoff,
            'metrics' => $metrics,
            'simulated' => $latest ? (bool)$latest['simulated'] : null,
            'mapping_profile' => $data['_provenance']['mapping_version'] ?? null,
        ];
    };
    echo "USCA Vale - diagnostico de persistencia - horario UTC\n";
    echo "Este teste e SOMENTE LEITURA. Nao altera banco, registros ou controladores.\n";
    $before = [];
    foreach ($devices as $dev) {
        $id = (int)$dev['id'];
        $s = $snapshot($dev);
        $before[$id] = $s;
        echo "\n#" . $id . " - " . $dev['name'] . " (" . $dev['role'] . ")\n";
        echo "  coleta=" . ((int)$dev['enabled'] ? 'ativa' : 'desabilitada')
            . " origem=" . ((int)$dev['simulate'] ? 'SIMULACAO' : 'REAL')
            . " intervalo=" . $dev['poll_seconds'] . " s\n";
        echo "  ultima tentativa=" . ($dev['last_attempt_at'] ?: '-')
            . " ultima leitura=" . ($dev['last_seen'] ?: '-') . "\n";
        echo "  falhas seguidas=" . ($dev['failure_count'] ?? 0)
            . " ultimo erro=" . ($dev['last_error'] ?: '-') . "\n";
        echo "  amostras depois do corte=" . $s['total_valid']
            . " (5min=" . $s['last_5min'] . ", 1h=" . $s['last_1h']
            . ", 24h=" . $s['last_24h'] . ")\n";
        echo "  inicio=" . ($s['first_at'] ?: '-') . " fim=" . ($s['latest_at'] ?: '-')
            . " atraso=" . ($s['age_seconds'] === null ? '-' : $s['age_seconds'] . 's') . "\n";
        echo "  corte historico=" . ($s['cutoff_utc'] ?: 'nenhum')
            . " perfil=" . ($s['mapping_profile'] ?: 'nao informado') . "\n";
        echo "  grandezas da ultima amostra: " . (implode(', ', $s['metrics']) ?: 'nenhuma') . "\n";
    }
    if ($delay > 0) {
        echo "\nObservando novas gravacoes por " . $delay . " segundos...\n";
        sleep($delay);
        foreach ($devices as $dev) {
            $old = $before[(int)$dev['id']];
            $new = $snapshot($dev);
            $delta = $new['total_valid'] - $old['total_valid'];
            $result = $delta > 0 && $new['latest_id'] > $old['latest_id'] ? 'OK - novas amostras persistidas' : 'SEM NOVAS AMOSTRAS';
            if (!(int)$dev['enabled']) $result .= ' (coleta desabilitada)';
            echo "#{$dev['id']} {$dev['name']}: {$result}; delta={$delta}; ultimo_id={$new['latest_id']}\n";
        }
    }
    echo "\nDiagnostico concluido. Para confirmar recuperacao apos reinicio, execute novamente.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERRO: " . $e->getMessage() . "\n");
    exit(1);
}
