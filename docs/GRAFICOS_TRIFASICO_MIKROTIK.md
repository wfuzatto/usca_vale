# Revisão dos gráficos e medições trifásicas

## Por que os gráficos de cinco minutos não mostravam linhas
A consulta histórica agregava por janelas de 1 segundo, mas o equipamento era lido a cada 5 segundos. O canvas antigo só ligava pontos cuja distância fosse menor ou igual a 2,6 segundos (janela de consulta × 2,6), transformando cada amostra em um `moveTo` sem segmento de linha. O tooltip continuava mostrando os valores, porque as amostras existiam.

**Correção:** a API de histórico devolve também `poll_seconds` do equipamento. A visualização liga dois pontos quando a separação cabe no maior dos intervalos de agregação ou de coleta, multiplicado por 2,5. Pontos isolados são marcados individualmente, sem ligar períodos de ausência de comunicação. Também foi corrigido o gráfico de investigação histórica.

## Diagrama e grandezas trifásicas
O diagrama unifilar representa um circuito trifásico por uma única linha. É válido como esquema, mas **não confirma a topologia física**. Abaixo dele, foi acrescentada uma tabela visível de três fases, cada uma com:
- tensão fase-fase da rede `M`: `mains_voltage_l12/l23/l31`
- tensão fase-neutro medida no MAINS: `voltage_l1n/l2n/l3n`
- correntes L1/L2/L3: `current_l1/l2/l3`
- tensão fase-fase do barramento `BB`: `bus_voltage_l12/l23/l31`
- frequência da rede e frequência do barramento, disponíveis apenas como medidas globais

Nenhuma corrente é atribuída ao barramento sem mapa próprio; nenhuma leitura do MAINS é exibida como se fosse dos geradores. Dados sem leitura atual mostram um traço em vez de valor inventado.

O esquema existente de MB, TB e geradores permanece **conceitual**, e só poderá ser apresentado como fiel à instalação depois de validar o diagrama elétrico real, relação de TCs/TPs e estados dos disjuntores. Rede medida de 13,8 kV não é a mesma que a saída de cargas de 380 V.

## MikroTik
O `/tool fetch` do RouterOS suporta requisições HTTP/HTTPS, mas não permite montar uma sessão Modbus/TCP bruta (MBAP) com leitura FC03/FC04. Ele pode funcionar como um **transportador HTTPS** para um coletor que já tenha lido o equipamento. Para coletar Modbus diretamente de um MikroTik, seria necessário usar um container RouterOS v7 em hardware suportado (ARM, ARM64 ou x86) com recursos livres; não é aconselhável adicionar esse processo a um router de produção crítico sem testes de carga. Uma alternativa melhor é um minicomputador local com UPS e um serviço Modbus read-only, enviando amostras ao servidor Locaweb via HTTPS autenticado com fila e reenvio. Não exponha a porta Modbus da USCA à internet.

## Testes
```bash
node --check assets/chart-series.js
node --check assets/visual-dashboard.js
node --check assets/app.js
node tests/chart_series_contract.cjs
node tests/dashboard_phase_contract.cjs
php -l api.php
```
