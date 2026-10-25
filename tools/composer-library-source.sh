#!/usr/bin/env bash
# Where composer fetches promoseven/biometric-protocol from, on THIS machine.
#
#   tools/composer-library-source.sh status   # show the current source
#   tools/composer-library-source.sh folder   # fall back to the local working copy
#   tools/composer-library-source.sh github   # back to GitHub (the default)
#
# composer.json always names GitHub. It cannot name a fallback: tested 2026-10-25 on
# Composer 2.10.3, an unreachable vcs repository is fatal whatever its position or
# "canonical" setting, so a path repository listed beside it is never reached (and a
# path repository listed first wins outright, as dev-main, against ^2.16).
#
# So the fallback is done by git, below composer: an insteadOf rule that sends the
# GitHub URL to the local folder. The folder holds the same commits and tags, so
# composer.lock does not change. Only committed, tagged work in the folder is
# installable this way, which is what the lock pins anyway.
#
# Composer's own cache already covers a GitHub outage on a machine that has fetched
# the library once. This switch is for a machine that cannot reach GitHub at all.
#
# Writes only the invoking user's global git config. Run as the user who runs
# composer (root on the production box).
set -euo pipefail

GITHUB_URL='git@github.com:GhassanYusuf/biometric-protocol.git'
FOLDER="${BIOMETRIC_LIBRARY_FOLDER:-/var/biometric-protocol}"

current() {
    git config --global --get-regexp '^url\..*\.insteadof$' 2>/dev/null \
        | awk -v u="$GITHUB_URL" '$2 == u { sub(/^url\./, "", $1); sub(/\.insteadof$/, "", $1); print $1 }'
}

case "${1:-status}" in
    folder)
        if ! git -C "$FOLDER" rev-parse --git-dir >/dev/null 2>&1; then
            echo "Not a git repository: $FOLDER" >&2
            exit 1
        fi
        git config --global "url.$FOLDER.insteadOf" "$GITHUB_URL"
        echo "composer now fetches the library from $FOLDER"
        ;;
    github)
        prev="$(current)"
        if [ -n "$prev" ]; then
            git config --global --unset-all "url.$prev.insteadOf" "^$(printf '%s' "$GITHUB_URL" | sed 's/[.]/\\./g')\$"
        fi
        echo "composer now fetches the library from GitHub"
        ;;
    status)
        prev="$(current)"
        echo "library source: ${prev:-GitHub ($GITHUB_URL)}"
        ;;
    *)
        echo "usage: $0 status|folder|github" >&2
        exit 2
        ;;
esac
