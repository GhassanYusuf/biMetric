#!/bin/bash
# Assembles the one-click ID card reader package from this folder's scripts (in git) and iGA's
# files (NOT in git: draft/ is ignored, the files are licensed to the organisation).
#   tools/id-card-installer/build-kit.sh [iga-installer-file]
# Output: draft/card-reading/ID-Card-Reader-Setup/ and ID-Card-Reader-Setup.zip
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../.." && pwd)"
src="$root/draft/card-reading"
out="$src/ID-Card-Reader-Setup"
kit="$src/extracted/ID-Card-Reader-Kit"

rm -rf "$out" "$out.zip"
mkdir -p "$out/files" "$out/iGA-installer"
for f in Install.cmd Check.cmd Undo.cmd README.txt; do
  sed 's/\r$//; s/$/\r/' "$here/$f" > "$out/$f"          # Windows line endings
done
cp "$here/Install-CardReader.ps1" "$out/files/"
cp "$kit/files/Check-CardReader.ps1" "$out/files/"                         # the colleague's check, unchanged
cp "$kit/files/cards/BH.CIO.Smartcard.IDCardManager.dll.config" "$out/files/" # iGA's 2025 Bahrain ATR list
cp -r "$src/iga/eRevealer.GCC/Extensions/BAH" "$out/files/BAH"             # iGA's proven Bahrain extension, unchanged
if [ "${1:-}" != "" ]; then
  cp "$1" "$out/iGA-installer/"
else
  printf "Put iGA's SDK installer (GCC CardRead Server, from bahrain.bh) in this folder.\r\n" > "$out/iGA-installer/PUT-THE-INSTALLER-HERE.txt"
fi
(cd "$src" && python3 -m zipfile -c "ID-Card-Reader-Setup.zip" "ID-Card-Reader-Setup")
echo "built: $out ($(du -sh "$out" | cut -f1)), $out.zip ($(du -sh "$out.zip" | cut -f1))"
