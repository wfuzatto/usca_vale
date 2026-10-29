# Run PowerShell elevated AFTER installing the application in XAMPP and visiting install.php.
param([string]$Root='C:\xampp\htdocs\usca_vale',[string]$Php='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
$Root=(Resolve-Path -LiteralPath $Root).Path
if(!(Test-Path -LiteralPath (Join-Path $Root 'config.php'))){throw 'Abra http://localhost/usca_vale/install.php antes de registrar o serviço.'}
if(!(Test-Path -LiteralPath $Php)){throw "PHP não encontrado: $Php"}
$runner=Join-Path $Root 'scripts\run_collector.cmd'
if(!(Test-Path -LiteralPath $runner)){throw "Executor do coletor não encontrado: $runner"}

# Keep runtime files out of the web UI and source control.
$logDir=Join-Path $Root 'var\logs'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null
if(!(Test-Path -LiteralPath (Join-Path $Root 'var\.htaccess'))){Set-Content -LiteralPath (Join-Path $Root 'var\.htaccess') -Value "Require all denied" -Encoding ascii}
if(!(Test-Path -LiteralPath (Join-Path $Root 'backups\.htaccess'))){Set-Content -LiteralPath (Join-Path $Root 'backups\.htaccess') -Value "Require all denied" -Encoding ascii}

# The Apache and MariaDB XAMPP services run as LocalSystem; administrators retain management access.
$caller=(& whoami).Trim()
$configPath=Join-Path $Root 'config.php'
if(Test-Path -LiteralPath $configPath){
  & icacls.exe $configPath /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' "${caller}:F" | Out-Null
  if($LASTEXITCODE -ne 0){throw "Não foi possível restringir permissões de $configPath"}
}
foreach($path in @($logDir,(Join-Path $Root 'var'),(Join-Path $Root 'backups'))){
  if(Test-Path -LiteralPath $path){
    $items=@((Get-Item -LiteralPath $path -Force))+@(Get-ChildItem -LiteralPath $path -Force -Recurse -ErrorAction Stop)
    foreach($item in $items){
      if($item.PSIsContainer){
        & icacls.exe $item.FullName /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "${caller}:(OI)(CI)F" | Out-Null
      } else {
        & icacls.exe $item.FullName /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' "${caller}:F" | Out-Null
      }
      if($LASTEXITCODE -ne 0){throw "Não foi possível restringir permissões de $($item.FullName)"}
    }
  }
}

$taskName='USCA Vale Coletor'
$cmdExe=Join-Path $env:SystemRoot 'System32\cmd.exe'
$taskArgs='/d /c ""{0}" "{1}""' -f $runner,$Php
$action=New-ScheduledTaskAction -Execute $cmdExe -Argument $taskArgs -WorkingDirectory $Root
$trigger=New-ScheduledTaskTrigger -AtStartup -RandomDelay (New-TimeSpan -Minutes 1)
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Seconds 0) -RestartInterval (New-TimeSpan -Minutes 1) -RestartCount 999 -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Start-ScheduledTask -TaskName $taskName

$backupAction=New-ScheduledTaskAction -Execute $Php -Argument ('"' + (Join-Path $Root 'bin\backup.php') + '"') -WorkingDirectory $Root
$backupTrigger=New-ScheduledTaskTrigger -Daily -At 2:10am
$backupSettings=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1) -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName 'USCA Vale Backup' -Action $backupAction -Trigger $backupTrigger -Settings $backupSettings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Write-Host "Coleta de inicialização e backup diário configurados. Abra http://localhost/usca_vale/" -ForegroundColor Green
Write-Host "Status: Get-ScheduledTask -TaskName 'USCA Vale Coletor'"
Write-Host "Logs: $logDir\collector.log"
