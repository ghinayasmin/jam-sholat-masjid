@echo off
rem Reset kata sandi panel takmir (padanan "sudo jamsholat-reset-password" di Linux).
rem Pintasan "Reset kata sandi" di Start Menu menjalankan file ini.
setlocal
set "ROOT=%~dp0..\..\.."
title Reset kata sandi - Jam Sholat Masjid
"%ROOT%\php\php.exe" "%ROOT%\app\scripts\reset_password_cli.php" %*
echo.
pause
