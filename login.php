<?php
declare(strict_types=1);
require_once __DIR__.'/src/bootstrap.php';
if (!is_file(__DIR__.'/config.php')) {header('Location: install.php');exit;}
startSession();
if (!empty($_SESSION['uid'])) {header('Location: index.php');exit;}
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { $error='CSRF inválido'; }
    else {
        $u=q('SELECT * FROM users WHERE username=?',[trim((string)($_POST['username']??''))])->fetch();
        if ($u && password_verify((string)($_POST['password']??''),$u['password_hash'])) {
            session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['username']=$u['username'];
            header('Location: index.php');exit;
        }
        $error='Usuário ou senha incorretos';
        usleep(250000);
    }
}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar • USCA Vale</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"></head><body class="setup-page"><div class="setup-box"><div class="text-center"><span class="brand-mark">⚡</span><h1>USCA Vale</h1><p class="muted">Supervisão elétrica e de grupos geradores</p></div><?php if($error):?><div class="notice danger"><?=h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label>Usuário</label><input class="form-control" name="username" value="admin" required><label>Senha</label><input class="form-control" name="password" type="password" required><button class="btn-primary-custom" type="submit">Entrar no painel</button></form></div></body></html>
