<?php
declare(strict_types=1);
require_once __DIR__.'/src/bootstrap.php';
startSession();
if (is_file(__DIR__.'/config.php')) {
    try { if (db()->query('SELECT COUNT(*) FROM users')->fetchColumn()>0) {header('Location: index.php');exit;} } catch(Throwable $e) {}
}
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('CSRF inválido');
    $host=trim((string)($_POST['dbhost']??'localhost')) ?: 'localhost';
    $ip=trim((string)($_POST['ip']??''));
    $port=(int)($_POST['port']??502);
    $user=trim((string)($_POST['dbuser']??'root')) ?: 'root';
    $pass=(string)($_POST['dbpass']??'');
    $admin=(string)($_POST['password']??'');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,252}$/D',$host)) throw new RuntimeException('Servidor do banco inválido');
    if ($ip!=='' && !filter_var($ip,FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,252}$/D',$ip)) throw new RuntimeException('IP/host USCA inválido');
    if ($port<1||$port>65535) throw new RuntimeException('Porta inválida');
    if (strlen($admin)<12) throw new RuntimeException('Senha administrativa: mínimo 12 caracteres');
    $pdo=new PDO("mysql:host=$host;port=3306;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `usca_vale` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `usca_vale`');
    $schema=file_get_contents(__DIR__.'/sql/schema.sql');
    foreach (preg_split('/;\s*(?:\r?\n|$)/',$schema) as $sql) if (trim($sql)) $pdo->exec($sql);
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0) throw new RuntimeException('Instalação já possui usuários; não é permitido redefinir por esta tela.');
    $s=$pdo->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)');
    $s->execute(['admin',password_hash($admin,PASSWORD_DEFAULT)]);
    $s=$pdo->prepare('INSERT INTO devices(name,role,simulate,enabled,poll_seconds) VALUES(?,?,?,?,?)');
    $s->execute(['Simulador AGC 150','simulator',1,1,5]);
    $s=$pdo->prepare('INSERT INTO devices(name,role,host,port,simulate,enabled,poll_seconds) VALUES(?,?,?,?,0,0,5)');
    $s->execute(['DEIF AGC 150','controller',$ip,$port]);
    $rules=[
      ['Ausência da concessionária','mains_available','below',0.5,0,5,'critical'],
      ['Subtensão L1-L2','voltage_l12','below',342,5,5,'critical'],
      ['Sobretensão L1-L2','voltage_l12','above',418,5,5,'critical'],
      ['Frequência baixa','frequency','below',58.5,0.5,5,'warning'],
      ['Frequência alta','frequency','above',61.5,0.5,5,'warning'],
      ['Falha do gerador','generator_fault','above',0.5,0,2,'critical'],
    ];
    $st=$pdo->prepare('INSERT INTO rules(name,metric,comparison,threshold,hysteresis,hold_seconds,severity) VALUES(?,?,?,?,?,?,?)');
    foreach($rules as $r) $st->execute($r);
    $pdo->exec("INSERT INTO app_settings(name,value) VALUES('retention_days','365')");
    $conf=['db'=>['host'=>$host,'port'=>3306,'name'=>'usca_vale','user'=>$user,'pass'=>$pass]];
    if (file_put_contents(__DIR__.'/config.php',"<?php\nreturn ".var_export($conf,true).";\n",LOCK_EX)===false) throw new RuntimeException('Impossível gravar config.php; verifique permissões da pasta');
    @chmod(__DIR__.'/config.php',0600);
    header('Location: index.php?installed=1');exit;
  } catch(Throwable $e) { $error=$e->getMessage(); }
}
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação USCA Vale</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"></head><body class="setup-page"><div class="setup-box"><div class="text-center"><span class="brand-mark">⚡</span><h1>USCA Vale</h1><p class="muted">Instalação inicial • XAMPP / MariaDB</p></div><?php if($error): ?><div class="notice danger"><?=h($error)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label>Endereço do banco MySQL</label><input class="form-control" name="dbhost" value="localhost" required><small>Banco usca_vale será criado automaticamente; porta 3306.</small><div class="grid-2"><div><label>IP da USCA DEIF</label><input class="form-control" name="ip" placeholder="192.168.100.50"><small>Pode ser preenchido depois.</small></div><div><label>Porta TCP</label><input class="form-control" name="port" type="number" min="1" max="65535" value="502" required></div></div><details><summary>Credenciais avançadas do banco (padrão XAMPP)</summary><label>Usuário do MySQL</label><input class="form-control" name="dbuser" value="root"><label>Senha do MySQL</label><input class="form-control" type="password" name="dbpass" autocomplete="new-password"></details><label>Senha do administrador</label><input class="form-control" type="password" name="password" minlength="12" required autocomplete="new-password"><small>Usuário: admin • mínimo 12 caracteres</small><button class="btn-primary-custom" type="submit">Instalar e abrir dashboard</button><p class="muted tiny">A coleta real permanece desativada até confirmar registradores oficiais. O simulador inicia automaticamente quando o serviço de coleta estiver ativo.</p></form></div></body></html>
