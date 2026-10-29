# Reutilização do mapeamento validado do XAMPP na Locaweb

O arquivo já existente [deif-agc150-mains-13k8.json](../profiles/deif-agc150-mains-13k8.json) é a fonte versionada, não um novo mapa estimado: perfil `deif-agc150-mains-13k8-20261028-r2`, 29 grandezas (23 posições distintas e 6 aliases), 5 verificações de controlador, FC02/FC03/FC04, escalas validadas contra o visor de 13,8 kV e alarmes locais para 90%/110% do nominal.

## O que o deploy faz
O GitHub Actions usa SSH/SCP e publica arquivos em `public_html/` (raiz pública da hospedagem), incluindo `profiles/deif-agc150-mains-13k8.json`, `profiles/.htaccess`, `src/ProfileInstaller.php`, `api.php` e os assets. **Não** publica `config.php`, `backups/` ou banco MySQL. A pasta `profiles/` é protegida para não servir o JSON por HTTP.

**O deploy não aplica automaticamente o mapa no banco de nenhuma instalação.** Isso evita transformar outro equipamento ou alterar registros sem autorização. O passo explícito é obrigatório, mas não é necessário preencher 29 linhas manualmente.

## Aplicar o perfil (apenas uma vez)
1. Acesse o painel Locaweb por HTTPS com login administrativo.
2. Em **Equipamentos** → **Mapeamento de registros Modbus**, selecione o equipamento real cujo modelo seja **AGC150MA / MAINS de 13,8 kV**. Não use este perfil para geradores.
3. No cartão **Perfil validado: DEIF AGC 150 MAINS · 13,8 kV**, clique **Aplicar perfil validado ao controlador selecionado** e confirme.
4. A API autentica a sessão e o CSRF, bloqueia aplicação concorrente com o coletor, e numa transação MySQL faz snapshot dos mapeamentos anteriores em `app_settings.modbus_mapping_backup_<id>`, substitui as grandezas do dispositivo, grava `app_settings.modbus_profile_<id>` com as 5 verificações, muda o papel do equipamento para `mains` e define `mapping_valid_from_<id>`. O histórico e os eventos existentes são preservados.
5. O instalador cria inicialmente regras de tensão **342 V/418 V**, próprias de 380 V. Em uma instalação apenas com este equipamento real, a importação desabilita essas regras genéricas e cria regras `mains_voltage_l12` para **12420 V/15180 V**, histerese **181,57895 V**. Se outros equipamentos reais estiverem ativos, aparece aviso para revisar regras compartilhadas, sem desabilitá-las.
6. Execute **Testar DNS / TCP / Modbus FC04 da Locaweb** e compare com o display da USCA. A importação do mapa, por si só, **não confirma** que a hospedagem alcança a USCA: isso depende de DNS, firewall/NAT e política de TCP de saída da Locaweb.
7. Depois da leitura FC04 válida, importe o agente .rsc gerado pelo painel no MikroTik (se ainda não instalado) e confirme o histórico.

## Segurança
- Antes de ler medições, o coletor confronta as cinco verificações de firmware/identidade e faixas de tensão do AGC150MA. Se não coincidirem, ele **interrompe a gravação** e registra erro: não há fallback para valores inventados.
- Não remove amostras históricas; apenas deixa amostras de um mapa anterior fora das curvas operacionais a partir do novo corte de validação.
- Não envia comandos Modbus de escrita, partida/parada ou alteração de configuração ao controlador.
- O backup da configuração anterior fica no MySQL, não na hospedagem pública; registre e proteja o backup do MySQL antes da primeira importação.
- Revise a topologia real e a versão do controlador, pois a tela só mostra o que foi medido e não prova o cabeamento físico.
