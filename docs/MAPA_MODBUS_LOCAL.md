# Integração local corrigida — AGC 150 Mains

Atualização: 28/10/2026, America/Sao_Paulo. Perfil: `deif-agc150-mains-13k8-20261028-r2`.

## Evidência e origem

- Endpoint configurado localmente em `<IP_DO_CONTROLADOR>:502`, Unit ID TCP 1. Nome lido em FC04/770–775: `AGC150MA`; versão bruta em FC04/500: `1221`.
- A coluna aplicável da planilha enviada é **MAINS**. Os mesmos endereços 501–538 têm significados diferentes nas colunas GEN/MAINS/BTB. No MAINS, FC02/0 é o disjuntor de interligação **TB**, e não o disjuntor do gerador.
- A planilha fornecida é `agc-150-modbus-server-tables-4189341212-uk (4).xlsx`, revisão V, software 1.35.0. O perfil usa os pontos efetivamente lidos e comparados com as fotos do visor; não presume compatibilidade de todos os pontos com qualquer firmware.
- O manual Modbus cobre RS-485/RTU e TCP usando a mesma referência de tabelas. O transporte não explica um multiplicador de dez nos valores. A faixa de engenharia configurada e a variante do controlador precisam ser consideradas.
- O manual comprova parâmetro 6004 (Nom. U1), offset 410: FC03/4410 retornou `1380`; os limites FC03/6410 e 8410 retornaram `40` e `7500`. As fotos confirmam tensões de rede entre 13,8 e 14,2 kV, fase-neutro em torno de 8 kV e potências em MW/MVA. O nominal local é **13,8 kV**.
- As fotos são de um instante anterior: mostram, por exemplo, 14,16/14,01/13,85 kV, 60,02 Hz, 32/29/34 A, 0,80 MVA, 0,02 MW, -0,07 Mvar, energia total de 15,36 MWh e bateria de 24,3 V. Servem para validar unidades, sem exigir igualdade com uma leitura posterior.

## Mapa aplicado

Todos os endereços abaixo são o endereço Modbus base zero, e não a referência PLC 3xxxxx/4xxxxx. Valores de 32 bits usam a word alta primeiro (ABCD).

| Grandeza | FC | Endereço | Tipo | Conversão para armazenamento |
|---|---|---|---|---|
| Rede L1-L2/L2-L3/L3-L1 | 04 | 501/502/503 | s16 | bruto × 10 V |
| Rede L1-N/L2-N/L3-N | 04 | 504/505/506 | s16 | bruto × 10 V |
| Frequência da rede L1 | 04 | 507 | s16 | bruto ÷ 100 Hz |
| Corrente L1/L2/L3 | 04 | 513/514/515 | s16 | bruto em A |
| Potência ativa da rede | 04 | 519 | s16 | bruto × 10 kW |
| Potência reativa da rede | 04 | 523 | s16 | bruto × 10 kvar |
| Potência aparente da rede | 04 | 527 | s16 | bruto × 10 kVA |
| Energia ativa exportada total | 04 | 536–537 | s32 | bruto × 10 kWh |
| Fator de potência da rede | 04 | 538 | s16 | bruto ÷ 100 |
| Bateria/alimentação terminais 1–2 | 04 | 567 | s16 | bruto ÷ 10 V |
| Barramento B L1-L2/L2-L3/L3-L1 | 04 | 539/540/541 | s16 | bruto × 10 V |
| Frequência do barramento B | 04 | 545 | s16 | bruto ÷ 100 Hz |
| TB fechado | 02 | 0 | bool | 0/1 |
| MB fechado | 02 | 1 | bool | 0/1 |
| Rede disponível | 02 | 5 | bool_not | inversão do sinal “Mains failure” |
| Alarmes ativos DEIF | 04 | 28000–28099 | u16 | endereços dos alarmes ativos |

São 29 mapeamentos de grandezas no banco, incluindo aliases `mains_*` e genéricos usados pelos gráficos. Uma leitura compartilhada por aliases é feita uma vez por ciclo para garantir o mesmo valor bruto.

O banco armazena V, kW, kvar, kVA e kWh. A apresentação pode usar kV/MW/MVA. Exemplos: `1395` → `13950 V` → `13,95 kV`; `80` → `800 kVA` → `0,80 MVA`; contador `1536` → `15360 kWh` → `15,36 MWh`. Estes fatores pertencem a este perfil local de média tensão, validado pelas fotos, e não devem ser copiados para um AGC em outra faixa.

## Alarme e histórico

O alarme anterior comparava a leitura de rede com o limite padrão de 418 V do exemplo de 380 V. Ele era inválido para este equipamento. As regras locais passaram a usar 90%/110% do nominal confirmado: **12,420 kV / 15,180 kV**. A histerese anterior foi reescalada na mesma proporção (181,57895 V). São regras do aplicativo; os parâmetros de proteção do controlador não foram alterados.

O evento gerado pela configuração anterior foi encerrado e marcado `mapping_invalidated`, com motivo explícito. Um evento informativo registra a correção. As amostras anteriores foram preservadas no banco e nos backups, mas ficam fora dos gráficos operacionais a partir do corte UTC registrado em `mapping_valid_from_<device_id>`. Não foram reescritas como se tivessem sido coletadas corretamente.

As novas amostras contêm `_provenance`: versão do perfil, função/endereço, words brutas, tipo e expoente por grandeza. Antes de coletar, o perfil confere versão/nome do controlador, nominal U1 e faixa mínima/máxima. Uma divergência interrompe a gravação e informa que o perfil precisa ser revisto.

## Dashboard e estado confirmado

- O endpoint é mostrado como **Concessionária / Mains**. Os cartões dos geradores ficam sem telemetria cadastrada; os valores da rede não são repetidos como geração.
- MB e TB são contatos distintos. Na validação, MB=1, TB=0 e barramento B=0 V/0 Hz. A tela não confirma cargas alimentadas nesse estado.
- O dashboard operacional usa dispositivos reais ativos e exclui eventos simulados ou invalidados; o histórico administrativo conserva os registros.
- O HTML autenticado não usa cache e os scripts têm versão por modificação. Abas que já estavam abertas precisam ser recarregadas.
- A coleta permanece em segundo plano pelo Agendador, a cada 5 segundos. O simulador está desabilitado.

## Validação

`tests/run.php`, `tests/mains_profile.php` e `tests/collector_integration.php` passaram. O teste do perfil cobre os valores e unidades das fotos, incluindo negativos, energia de 32 bits e rejeição de telemetria de gerador no perfil MAINS. Uma amostra real corrigida registrou 13,95/14,03/13,75 kV, 60,01 Hz, 21 A por fase, 510 kVA, 15,72 MWh e bateria de 24,3 V. O dashboard/API/histórico foram conferidos; não havia alarme ativo nem erro de console.

## Fontes

- Planilha local fornecida pelo usuário, revisão V, abas `Descriptions`, `Discrete input contact (02)` e `Input register (04)`.
- [Manual Modbus DEIF 4189341435](https://deif-cdn-umbraco.azureedge.net/media/w25puznj/agc-150-modbus-server-user-manual-4189341435-uk.pdf), especialmente endereços de parâmetros e exemplo Nom. U1.
- [Handbook AGC 150 Generator/Mains/BTB](https://deif-cdn-umbraco.azureedge.net/media/y4sgwxqv/agc-150-generator-mains-btb-designers-handbook-4189341307-uk.pdf), faixas de medição e proteção relativa ao nominal.
- Fotos do visor enviadas pelo usuário em 28/10/2026.
