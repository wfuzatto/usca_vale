# Run PowerShell elevated AFTER installing the application in XAMPP and visiting install.php.
param([string]$Root='C:\xampp\htdocs\usca_vale',[string]$Php='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
if(!(Test-Path "$Root\config.php")){throw 'Abra http://localhost/usca_vale/install.php antes de registrar o serviço.'}
if(!(Test-Path $Php)){throw "PHP não encontrado: $Php"}
$action=New-ScheduledTaskAction -Execute $Php -Argument ('"' + (Join-Path $Root 'bin\collect.php') + '"') -WorkingDirectory $Root
$trigger=New-ScheduledTaskTrigger -AtStartup
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Seconds 0) -RestartInterval (New-TimeSpan -Minutes 1) -RestartCount 3 -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName 'USCA Vale Coletor' -Action $action -Trigger $trigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Start-ScheduledTask -TaskName 'USCA Vale Coletor'
$backupAction=New-ScheduledTaskAction -Execute $Php -Argument ('"' + (Join-Path $Root 'bin\backup.php') + '"') -WorkingDirectory $Root
$backupTrigger=New-ScheduledTaskTrigger -Daily -At 2:10am
Register-ScheduledTask -TaskName 'USCA Vale Backup' -Action $backupAction -Trigger $backupTrigger -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Write-Host "Coleta e backup configurados. Abra http://localhost/usca_vale/" -ForegroundColor Green
Write-Host "Status: Get-ScheduledTask -TaskName 'USCA Vale Coletor'"
