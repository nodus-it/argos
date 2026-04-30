#!/usr/bin/env bash
# tests/run-bats.sh — Bats-Unit-Tests via Docker ausführen.
#
# Bootstrap-Helper: baut das Test-Image (bats + jq) und führt alle .bats-Files
# unter tests/bats/ aus. Wird in Schritt 8 in tests/run-tests.sh integriert.
set -euo pipefail
IFS=$'\n\t'

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root"

if ! [[ -d tests/bats ]] || ! compgen -G 'tests/bats/*.bats' >/dev/null; then
    echo "Keine Bats-Tests unter tests/bats/ gefunden — überspringe."
    exit 0
fi

docker build -q -t agent-bats:latest -f tests/Dockerfile.bats tests/ >/dev/null
docker run --rm -v "$repo_root:/code" -w /code agent-bats:latest tests/bats/
