# Leitura da rede e do barramento / tooltip de graficos

O controlador AGC 150 com papel **MAINS** possui duas medicoes eletricas diferentes:

- **M (rede/concessionaria):** chaves \`mains_voltage_l12\` e \`mains_frequency\` (mapa MAINS FC04/501 e FC04/507).
- **BB (barramento B):** chaves \`bus_voltage_l12\` e \`bus_frequency\` (mapa MAINS FC04/539 e FC04/545).

Nas fotografias do usuario, a rede apresenta cerca de **14 kV / 60 Hz**, enquanto o **BB apresenta 0 kV / 0 Hz**. Nao era correto exibir 14 kV no BB como se a tensao da rede fosse a tensao efetivamente medida no barramento. O unifilar agora apresenta separadamente **M:** e **BB:**. Quando uma leitura nao existe, mostra indisponibilidade e **nunca copia a grandeza da rede para o BB**. O estado de alimentacao da carga depende de evidencias de disjuntor e barramento, nao apenas da presenca da rede.

Os seis minigraficos do dashboard e o grafico da pagina Historico usam a mesma implementacao de tooltip em canvas. Ao aproximar o mouse do ponto tracado, exibem hora no fuso America/Sao_Paulo, nome da serie, valor numerico e unidade; uma guia vertical mostra a amostra escolhida. Sao **medias agregadas por janela** retornadas pela API, nao amostras instantaneas; pontos ausentes nao sao inventados. Os atributos nao alteram banco, coletor ou mapa Modbus.

Para testar sintaxe e interacao: \`node --check assets/chart-hover.js\`, \`node --check assets/app.js\`, \`node --check assets/visual-dashboard.js\` e \`node tests/chart_hover_contract.cjs\`.

Versoes: no XAMPP local, execute \`git pull --ff-only origin main\`, depois Ctrl+F5. O workflow GitHub publica apenas os arquivos web na Locaweb; atualizar o GitHub **nao** instala automaticamente a nova versao no PC XAMPP.
