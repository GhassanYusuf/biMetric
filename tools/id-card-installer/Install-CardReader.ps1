<#
.SYNOPSIS
  One-click setup of Bahrain ID card reading on a Windows PC (owner, 2026-11-04: "install the
  SDK on each PC, but prepare the installation with one click").

.DESCRIPTION
  Run by Install.cmd (double-click it). What it does, in order, each step logged:
    1. Installs iGA's "GCC CardRead Server" if it is not installed yet, from the copy inside this
       package (files\service): nothing is downloaded.
    2. Checks a smart card reader is connected (a warning only: the reader can come later).
    3. Backs up the service's Extensions\BAH folder and its config.
    4. Copies the Bahrain extension that is proven to read current Bahrain cards (eRevealer's
       whole Extensions\BAH, the fix found on 2026-10-27) and the full Bahrain ATR list.
    5. Sets the service to start with Windows, starts it, and reads the inserted card once.
  iGA's files are used exactly as iGA ships them: copied, never changed.
  Personal data from the card is never shown or logged: only whether the read worked.

  -Restore puts the latest backup back (Undo.cmd).
#>
param(
    [string]$KitPath = (Split-Path $PSScriptRoot -Parent),
    [string]$ServicePath = 'C:\Program Files (x86)\CIO\GCC CardRead Server',
    [switch]$Restore
)

$ErrorActionPreference = 'Stop'
# iGA's service registers itself as SCardReadService (its install log); an older note here said
# SCardReadServer. It is found by its program file, so either name works.
$ServiceName = $null
$ConfigName = 'BH.CIO.Smartcard.IDCardManager.dll.config'
$bah = Join-Path $ServicePath 'Extensions\BAH'
$config = Join-Path $ServicePath $ConfigName

function Step($n, $text) { Write-Host ''; Write-Host "[$n] $text" -ForegroundColor Cyan }
function Ok($text) { Write-Host "    OK  $text" -ForegroundColor Green }
function Warn($text) { Write-Host "    !!  $text" -ForegroundColor Yellow }

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Run Install.cmd (it asks Windows for administrator rights).' }

function Get-CardService {
    $w = Get-CimInstance Win32_Service | Where-Object { $_.PathName -like '*SCardReadWebApi.exe*' } | Select-Object -First 1
    if ($w) { Get-Service $w.Name }
}

function Stop-CardService {
    if ($ServiceName -and (Get-Service $ServiceName -ErrorAction SilentlyContinue)) {
        Stop-Service $ServiceName -Force -ErrorAction SilentlyContinue
        # Stop-Service returns before the process lets go of its DLLs.
        $exe = Join-Path $ServicePath 'SCardReadWebApi.exe'
        for ($i = 0; $i -lt 20; $i++) {
            $p = Get-Process -Name SCardReadWebApi -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $exe }
            if (-not $p) { return }
            Start-Sleep -Seconds 1
        }
        $p | Stop-Process -Force
        Start-Sleep -Seconds 2
    }
}

function Start-CardService {
    Set-Service $ServiceName -StartupType Automatic
    Start-Service $ServiceName
    (Get-Service $ServiceName).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
    Ok "$ServiceName is running and starts with Windows."
}

if ($Restore) {
    $backup = Get-ChildItem $ServicePath -Directory -Filter 'backup-oneclick-*' | Sort-Object Name -Descending | Select-Object -First 1
    if (-not $backup) { throw 'No backup made by this installer was found.' }
    Write-Host "Restoring $($backup.FullName)..."
    $ServiceName = (Get-CardService).Name
    Stop-CardService
    Remove-Item $bah -Recurse -Force
    Copy-Item (Join-Path $backup.FullName 'BAH') $bah -Recurse -Force
    Copy-Item (Join-Path $backup.FullName $ConfigName) $config -Force
    Start-CardService
    Write-Host 'Restored.' -ForegroundColor Green
    return
}

# 1. iGA's service ---------------------------------------------------------------------------
Step 1 "iGA GCC CardRead Server"
if (Get-CardService) {
    Ok "already installed ($((Get-CardService).Name))"
} else {
    # Nothing is downloaded (owner, 2026-11-04: "I don't want to install anything from the
    # internet"). files\service is iGA's service exactly as iGA's installer laid it out on a
    # working PC; it is copied into place and registered by .NET's own InstallUtil, which runs
    # the installer class inside SCardReadWebApi.exe - the same thing iGA's installer does
    # (its SCardReadWebApi.InstallLog says so). No iGA file is changed.
    $bundled = Join-Path $KitPath 'files\service'
    if (-not (Test-Path (Join-Path $bundled 'SCardReadWebApi.exe'))) { throw 'The package is incomplete: files\service is missing.' }
    $installUtil = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\InstallUtil.exe'
    if (-not (Test-Path $installUtil)) { throw ".NET Framework 4 is missing ($installUtil). Windows 10 and 11 include it: turn it on in 'Windows features'." }
    Write-Host "    copying the service to $ServicePath..."
    New-Item -ItemType Directory -Path $ServicePath -Force | Out-Null
    Copy-Item (Join-Path $bundled '*') $ServicePath -Recurse -Force
    Get-ChildItem $ServicePath -Recurse -File | Unblock-File
    Write-Host '    registering the Windows service...'
    $ErrorActionPreference = 'Continue'   # InstallUtil's own messages are not script errors
    $out = & $installUtil (Join-Path $ServicePath 'SCardReadWebApi.exe') 2>&1
    $code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    for ($i = 0; $i -lt 10 -and -not (Get-CardService); $i++) { Start-Sleep -Seconds 1 }
    if (-not (Get-CardService)) { $out | ForEach-Object { Write-Host "      $_" }; throw "Registering the service failed (InstallUtil code $code)." }
    Ok "installed ($((Get-CardService).Name))"
}
$ServiceName = (Get-CardService).Name
$exePath = ((Get-CimInstance Win32_Service -Filter "Name='$ServiceName'").PathName).Trim('"')
if ((Split-Path $exePath -Parent) -ne $ServicePath) { throw "The service runs from $(Split-Path $exePath -Parent), not $ServicePath." }

# 2. Reader ----------------------------------------------------------------------------------
Step 2 'Card reader'
$readers = @(Get-PnpDevice -Class SmartCardReader -PresentOnly -ErrorAction SilentlyContinue)
if ($readers.Count -gt 0) { Ok $readers[0].FriendlyName } else { Warn 'No card reader is connected now. Plug it in; nothing else needs to change.' }

# 3. Backup ----------------------------------------------------------------------------------
Step 3 'Backup'
$backupDir = Join-Path $ServicePath ('backup-oneclick-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
Copy-Item $bah (Join-Path $backupDir 'BAH') -Recurse -Force
Copy-Item $config $backupDir -Force
Ok $backupDir

# 4. Bahrain extension -----------------------------------------------------------------------
Step 4 'Bahrain cards (proven extension and ATR list)'
# The bundled service already carries both (checked when the package is built); on a PC whose
# service was installed earlier, this is what brings it up to date.
$source = Join-Path $KitPath 'files\service\Extensions\BAH'
$atrConfig = Join-Path $KitPath "files\service\$ConfigName"
if (-not (Test-Path (Join-Path $source 'BH.CIO.Smartcard.Bahrain.dll'))) { throw "The package is incomplete: files\service\Extensions\BAH is missing." }
$bahLine = [regex]::Match((Get-Content $atrConfig -Raw), '<add key="BAH" value="[^"]*"\s*/>').Value
if (-not $bahLine) { throw "The package is incomplete: no Bahrain ATR list in files\service\$ConfigName." }
try {
    Stop-CardService
    Copy-Item (Join-Path $source '*') $bah -Recurse -Force
    $updated = [regex]::Replace((Get-Content $config -Raw), '<add key="BAH" value="[^"]*"\s*/>', $bahLine)
    [IO.File]::WriteAllText($config, $updated, (New-Object Text.UTF8Encoding($false)))
    Ok "copied $((Get-ChildItem $source -File -Recurse).Count) files into Extensions\BAH and updated the ATR list"
} finally {
    # Never leave the PC without a card service, whatever happened above.
    Step 5 'Service'
    Start-CardService
}

# 6. Test read -------------------------------------------------------------------------------
Step 6 'Test read'
$body = '{"ReadCardInfo":true,"ReadPersonalInfo":true,"ReadAddressDetails":false,"ReadBiometrics":false,"ReadEmploymentInfo":false,"ReadImmigrationDetails":false,"ReadTrafficDetails":false,"SilentReading":true,"ReaderIndex":-1,"ReaderName":"","OutputFormat":"JSON","ValidateCard":false}'
try {
    # Each reader in turn: with two readers (a keyboard slot and another), the service's own choice
    # (-1) can be the empty one (owner's PC, 2026-11-04: -1 and 0 'card removed', 1 read the card).
    $r = $null; foreach ($ix in -1, 0, 1, 2, 3) { $r = Invoke-RestMethod -Method Post -Uri 'http://localhost:5050/api/operation/ReadCard' -ContentType 'application/json' -Body ($body -replace '"ReaderIndex":-1', ('"ReaderIndex":' + $ix)) -TimeoutSec 60 -ErrorAction Stop; if ($r -is [string]) { $r = $r | ConvertFrom-Json }; if ($r.CardCountry) { break } }
    if ($r -is [string]) { $r = $r | ConvertFrom-Json }
    if (-not [string]::IsNullOrWhiteSpace($r.EnglishFullName) -and $r.CardCountry -eq 'BAH') { Ok 'a Bahrain card was read with its names' }
    else { Warn "a card answered (country '$($r.CardCountry)') but without names" }
} catch {
    Warn 'no card read (insert a Bahrain ID card and run Check.cmd)'
}

Write-Host ''
Write-Host 'Setup finished. In the system, open an employee and press "Read ID card".' -ForegroundColor Green
Write-Host "To undo: Undo.cmd (backup: $backupDir)"
