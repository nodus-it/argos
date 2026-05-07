#!/usr/bin/env bash
# tests/integration/run-all.sh — fuehrt alle Integration-Tests sequentiell aus.
set -euo pipefail
IFS=$'\n\t'

dir="$(cd "$(dirname "$0")" && pwd)"
rc=0

shopt -s nullglob
for t in "$dir"/test_*.sh; do
    name="$(basename "$t")"
    printf '\n\033[1;33m=== %s ===\033[0m\n' "$name"
    if bash "$t"; then
        printf '\033[1;32m%s OK\033[0m\n' "$name"
    else
        printf '\033[1;31m%s FAILED\033[0m\n' "$name"
        rc=1
    fi
done

exit "$rc"
