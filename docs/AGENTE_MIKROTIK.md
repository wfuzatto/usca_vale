# MikroTik como agente HTTP/HTTPS (Locaweb faz a leitura Modbus)

## Como funciona
O MikroTik **não lê Modbus** nem abre outro acesso na rede. Ele envia um POST HTTPS a cada 30 segundos para `/collect-trigger.php` (arquivo publicado na raiz `public_html/`). O endpoint confere um token aleatório de 256 bits contra seu SHA-256 guardado no MySQL, usa `GET_LOCK` para impedir execução simultânea, limita novas tentativas a uma a cada 20 segundos e chama `Collector::tick(..., realOnly=true)`. O coletor PHP da Locaweb abre o socket Modbus TCP **somente leitura** até o DDNS/endereço e porta cadastrados em Equipamentos, decodifica as leituras e persiste amostras, regras, eventos, falhas e comunicação no MySQL da Locaweb. Não são coletados dados simulados por esse disparo.

### Instalação passo a passo

1. Na **Locaweb**, habilite o certificado HTTPS do domínio `valedamantiqueira.energia.ws` e confira que `https://valedamantiqueira.energia.ws/index.php` abre sem alertas. O script MikroTik **recusa certificado inválido**, e o PHP **recusa HTTP**. Não desative a validação TLS.
2. Em **Equipamentos**, selecione um controlador **real** com **Habilitar coleta** marcado, IP/hostname `608905397dde.sn.mynetname.net` e porta **37502** (se esse for o NAT externo de sua configuração). Confirme Unit ID (habitualmente 1) e cadastre o mapa FC04 DEIF MAINS verificado. A porta *interna* da USCA pode continuar 502 — a hospedagem acessa a **porta externa** do NAT.
3. Na mesma tela, em **Agente MikroTik**, clique **Testar DNS / TCP / Modbus FC04 da Locaweb**, tendo selecionado o mesmo dispositivo em **Mapeamento de registros Modbus**. Esse teste é executado no PHP hospedado na Locaweb, não no navegador nem no MikroTik. Compare o valor retornado ao display físico para verificar a escala. Se DNS resolver mas TCP falhar, a hospedagem pode bloquear saída TCP, o DDNS/NAT pode estar incorreto ou o firewall pode bloquear; não há garantia de disponibilidade da porta externa.
4. Clique **Gerar e baixar agente MikroTik (.rsc)**. Isso só funciona acessando o painel via HTTPS. O sistema gera token exclusivo, armazena **somente o hash** no banco e baixa `usca-vale-mikrotik.rsc` já pronto para importar. **Gerar outro arquivo revoga o agente anterior.**
5. Arraste o arquivo para **Files** do WinBox, depois execute no terminal:
   ```routeros
   /import file-name=usca-vale-mikrotik.rsc
   /system scheduler print where name="usca-vale-scheduler"
   /system script run usca-vale-trigger
   /log print where message~"USCA Vale"
   ```
6. Aguarde 30–90 segundos e abra o dashboard e o histórico. Se aparecer "Sem leitura", verifique em Equipamentos se o controlador está habilitado, e revise FC04/Unit ID. O endpoint `collect-trigger.php` devolve somente contadores e erros genéricos, sem revelar endereços internos. O estado do MySQL, eventos e `devices.last_error` ajudam na investigação.

### Segurança
- O arquivo `.rsc` **contém o token privado**, incluindo no código-fonte do script no RouterOS. Não compartilhe nem faça `/export show-sensitive` publicamente. Remova cópias do arquivo após importar.
- A conexão Locaweb → USCA usa Modbus TCP sem autenticação ou criptografia. Deixar uma porta Modbus encaminhada por NAT para toda a internet é perigoso para uma rede industrial. Restrinja a **origem** à infraestrutura permitida, por firewall, e prefira túnel privado com acesso controlado. Este projeto **não cria nem modifica regras NAT/firewall**.
- Não coloque tokens, senhas FTP/MySQL ou credenciais em GitHub; a geração é feita no painel e a senha não aparece em logs.
- Não rode simultaneamente outro coletor apontando ao **mesmo banco e ao mesmo dispositivo**. O lock remoto protege o endpoint web, mas não outros coletores independentes.
- O agendamento de 30 segundos é adequado para supervisão básica, **não** para registrar transitórios elétricos, e não pode atestar duração de falhas inferiores à amostragem.
- Enquanto a rede local, a USCA ou a internet estiverem desligadas, o dashboard passa a mostrar leitura desatualizada. Não interprete falha de comunicação como falta comprovada de concessionária.

### Se a hospedagem bloquear TCP de saída
É uma limitação real que não se resolve pelo Scheduler. Nesse caso, o coletor deve ficar na LAN (XAMPP ou dispositivo pequeno em nobreak) e **enviar dados por HTTPS** para uma API receptora (fluxo *push*), ou ser usada hospedagem/VPS com conectividade aprovada. Esse modo alternativo requer implementação própria.

### Parar ou remover o agente no MikroTik
```routeros
/system scheduler disable [find where name="usca-vale-scheduler"]
/system scheduler remove [find where name="usca-vale-scheduler"]
/system script remove [find where name="usca-vale-trigger"]
```
Para revogar o token no servidor, gere outro `.rsc` (isso invalida o anterior) e mantenha-o em local seguro. Em incidente, remova o valor `collector_trigger_sha256` da tabela `app_settings` por administração autorizada.

### Testes automatizados de código
`php tests/trigger_agent.php` verifica validação TLS, hash do token, filtro de URLs e composição do RSC. O CI verifica sintaxe dos scripts, contrato do instalador e frontend, e se o deploy inclui `collect-trigger.php`.

**Limitação de homologação:** teste real de acesso TCP/Modbus a partir da Locaweb e importação física do `.rsc` em RouterOS não podem ser considerados aprovados até serem executados no ambiente do usuário.
