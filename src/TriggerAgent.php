<?php
declare(strict_types=1);
/** Helpers for the RouterOS HTTPS trigger. No secrets written to the repository. */
function triggerHostIsTls(array $server): bool {
    return (isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string)$server['HTTPS']) !== 'off')
        || (int)($server['SERVER_PORT'] ?? 0) === 443;
}
function triggerTokenValid(string $sent, string $storedSha): bool {
    if ($storedSha==='' || !preg_match('/^[0-9a-f]{64}$/D',$storedSha)) return false;
    if (!preg_match('/^[0-9a-f]{64}$/D',$sent)) return false;
    return hash_equals($storedSha,hash('sha256',$sent));
}
/** Prefer legacy auth header; RouterOS compatibility mode sends form body over HTTPS. */
function triggerSubmittedToken(array $server, array $post): string {
    $header=$server['HTTP_X_COLLECTOR_TOKEN']??null;
    if(is_string($header) && $header!=='') return $header;
    $form=$post['token']??null;
    return is_string($form)?$form:'';
}
function triggerEndpointUrl(string $url): string {
    $u=parse_url(trim($url));
    if (!is_array($u) || strtolower((string)($u['scheme']??''))!=='https'
        || !isset($u['host']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/D',(string)$u['host'])
        || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])
        || (isset($u['port']) && (int)$u['port']!==443)
        || ($u['path']??'')!=='/collect-trigger.php') {
        throw new InvalidArgumentException('Use URL HTTPS terminada em /collect-trigger.php (porta 443).');
    }
    return 'https://'.strtolower((string)$u['host']).'/collect-trigger.php';
}
function triggerRsc(string $url,string $token): string {
    $url=triggerEndpointUrl($url);
    if (!preg_match('/^[0-9a-f]{64}$/D',$token)) throw new InvalidArgumentException('Token inválido');
    $template=<<<'RSC'
# USCA Vale - Agente HTTPS para MikroTik RouterOS 7
# Importar: /import file-name=usca-vale-mikrotik.rsc
# Sem alteracao de firewall, NAT, rotas ou controle da USCA.
# Contem token privado. Proteja o arquivo e contas do roteador.
:if ([:len [/system scheduler find where name="usca-vale-scheduler"]] > 0) do={ /system scheduler remove [find where name="usca-vale-scheduler"] }
:if ([:len [/system script find where name="usca-vale-trigger"]] > 0) do={ /system script remove [find where name="usca-vale-trigger"] }
/system script add name="usca-vale-trigger" policy=read,write,test,ftp source={
    :local url "@@URL@@"
    :local token "@@TOKEN@@"
    :local payload ("token=" . $token)
    :do {
        /tool fetch url=$url http-method=post http-data=$payload check-certificate=yes output=none
    } on-error={
        :log warning "USCA Vale: falha no disparo; confira HTTPS, DNS e o status remoto"
    }
}
/system scheduler add name="usca-vale-scheduler" interval=30s start-time=00:00:00 on-event="/system script run usca-vale-trigger" policy=read,write,test,ftp disabled=no comment="Agente HTTPS; sem Modbus local"
/system script run usca-vale-trigger
:log info "USCA Vale: agente HTTPS instalado (intervalo 30s)"
RSC;
    return str_replace(['@@URL@@','@@TOKEN@@'],[$url,$token],$template)."\n";
}
