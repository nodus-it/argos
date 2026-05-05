#!/usr/bin/env bash
# installer/tests/run-bats.sh — run install.sh's bats tests via docker.
set -euo pipefail
IFS=$'\n\t'

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

if ! compgen -G 'installer/tests/*.bats' >/dev/null; then
    echo "No bats tests under installer/tests/ — skipping."
    exit 0
fi

docker build -q -t argos-install-bats:latest -f installer/tests/Dockerfile.bats installer/tests/ >/dev/null
docker run --rm -v "$repo_root:/code" -w /code argos-install-bats:latest installer/tests/
