# Deploy automático do USCA Vale na Locaweb

Este fluxo publica **somente arquivos PHP da aplicação e assets**. Não é migração do coletor Modbus nem do MySQL local; a Locaweb não conseguirá acessar uma USCA privada sem uma arquitetura de coleta apropriada.

## Ativar com segurança

1. No painel Locaweb, abra **Hospedagem de Sites > Administrar > Arquivos e FTP**. Copie o **Host FTP** (ou alternativo, se DNS ainda não aponta), **Usuário FTP** e confirme a **porta**. O IP compartilhado e a pasta `/home/...` não substituem esses campos. Priorize FTPS explícito (TLS) em porta 21; se esta hospedagem não suportar FTPS, não habilite FTP inseguro sem avaliar a alternativa SFTP/SSH.
2. Abra [Secrets and variables > Actions](https://github.com/wfuzatto/usca_vale/settings/secrets/actions). Em **Secrets**, cadastre:
   - `LOCAWEB_FTP_HOST`: host FTP informado pela Locaweb.
   - `LOCAWEB_FTP_USER`: usuário FTP da hospedagem.
   - `LOCAWEB_FTP_PASSWORD`: senha FTP (não publicar no código, issue, commit ou chat).
3. Na aba **Variables**, crie:
   - `LOCAWEB_FTP_DIR`: pasta **vista a partir da raiz da sessão FTP**, terminada em `/usca_vale/`. Por exemplo, `public_html/usca_vale/` **se** o FTP começar na raiz da conta. Se o FTP já abrir dentro de `public_html`, use `usca_vale/`. Confirme isso antes usando o gerenciador WebFTP/FileZilla. NÃO use `/home/valedamantiqueir1/`.
   - `LOCAWEB_DEPLOY_ENABLED`: `true` para liberar publicação. Ausente ou `false` deixa o workflow desativado.
4. Em **Actions > Publicar USCA Vale na Locaweb (FTPS) > Run workflow**, mantenha marcada a opção **Simular sem enviar arquivos** para analisar o destino e os arquivos que seriam enviados. Após revisar, execute novamente com a opção desmarcada.
5. Depois de habilitado, cada novo `push` na branch `main` publica automaticamente os arquivos pelo FTPS.

## Proteção

- O job valida que o destino é exclusivamente uma pasta `usca_vale/`; impede deploy na raiz da hospedagem.
- Faz pacote com lista de arquivos permitidos. **Não inclui** `config.php`, `config.example.php`, `backups/`, `var/`, `bin/`, `scripts/`, `tests/`, `profiles/`, documentação ou segredos.
- Não ativa **dangerous-clean-slate**. Ao publicar em pasta existente, faça backup antes do primeiro deploy; o sincronizador pode atualizar ou remover arquivos que rastrear.
- Não grava ou altera credenciais FTP no repositório. A senha deve ficar apenas como GitHub Actions Secret.
- Um estado de sincronização é armazenado na pasta publicada como `.ftp-deploy-sync-state.json`; o `.htaccess` da aplicação nega acesso HTTP a ele.
- A pasta `sql/` está protegida por `.htaccess` mas contém o esquema necessário ao instalador. Confirme que o Apache da hospedagem honra essas regras.

## Limitações da hospedagem

- O instalador atual foi criado para XAMPP e tenta criar banco `usca_vale`. Na Locaweb, normalmente o banco precisa ser provisionado no painel e pode ter nome/usuário próprios; não execute o instalador sem adaptar esse passo.
- O servidor web na Locaweb, por si só, **não pode consultar a USCA numa LAN privada**. Deixe o coletor rodando em equipamento local energizado, e planeje sincronização segura dos dados por HTTPS ao banco/API remota (sem abrir o Modbus TCP à internet).
- A Locaweb limita cron de hospedagem compartilhada; não conte com um processo coletor PHP permanente na hospedagem.
- O endereço web final depende do domínio apontado para a pasta `public_html` e de onde a pasta `usca_vale` foi criada. Verifique pelo WebFTP.

## Diagnóstico de erros

- **Host unreachable / credenciais inválidas**: confira o host FTP e login em Arquivos e FTP.
- **TLS error**: confirme FTPS explícito disponível. Não desative a validação de certificado; avalie SFTP.
- **550 / acesso negado**: confirme a pasta relativa correta e permissões da conta FTP.
- **Job skipped**: defina `LOCAWEB_DEPLOY_ENABLED=true` nas Variables, após configurar Secrets e diretório.
- **Site sem dados**: o deploy FTP não sincroniza o banco local e não inicia o coletor local.
