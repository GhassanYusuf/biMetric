@echo off
rem Puts back what Install.cmd replaced (its latest backup). Approve the Windows prompt.
rem يعيد ما استبدله Install.cmd (آخر نسخة احتياطية). وافق على نافذة Windows.
chcp 65001 >nul
net session >nul 2>&1
if errorlevel 1 (
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
title ID card reader undo
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0files\Install-CardReader.ps1" -KitPath "%~dp0." -Restore
pause
