#!/usr/bin/env bash
# worker/tests/run-tests.sh — zentraler Test-Wrapper.
#
# Optionen:
#   --bats           nur Bats-Unit-Tests laufen lassen
#   --shellcheck     nur shellcheck (Severity warning) laufen lassen
#   (default: shellcheck → bats)
#
# Hinweis: --integration ist als Alias für --bats erhalten (CI ruft das so);
# eine eigenständige Integrations-Suite für die Bash-Schicht existiert nicht
# mehr, weil PhaseRunner (PHP) die Container-Orchestrierung übernommen hat.

set -euo pipefail
IFS=$'\n\t'

run_shellcheck=false
run_bats=false

if [[ $# -eq 0 ]]; then
    run_shellcheck=true
    run_bats=true
fi
while [[ $# -gt 0 ]]; do
    case "$1" in
        --shellcheck)  run_shellcheck=true; shift ;;
        --bats)        run_bats=true; shift ;;
        --integration) run_bats=true; shift ;;
        --all)         run_shellcheck=true; run_bats=true; shift ;;
        -h|--help)
            sed -n '2,11p' "$0"
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
    files=(.tools/docker/worker/worker-entrypoint.sh worker/tests/run-tests.sh worker/tests/run-bats.sh)
    while IFS= read -r f; do files+=("$f"); done < <(find worker/lib worker/phases -type f \( -name '*.sh' -o -name 'claude' \))
    docker run --rm -v "$repo_root:/mnt" -w /mnt koalaman/shellcheck:stable \
        --severity=warning "${files[@]}" || rc=$?
fi

if $run_bats; then
    echo "==> bats unit tests"
    bash "$repo_root/worker/tests/run-bats.sh" || rc=$?
fi

exit "$rc"
