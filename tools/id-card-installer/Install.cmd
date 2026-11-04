@echo off
rem Sets up Bahrain ID card reading on this PC. Double-click it and approve the Windows prompt.
rem يجهّز هذا الجهاز لقراءة بطاقة الهوية البحرينية. انقر مرتين ووافق على نافذة Windows.
chcp 65001 >nul
net session >nul 2>&1
if errorlevel 1 (
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
title ID card reader setup
set LOG=%~dp0install-log.txt
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Transcript -Path '%LOG%' -Force | Out-Null; try { & '%~dp0files\Install-CardReader.ps1' -KitPath '%~dp0.' } catch { Write-Host ('ERROR: ' + $_.Exception.Message) -ForegroundColor Red } finally { Stop-Transcript | Out-Null }"
echo.
echo Log saved to %LOG%
pause
