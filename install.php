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
    $dbport=(int)($_POST['dbport']??3306);
    $dbname=trim((string)($_POST['dbname']??'usca_vale'));
    $user=trim((string)($_POST['dbuser']??'root'));
    $pass=(string)($_POST['dbpass']??'');
    $createDb=!empty($_POST['create_db']);
    $ip=trim((string)($_POST['ip']??''));
    $port=(int)($_POST['port']??502);
    $admin=(string)($_POST['password']??'');
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,252}$/D',$host)) throw new RuntimeException('Servidor do banco inválido');
    if ($dbport<1||$dbport>65535) throw new RuntimeException('Porta do MySQL inválida');
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$dbname)) throw new RuntimeException('Nome do banco inválido (use letras, números e _; máximo 64 caracteres)');
    if ($user===''||strlen($user)>128) throw new RuntimeException('Informe o usuário do MySQL');
    if ($ip!=='' && !filter_var($ip,FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,252}$/D',$ip)) throw new RuntimeException('IP/host USCA inválido');
    if ($port<1||$port>65535) throw new RuntimeException('Porta da USCA inválida');
    if (strlen($admin)<12) throw new RuntimeException('Senha administrativa: mínimo 12 caracteres');
    // Hospedagens compartilhadas geralmente exigem um banco criado no painel,
    // sem privilégios CREATE DATABASE para o usuário da aplicação.
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
    $serverDsn="mysql:host=$host;port=$dbport;charset=utf8mb4";
    $databaseDsn="mysql:host=$host;port=$dbport;dbname=$dbname;charset=utf8mb4";
    try {
      $pdo=new PDO($databaseDsn,$user,$pass,$options);
    } catch(PDOException $connectionError) {
      $mysqlCode=(int)($connectionError->errorInfo[1]??0);
      if ($mysqlCode!==1049) throw $connectionError;
      if (!$createDb) throw new RuntimeException('Banco não encontrado. Crie o banco no painel da hospedagem ou marque "Criar banco se não existir".');
      try {
        $server=new PDO($serverDsn,$user,$pass,$options);
        $server->exec('CREATE DATABASE IF NOT EXISTS ' . chr(96) . $dbname . chr(96) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      } catch(PDOException $createError) {
        throw new RuntimeException('Não foi possível criar o banco. Na Locaweb, crie o banco primeiro no painel e informe aqui seu nome, usuário e senha.',0,$createError);
      }
      $pdo=new PDO($databaseDsn,$user,$pass,$options);
    }
    $pdo->exec("SET time_zone = '+00:00'");
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
    $conf=['db'=>['host'=>$host,'port'=>$dbport,'name'=>$dbname,'user'=>$user,'pass'=>$pass]];
    if (file_put_contents(__DIR__.'/config.php',"<?php\nreturn ".var_export($conf,true).";\n",LOCK_EX)===false) throw new RuntimeException('Impossível gravar config.php; verifique permissões da pasta');
    @chmod(__DIR__.'/config.php',0600);
    header('Location: index.php?installed=1');exit;
  } catch(Throwable $e) {
    $mysqlCode=$e instanceof PDOException?(int)($e->errorInfo[1]??0):0;
    $error=$mysqlCode===1045?'Acesso negado ao MySQL. Confira servidor, porta, usuário e senha do banco no painel da hospedagem.'
          :($mysqlCode===1044?'Usuário sem permissão para o banco informado. Confira o nome do banco e seus privilégios.'
          :$e->getMessage());
  }
}
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação USCA Vale</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
<style>
.setup-box{width:min(100%,550px)}
.setup-box .install-section{margin:22px 0 8px;color:#14385c;font-size:13px;font-weight:800;border-bottom:1px solid #e3eaf2;padding-bottom:7px}
.setup-box .install-grid{display:grid;grid-template-columns:minmax(0,1fr) 130px;gap:12px}
.setup-box .install-grid.equal{grid-template-columns:repeat(2,minmax(0,1fr))}
.setup-box .install-field{min-width:0;margin-top:12px}
.setup-box .install-field label{display:block;margin-bottom:6px}
.setup-box .install-check{display:flex;align-items:center;gap:8px;margin-top:13px;font-size:12px;color:#375d82}
.setup-box .install-check input{width:16px;height:16px;flex:none}
@media(max-width:480px){.setup-box .install-grid,.setup-box .install-grid.equal{grid-template-columns:1fr}}
</style>
</head><body class="setup-page"><div class="setup-box">
<div class="text-center"><span class="brand-mark">⚡</span><h1>USCA Vale</h1><p class="muted">Configuração inicial • XAMPP / Locaweb / MariaDB</p></div>
<?php if($error): ?><div class="notice danger" role="alert"><?=h($error)?></div><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
<div class="install-section">Banco de dados MySQL / MariaDB</div>
<div class="install-grid">
<div class="install-field"><label for="dbhost">Servidor MySQL</label><input id="dbhost" class="form-control" name="dbhost" value="<?=h($_POST['dbhost']??'localhost')?>" placeholder="localhost" required></div>
<div class="install-field"><label for="dbport">Porta MySQL</label><input id="dbport" class="form-control" name="dbport" type="number" min="1" max="65535" value="<?=h($_POST['dbport']??'3306')?>" required></div>
</div>
<div class="install-field"><label for="dbname">Nome do banco de dados</label><input id="dbname" class="form-control" name="dbname" value="<?=h($_POST['dbname']??'usca_vale')?>" maxlength="64" pattern="[a-zA-Z0-9_]+" required><small>Na Locaweb, informe o nome exato do banco criado no painel.</small></div>
<div class="install-grid equal">
<div class="install-field"><label for="dbuser">Usuário do MySQL</label><input id="dbuser" class="form-control" name="dbuser" value="<?=h($_POST['dbuser']??'root')?>" autocomplete="username" required></div>
<div class="install-field"><label for="dbpass">Senha do MySQL</label><input id="dbpass" class="form-control" name="dbpass" type="password" autocomplete="new-password" placeholder="Vazia somente se seu banco permitir"></div>
</div>
<label class="install-check" for="create_db"><input id="create_db" name="create_db" type="checkbox" value="1" <?=(!isset($_POST['csrf'])||!empty($_POST['create_db']))?'checked':''?>> Criar o banco se não existir (precisa de permissão)</label>
<small>Na hospedagem compartilhada, normalmente o banco já deve existir. O instalador cria as tabelas dentro dele.</small>
<div class="install-section">Comunicação com a USCA</div>
<div class="install-grid equal">
<div class="install-field"><label for="ip">IP da USCA DEIF</label><input id="ip" class="form-control" name="ip" value="<?=h($_POST['ip']??'')?>" placeholder="192.168.100.50"><small>Pode ser preenchido depois.</small></div>
<div class="install-field"><label for="port">Porta TCP / Modbus</label><input id="port" class="form-control" name="port" type="number" min="1" max="65535" value="<?=h($_POST['port']??'502')?>" required></div>
</div>
<div class="install-section">Acesso ao painel</div>
<div class="install-field"><label for="password">Senha do administrador</label><input id="password" class="form-control" name="password" type="password" minlength="12" required autocomplete="new-password"><small>Usuário inicial: admin • mínimo de 12 caracteres</small></div>
<button class="btn-primary-custom" type="submit">Instalar e abrir dashboard</button>
<p class="muted tiny">Banco e credenciais são específicos de cada instalação. A coleta real permanece desativada até validar os registradores da USCA. Nunca publique o arquivo config.php no GitHub.</p>
</form></div></body></html>
