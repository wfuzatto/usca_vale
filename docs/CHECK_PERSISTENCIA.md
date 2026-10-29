# Conferir persistência real no XAMPP

A tela **Online** confirma telemetria recente, mas não prova sozinha a retenção histórica. Execute o diagnóstico SOMENTE LEITURA com o coletor normalmente funcionando.

Abra um terminal PowerShell no computador do XAMPP:

```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\usca_vale\bin\diagnose.php" --observe=15
```

O comando verifica cada dispositivo: modo real/simulação, tentativas e erros de coleta, últimas amostras gravadas, contagem em 5 minutos/1 hora/24 horas, primeiro registro do período válido, perfil de mapeamento, nomes das grandezas no JSON, e evolução da contagem após 15 segundos. Não lê nem modifica equipamentos Modbus e não altera o banco. Pode levar mais de 15 segundos de relógio para exibir o resultado.

**Interpretação:**
- `OK - novas amostras persistidas`: houve gravação no MySQL durante a observação.
- `SEM NOVAS AMOSTRAS` com coleta ativa: consulte `var/logs/collector.log`, a tarefa `USCA Vale Coletor`, `last_error`, falhas do banco e configuração do mapa.
- `last_5min=0`, mas `last_24h>0`: houve gravações no passado, mas não nos últimos 5 minutos.
- `corte historico` preenchido: dados anteriores ao perfil validado são preservados no MySQL e ocultados nos gráficos operacionais, por segurança.
- `grandezas` sem `current_l1`, `power_factor` etc.: o mapeamento ou a leitura não está gerando aquela grandeza.
- Se o script indicar dados e a tela ainda estiver vazia, inspecione no navegador a resposta autenticada de `api.php?action=history`, filtro de dispositivo, janela UTC e Console de JavaScript. Os gráficos **consultam** a tabela `samples`, não gravam o histórico por si mesmos.

Pode-se verificar independentemente em phpMyAdmin (SQL; somente leitura):

```sql
SELECT device_id, COUNT(*) AS amostras,
       MIN(taken_at) AS primeira_utc, MAX(taken_at) AS ultima_utc,
       SUM(taken_at >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE) AS ultimos_5min
FROM usca_vale.samples
GROUP BY device_id;
```

Em uma captura pontual e sem acesso ao MySQL local, NÃO se deve afirmar que a persistência da máquina está confirmada. O script serve para demonstrá-la diretamente.
