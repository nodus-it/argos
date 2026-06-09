#!/usr/bin/env bash
# .tools/tests/run-bats.sh — run install.sh's bats tests via docker.
set -euo pipefail
IFS=$'\n\t'

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

if ! compgen -G '.tools/tests/*.bats' >/dev/null; then
    echo "No bats tests under .tools/tests/ — skipping."
    exit 0
fi

docker build -q -t argos-install-bats:latest -f .tools/tests/Dockerfile.bats .tools/tests/ >/dev/null
docker run --rm -v "$repo_root:/code" -w /code argos-install-bats:latest .tools/tests/
