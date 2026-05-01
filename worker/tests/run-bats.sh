#!/usr/bin/env bash
# worker/tests/run-bats.sh — Bats-Unit-Tests via Docker ausführen.
#
# Bootstrap-Helper: baut das Test-Image (bats + jq) und führt alle .bats-Files
# unter worker/tests/bats/ aus.
set -euo pipefail
IFS=$'\n\t'

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

if ! [[ -d worker/tests/bats ]] || ! compgen -G 'worker/tests/bats/*.bats' >/dev/null; then
    echo "Keine Bats-Tests unter worker/tests/bats/ gefunden — überspringe."
    exit 0
fi

docker build -q -t agent-bats:latest -f worker/tests/Dockerfile.bats worker/tests/ >/dev/null
docker run --rm -v "$repo_root:/code" -w /code agent-bats:latest worker/tests/bats/
