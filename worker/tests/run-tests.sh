#!/usr/bin/env bash
# worker/tests/run-tests.sh — zentraler Test-Wrapper.
#
# Optionen:
#   --bats           nur Bats-Unit-Tests laufen lassen
#   --integration    nur Container-Integrationstests laufen lassen
#   --shellcheck     nur shellcheck (Severity warning) laufen lassen
#   (default: alles drei in Reihenfolge shellcheck → bats → integration)

set -euo pipefail
IFS=$'\n\t'

run_shellcheck=false
run_bats=false
run_integration=false

if [[ $# -eq 0 ]]; then
    run_shellcheck=true
    run_bats=true
    run_integration=true
fi
while [[ $# -gt 0 ]]; do
    case "$1" in
        --shellcheck)  run_shellcheck=true; shift ;;
        --bats)        run_bats=true; shift ;;
        --integration) run_integration=true; shift ;;
        --all)         run_shellcheck=true; run_bats=true; run_integration=true; shift ;;
        -h|--help)
            sed -n '2,9p' "$0"
            exit 0
            ;;
        *)             echo "Unbekannte Option: $1" >&2; exit 64 ;;
    esac
done

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

rc=0

if $run_shellcheck; then
    echo "==> shellcheck (severity=warning)"
    files=(agent worker/docker/worker-entrypoint.sh worker/tests/run-tests.sh worker/tests/run-bats.sh)
    while IFS= read -r f; do files+=("$f"); done < <(find worker/lib worker/phases worker/tests/integration -type f \( -name '*.sh' -o -name 'claude' \))
    docker run --rm -v "$repo_root:/mnt" -w /mnt koalaman/shellcheck:stable \
        --severity=warning "${files[@]}" || rc=$?
fi

if $run_bats; then
    echo "==> bats unit tests"
    bash "$repo_root/worker/tests/run-bats.sh" || rc=$?
fi

if $run_integration; then
    echo "==> integration tests"
    if [[ -x "$repo_root/worker/tests/integration/run-all.sh" ]]; then
        bash "$repo_root/worker/tests/integration/run-all.sh" || rc=$?
    else
        echo "(noch keine integration tests vorhanden — uebersprungen)"
    fi
fi

exit "$rc"
