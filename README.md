# USCA Vale — Monitoramento elétrico e geradores

Dashboard em **PHP 8.1+, Bootstrap 5, JavaScript e MariaDB/MySQL**, projetado para **XAMPP sem Docker**. Possui coletor CLI separado da interface, simulação, histórico e alarmes. **Não é sistema de proteção elétrica** e **não envia comandos de controle**.

## Instalação guiada — Windows / XAMPP

1. Instale XAMPP (Apache + MySQL/MariaDB + PHP), com `pdo_mysql`, `json`, `openssl` e `zlib` habilitados. Ligue **Apache** e **MySQL** no XAMPP Control Panel.
2. Copie a pasta do projeto para `C:\xampp\htdocs\usca_vale\`. Não copie só os arquivos da pasta `assets`.
3. Navegue a `http://localhost/usca_vale/install.php`. Informe **servidor de banco (localhost)**, **IP da USCA** e **porta (502 por padrão)**, crie uma senha administrativa com pelo menos 12 caracteres. No XAMPP recém-instalado, o MySQL costuma usar `root` sem senha; se não funcionar, expanda “Credenciais avançadas do banco”. O instalador cria o banco `usca_vale`, todas as tabelas, usuário `admin`, um simulador habilitado e um controlador real inicialmente desabilitado.
4. Acesse `http://localhost/usca_vale/` com usuário `admin` e a senha criada.
5. Abra PowerShell **como Administrador** e rode:

   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass
   & 'C:\xampp\htdocs\usca_vale\scripts\install_windows.ps1'
   ```

   O script instala uma **Tarefa Agendada de inicialização** para o coletor contínuo (`php.exe bin\collect.php`), e outra tarefa diária para `bin\backup.php`. Para testar imediatamente no terminal:

   ```powershell
   & 'C:\xampp\php\php.exe' 'C:\xampp\htdocs\usca_vale\bin\collect.php' --once
   ```

   `--once` respeita o intervalo de coleta. Aguarde a coleta normal, em seguida atualize o navegador. O simulador deverá ter medições e, ao passar pelas fases, eventos automáticos. Se o serviço não iniciar, abra o **Agendador de Tarefas** e inspecione `USCA Vale Coletor` e o Visualizador de Eventos.
6. Para permitir consultas por outros PCs da LAN, libere apenas HTTP/HTTPS do Apache no firewall para a sub-rede autorizada, por exemplo `http://IP_DO_SERVIDOR/usca_vale/`. **Nunca** libere a porta TCP 502 da USCA para a internet. Fora da LAN, use VPN e HTTPS autenticado.
7. **Para habilitar dados reais:** em **Equipamentos** edite o DEIF, confira IP/porta, **Unit ID** e versão de firmware; configure **cada grandeza** com FC, endereço, tipo e escala conforme tabela DEIF revisada para sua instalação, teste a conexão, e só então habilite coleta. A simulação não se mistura com o dispositivo real.

## Instalação Linux / Apache + MariaDB

Copie a pasta para `/var/www/html/usca_vale`. Garanta PHP CLI e pacote `php-mysql`, Apache, MariaDB e permissões de leitura do usuário `www-data`. Abra `/usca_vale/install.php`, configure e depois execute:

```bash
sudo bash /var/www/html/usca_vale/scripts/install_linux.sh /var/www/html/usca_vale
systemctl status usca-vale.service
journalctl -u usca-vale.service -f
```

O serviço é supervisionado pelo `systemd`, que reinicia automaticamente o coletor; o timer faz backup diário.

## Manual de uso

- **Visão geral:** alimentação, estado do gerador, eventos abertos, unifilar conceitual, curvas e grandezas lidas; dados velhos aparecem indisponíveis.
- **Histórico & gráficos:** escolha dispositivo, data inicial/final e até 12 curvas. A consulta calcula média por janelas proporcionais ao intervalo. Exibe lacunas, não interpola perda de dados.
- **Alarmes & eventos:** pesquisa por período/dispositivo/severidade, início/fim, duração, mínimo/máximo, reconhecimento e CSV. Botão “Salvar PDF” usa a opção **Imprimir > Salvar como PDF** do navegador.
- **Equipamentos:** múltiplos controladores, IP, porta, Unit ID, intervalo, simulador e mapa de registradores. Botão Testar TCP verifica apenas a conectividade da porta, não interpreta sensores.
- **Configurações:** regras com limite, histerese, tempo mínimo e senha administrativa.
- **Backups:** em `backups/` (protegida contra acesso HTTP). Guarde cópias fora do servidor e teste restauração. Restaurar com `mysql -u root -p usca_vale < dump.sql` após descompactação.

## Integração DEIF AGC 150: mapeamento seguro

Documentação consultada:
- https://www.deif.com/documentation/agc-150-stand-alone
- [Tabela oficial AGC 150 4189341212 (XLSX)](https://deif-cdn-umbraco.azureedge.net/media/f2hiadel/agc-150-modbus-server-tables-4189341212-uk.xlsx)
- [Manual Modbus server 4189341435-B](https://deif-cdn-umbraco.azureedge.net/media/w25puznj/agc-150-modbus-server-user-manual-4189341435-uk.pdf)

O manual (base de **software 1.37**) define: funções 01/02/03/04 para leitura; bloco FC04 de endereços **0 a 499 configurável**, espelhando registros de **500 a 1999**. Os alarmes ativos podem ser consultados via **FC04 28000–28099** (o valor retornado é endereço de alarme, cuja descrição depende de parâmetro no USW). `INT16`, `UINT16`, `INT32`, `UINT32`, `BOOL`; para 32 bits o primeiro registro contém HI16 (ABCD). A fórmula de conversão é `valor = registro × 10^(−escala)`.

Os endereços devem ser escolhidos na coluna correspondente ao tipo do controlador (MAINS, GEN, BTB etc.). O manual usa a mesma referência de tabelas para TCP e RS-485/RTU; a faixa de engenharia configurada no controlador também afeta a interpretação. A leitura de alarmes usa a faixa documentada, porém os códigos precisam de descrição no software DEIF. Se a leitura falhar, não se assume que não existem alarmes.

### Perfil MAINS de média tensão

O perfil [deif-agc150-mains-13k8.json](profiles/deif-agc150-mains-13k8.json) registra os endereços e fatores validados com a configuração nominal e fotos de um AGC 150 Mains em 13,8 kV. O [mapa de integração](docs/MAPA_MODBUS_LOCAL.md) explica as unidades, MB/TB, proveniência e correção de leituras anteriores. O perfil é específico desse comissionamento e não é ativado automaticamente em outras instalações.

- As medições de rede não são exibidas como dados de gerador. Grandezas indisponíveis ficam como `—`.
- Novas amostras de um perfil guardam words brutas e versão em `_provenance`; os aliases compartilham uma única leitura por ciclo.
- Um perfil em `app_settings.modbus_profile_<device_id>` pode definir verificações de versão, nome, nominal e faixa antes da gravação.
- `mapping_valid_from_<device_id>` marca a primeira data de um mapa validado. Amostras anteriores permanecem no banco e são excluídas dos gráficos operacionais.
- `dashboard_real_only=1` limita o resumo operacional a dispositivos reais ativos. Eventos simulados e invalidados continuam no histórico administrativo.
- `BOOL invertido` converte um sinal de falha em disponibilidade. Em bases existentes, execute [a migração do tipo](sql/migrations/20261028_mapping_bool_not.sql) no banco antes de usar `bool_not`; instalações novas já recebem o tipo pelo schema.

A implementação executa apenas leituras Modbus TCP **01/02/03/04**. Não oferece partida, parada, desligamento, escrita de parâmetros, reset, disjuntores ou outros comandos remotos. Preferir rede industrial segregada, ACL limitando acesso ao controlador e backup das configurações da USCA.

## Diagnóstico e testes

```bash
php -l api.php
php tests/run.php
php tests/modbus_tcp.php
php tests/mains_profile.php
php bin/collect.php --once
php bin/collect.php --once --phase=sag
php bin/collect.php --once --phase=outage
```

O simulador repete aproximadamente a cada 5 minutos: operação normal, subtensão, falta de rede, partida, alimentação pelo gerador e restabelecimento. `--phase` é somente para o simulador em instalações de desenvolvimento (não muda o equipamento real). Use ambiente de teste separado antes de ensaios artificiais.

`php tests/collector_integration.php` exercita falha e recuperação com TCP local e banco de teste configurado em `config.php`; cria e remove um dispositivo descartável. Execute com o coletor agendado parado e em uma instalação de teste.

### Limitações relevantes / checklist para comissionamento

1. **Confirmar número e função dos controladores:** AGC 150 Stand-alone, controle da rede vs. geradores, topologia real, disjuntores, paralelismo e fonte que alimenta o barramento.
2. **Confirmar firmware, Unit ID, registros e escalas por equipamento** na tabela oficial/DEIF USW; portas Modbus no controlador. O IP/porta por si só não definem quais grandezas podem ser lidas.
3. **Confirmar cabeamento/medição:** existência de TCs/TPs, relações configuradas, sensores de óleo/temperatura, medição de concessionária versus gerador. O sistema não deduz sensores ausentes nem corrige relações elétricas.
4. **Sincronismo e relógio:** usar NTP confiável; dados UTC gravados, exibidos localmente no fuso `America/Sao_Paulo`. Cronologia de transferência com resolução máxima do tempo de amostragem.
5. **Garantir continuidade:** UPS para controlador/switch/servidor, acesso MySQL protegido, backup fora da máquina, monitoramento do coletor, testes de reinício e plano de restauração.
6. **Histórico volumoso:** em 5 s, um dispositivo gera ~6,3 milhões de amostras/ano. Planeje disco/índices, retenção e eventual pré-agregação de longo prazo.
7. **Eventos rápidos:** aquisição a cada 1–5 s **não captura transitórios de milissegundos** e não constitui analisador Classe A. Para PQ, surtos, harmônicas, flicker e afundamentos rápidos, integrar medidor dedicado.
8. **Alarmes externos:** notificações por e-mail/Telegram, mapeamento de nomes de alarmes DEIF, perfis de usuários, múltiplas fontes no unifilar e importação automática da planilha oficial são melhorias a implementar e validar antes de operação crítica. Esta entrega inicial possui reconhecimento, alarmes por limites, eventos e logs, sem notificação externa.
9. **Validação física por instalação:** o perfil MAINS documentado foi conferido com leituras reais e fotos. Outros modelos, registradores, faixas e comportamento de reconexão exigem validação própria; o teste simulado não substitui essa conferência.

## Estrutura

`install.php` instala MariaDB/MySQL. `api.php` atende consultas autenticadas. `index.php` exibe o dashboard Bootstrap. `src/ModbusClient.php` contém o cliente somente leitura. `src/Collector.php` faz persistência, simulação, detecção e coleta. `bin/collect.php` executa o daemon. `bin/backup.php` executa backup. `scripts/` contém instaladores de serviço. `tests/` reúne testes locais de decodificação Modbus. `backups/` deve permanecer inacessível pela web.


## Interface aprovada — painel v1.1

O visual do painel principal foi reconstruído com base na imagem aprovada (1536 × 1024):
barra superior azul-marinho, menu lateral à esquerda, quatro cartões de concessionária
e geradores, unifilar conceitual, alarmes ativos, última comunicação, seis mini-gráficos
e tabela de eventos recentes. A imagem de referência acompanha o pacote ZIP entregue, em `docs/referencia-dashboard-aprovado.png`.

- **Não são usados os valores da imagem como dados reais.** Campos indisponíveis ficam como `—`.
- Cartões e diagrama mostram estado **apenas** se existem leituras válidas para ele.
- A identificação `SIMULAÇÃO` permanece visível nos cartões simulados.
- O botão **Editar Topologia** salva rótulos no banco `app_settings` e não envia comandos aos equipamentos.
- Os gráficos do dashboard consultam a API `history`, com 5 min / 1 h / 24 h / 7 dias / 30 dias.
- O novo CSS está em `assets/mockup-exato.css`; o JavaScript está em `assets/visual-dashboard.js`.
- O menu **Backup** apresenta instruções; backup de verdade continua restrito ao script local `bin/backup.php`.

Para atualizar uma instalação anterior: faça backup do código e banco, substitua os
arquivos preservando `config.php`, atualize a página com Ctrl+F5 e verifique o serviço coletor.
A mudança visual não altera dados existentes. O suporte a `bool_not` requer a migração indicada acima em bancos criados antes dessa opção.
