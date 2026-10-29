# Publicação do USCA Vale na raiz pública da Locaweb

## Destino efetivo

O site é publicado **diretamente em `public_html/`**, a raiz pública do domínio, **não** em `/home/...` nem `public_html/usca_vale/`. O deploy é feito por **SSH/SFTP/SCP na porta 22**; este servidor rejeitou FTPS explícito na porta 21.

- O workflow atualmente define `FTP_DIR: public_html/` diretamente, prevalecendo sobre a variável de repositório `LOCAWEB_FTP_DIR`. Para evitar confusão, atualize o valor dessa variável em **Settings > Secrets and variables > Actions > Variables** para `public_html/`, ou exclua a variável obsoleta.
- `LOCAWEB_DEPLOY_ENABLED` precisa ser `true` ou estar ausente. Use `false` para suspender os envios.
- Credenciais continuam em GitHub Actions Secrets: `LOCAWEB_FTP_HOST`, `LOCAWEB_FTP_USER`, `LOCAWEB_FTP_PASSWORD`; nunca envie senhas em commits.

[Workflow](../.github/workflows/deploy-locaweb.yml) • [GitHub Actions](https://github.com/wfuzatto/usca_vale/actions)

## Proteção para os demais sites

Antes da transferência, o workflow lista a raiz pelo SFTP. Se houver arquivos e pastas com nomes utilizados pelo USCA Vale (`index.php`, `api.php`, `.htaccess`, `assets/`, `src/`, `sql/` etc.), lê e valida os arquivos principais existentes para **confirmar que pertencem ao USCA Vale**. Se não confirmados, interrompe a publicação sem sobrescrever outro site. O deploy faz upload de uma lista permitida de PHP, CSS, JS, arquivos necessários e `.htaccess`, **não remove** arquivos remotos e **não envia** `config.php`, logs ou backups. Após o upload, baixa e compara três arquivos byte a byte.

Foi confirmada uma cópia anterior do próprio USCA Vale em `public_html/` e o deploy posterior nessa raiz concluiu com sucesso. A cópia antiga em `public_html/usca_vale/` foi preservada; o workflow **não a remove**.

## Banco de dados e coleta

A transferência é apenas dos arquivos da aplicação. Não transfere MySQL, amostras nem executa Modbus na hospedagem. No instalador web, informe host, porta, nome do banco, usuário e senha **específicos da Locaweb**, criando previamente um banco pelo painel se o usuário não tiver permissão `CREATE DATABASE`. A USCA na rede privada precisa de um coletor local e de uma sincronização segura, caso o dashboard remoto deva apresentar dados reais.

O arquivo `config.php` é específico do ambiente e nunca faz parte do pacote. Depois da instalação, confira as proteções HTTP desse arquivo, feche o acesso público ao instalador e garanta backups e acesso somente via HTTPS.
