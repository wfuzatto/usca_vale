<?php
declare(strict_types=1);
if (is_file(dirname(__DIR__).'/config.php')) {
    fwrite(STDERR, "O teste requer um checkout limpo, sem config.php.\n");
    exit(2);
}
$_SERVER['REQUEST_METHOD']='GET';
ob_start();
require dirname(__DIR__).'/install.php';
$html=(string)ob_get_clean();
$fields=['dbhost','dbport','dbname','dbuser','dbpass','create_db','ip','port','password'];
foreach ($fields as $name) {
    if (!preg_match('/<input\b[^>]*\bname="'.preg_quote($name,'/').'"/', $html)) {
        fwrite(STDERR,"Campo ausente: $name\n");
        exit(1);
    }
}
if (strpos($html,'<details>')!==false) {
    fwrite(STDERR,"Credenciais escondidas em <details>.\n"); exit(1);
}
if (strpos($html,'Senha do MySQL')===false ||
    strpos($html,'Nome do banco de dados')===false) {
    fwrite(STDERR,"Rotulos de credenciais incompletos.\n"); exit(1);
}
if (!preg_match('/<input\b[^>]*\bname="dbpass"[^>]*\btype="password"/', $html)) {
    fwrite(STDERR,"Senha do MySQL nao esta mascarada.\n"); exit(1);
}
$source=(string)file_get_contents(dirname(__DIR__).'/install.php');
if (strpos($source, "'port'=>\$dbport")===false ||
    strpos($source, "'name'=>\$dbname")===false) {
    fwrite(STDERR,"Configuracao salva nao usa nome e porta configuraveis.\n"); exit(1);
}
if (strpos($source,'CREATE DATABASE IF NOT EXISTS')===false ||
    strpos($source,'if (!$createDb)')===false) {
    fwrite(STDERR,"Fluxo de banco existente ou criacao opcional esta incompleto.\n"); exit(1);
}
if (!validDbHost('usca_vale.mysql.dbaas.com.br') ||
    !validDbHost('localhost') ||
    !validDbHost('192.168.100.50')) {
    fwrite(STDERR,"Rejeitou host MySQL permitido.\\n"); exit(1);
}
foreach (['mysql.example.com;port=99','mysql example.com','localhost:3306','../../etc/passwd',''] as $invalidHost) {
    if (validDbHost($invalidHost)) {
        fwrite(STDERR,"Aceitou host MySQL invalido.\\n"); exit(1);
    }
}
echo "OK: tela de instalacao com credenciais visiveis, senha mascarada e banco/porta configuraveis.\n";
