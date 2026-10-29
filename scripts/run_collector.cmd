@echo off
setlocal
set "ROOT=%~dp0.."
set "PHP=%~1"
set "LOG_DIR=%ROOT%\var\logs"
set "LOG=%LOG_DIR%\collector.log"
set "OLD_LOG=%LOG_DIR%\collector.previous.log"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
if not exist "%PHP%" exit /b 2

:restart
if exist "%LOG%" for %%F in ("%LOG%") do if %%~zF GTR 10485760 move /Y "%LOG%" "%OLD_LOG%" >nul
echo [%date% %time%] Starting bin\collect.php>>"%LOG%"
"%PHP%" "%ROOT%\bin\collect.php" >>"%LOG%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
echo [%date% %time%] bin\collect.php exited with code %EXIT_CODE%; retrying in 5 seconds.>>"%LOG%"
timeout /t 5 /nobreak >nul
goto restart
