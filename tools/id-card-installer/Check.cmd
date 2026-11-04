@echo off
rem Checks this PC for ID card reading and says what is missing. No administrator rights needed.
rem يفحص الجهاز ويبيّن ما ينقصه لقراءة البطاقة. لا يحتاج صلاحيات المسؤول.
chcp 65001 >nul
title ID card reader check
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0files\Check-CardReader.ps1"
pause
