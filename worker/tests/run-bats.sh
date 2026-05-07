#!/usr/bin/env bash
# worker/tests/run-bats.sh — run bats unit tests via docker.
#
# Bootstrap helper: builds the test image (bats + jq) and runs every .bats
# file under worker/tests/bats/.
set -euo pipefail
IFS=$'\n\t'

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

if ! [[ -d worker/tests/bats ]] || ! compgen -G 'worker/tests/bats/*.bats' >/dev/null; then
    echo "No bats tests under worker/tests/bats/ — skipping."
    exit 0
fi

docker build -q -t agent-bats:latest -f worker/tests/Dockerfile.bats worker/tests/ >/dev/null
docker run --rm -v "$repo_root:/code" -w /code agent-bats:latest worker/tests/bats/
