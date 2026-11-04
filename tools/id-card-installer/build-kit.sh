#!/bin/bash
# Assembles the one-click ID card reader package from this folder's scripts (in git) and iGA's
# files (NOT in git: draft/ is ignored, the files are licensed to the organisation).
#   tools/id-card-installer/build-kit.sh
# Self-contained (owner, 2026-11-04: "put all in one file, I don't want to install anything from
# the internet"): files/service is iGA's GCC CardRead Server as iGA's installer laid it out on the
# owner's VM (draft/card-reading/iga/GCC CardRead Server), without its backups and install logs.
# Output: draft/card-reading/ID-Card-Reader-Setup/ and ID-Card-Reader-Setup.zip
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../.." && pwd)"
src="$root/draft/card-reading"
out="$src/ID-Card-Reader-Setup"
kit="$src/extracted/ID-Card-Reader-Kit"

rm -rf "$out" "$out.zip"
mkdir -p "$out/files"
for f in Install.cmd Check.cmd Undo.cmd README.txt; do
  sed 's/\r$//; s/$/\r/' "$here/$f" > "$out/$f"          # Windows line endings
done
cp "$here/Install-CardReader.ps1" "$out/files/"
# The colleague's check, with the service found by its program file: iGA's service is named
# SCardReadService (its install log), not SCardReadServer as the check assumed.
sed -e "s/^\$svc = Get-Service SCardReadServer$/\$svc = Get-CimInstance Win32_Service | Where-Object { \$_.PathName -like '*SCardReadWebApi.exe*' } | Select-Object -First 1 | ForEach-Object { Get-Service \$_.Name }/" \
    -e 's/start the "SCardReadServer" service/start the "SCardReadService" service/; s/خدمة SCardReadServer/خدمة SCardReadService/' \
    -e "s/Run the iGA installer (see iGA-installer folder)./Run Install.cmd./; s/ثبّت برنامج الهيئة (انظر مجلد iGA-installer)./شغّل الملف Install.cmd./; s/Run 1-Setup.cmd./Run Install.cmd./; s/شغّل الملف 1-Setup.cmd./شغّل الملف Install.cmd./" \
    "$kit/files/Check-CardReader.ps1" > "$out/files/Check-CardReader.ps1"
# Its test read tries each reader in turn, as Install-CardReader.ps1 does (a keyboard slot next to a
# second reader: the service's own choice was the empty one, 2026-11-04).
python3 - "$out/files/Check-CardReader.ps1" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8-sig').read()
old = "$r = Invoke-RestMethod -Method Post -Uri 'http://localhost:5050/api/operation/ReadCard' -ContentType 'application/json' -Body $body -TimeoutSec 60 -ErrorAction Stop"
new = "$r = $null; foreach ($ix in -1, 0, 1, 2, 3) { $r = Invoke-RestMethod -Method Post -Uri 'http://localhost:5050/api/operation/ReadCard' -ContentType 'application/json' -Body ($body -replace '\"ReaderIndex\":-1', ('\"ReaderIndex\":' + $ix)) -TimeoutSec 60 -ErrorAction Stop; if ($r -is [string]) { $r = $r | ConvertFrom-Json }; if ($r.CardCountry) { break } }"
if s.count(old) != 1: sys.exit("Check-CardReader.ps1: test read not found")
open(p, 'w', encoding='utf-8-sig').write(s.replace(old, new))
PY
grep -q 'PathName -like' "$out/files/Check-CardReader.ps1" || { echo "Check-CardReader.ps1: service lookup not patched" >&2; exit 1; }
svc="$src/iga/GCC CardRead Server"
[ -f "$svc/SCardReadWebApi.exe" ] || { echo "missing $svc" >&2; exit 1; }
mkdir -p "$out/files/service"
(cd "$svc" && find . -mindepth 1 -maxdepth 1 ! -name 'backup-*' ! -name '*.InstallLog' -exec cp -r {} "$out/files/service/" \;)
# Install step 4 takes the Bahrain fix from the bundled service, so it must be the proven one:
# eRevealer's whole Extensions\BAH and iGA's 2025 Bahrain ATR list, byte for byte.
diff -rq "$src/iga/eRevealer.GCC/Extensions/BAH" "$out/files/service/Extensions/BAH" >/dev/null || { echo "bundled Extensions/BAH is not eRevealer's" >&2; exit 1; }
atr() { grep -o '<add key="BAH"[^>]*>' "$1"; }
[ "$(atr "$kit/files/cards/BH.CIO.Smartcard.IDCardManager.dll.config")" = "$(atr "$out/files/service/BH.CIO.Smartcard.IDCardManager.dll.config")" ] || { echo "bundled ATR list differs" >&2; exit 1; }
(cd "$src" && python3 -m zipfile -c "ID-Card-Reader-Setup.zip" "ID-Card-Reader-Setup")
echo "built: $out ($(du -sh "$out" | cut -f1)), $out.zip ($(du -sh "$out.zip" | cut -f1))"
