#!/usr/bin/env bash
# tests/integration/test_error_scenarios.sh
#
# Testet Fehlerszenarien: Mock-Claude gibt Fehler-Antworten zurueck,
# der Worker muss korrekte Exit-Codes und state.json-Status liefern.
#
# Setup identisch zu test_phase_lifecycle.sh, aber mit MOCK_CLAUDE_ERROR_MODE
# als Environment-Variable im Compose-Overlay.

set -euo pipefail
IFS=$'\n\t'

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
FIXTURES="$ROOT/worker/tests/integration/fixtures"

TEST_DIR="$(mktemp -d -t agent-it-err-XXXXXX)"
export AGENT_HOME="$TEST_DIR/.agent"
mkdir -p "$AGENT_HOME"

FAKE_REMOTE="$TEST_DIR/fake-remote.git"

cleanup() {
    local rc=$?
    set +e
    for vol in $(docker volume ls -q --filter "name=task_ws_err-"); do
        docker volume rm "$vol" 2>/dev/null
    done
    rm -rf "$TEST_DIR"
    exit "$rc"
}
trap cleanup EXIT

step() {
    printf '\n\033[1;36m==> %s\033[0m\n' "$*"
}

fail() {
    printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2
    exit 1
}

# run_error_scenario: Fuehrt concept-Phase mit gegebenem Fehlermodus aus.
# Args: $1=scenario_name, $2=MOCK_CLAUDE_ERROR_MODE, $3=expected_exit (non-zero)
run_error_scenario() {
    local name="$1"
    local error_mode="$2"
    local expected_exit="$3"

    local task_id="err-${name}-$$"

    step "$name: Setup"
    mkdir -p "$AGENT_HOME/tasks/$task_id"
    cat > "$AGENT_HOME/tasks/$task_id/credentials.env" <<EOF
REPO_URL=file:///tmp/fake-remote.git
REPO_TOKEN=dummy-token
BASE_BRANCH=main
EOF
    chmod 600 "$AGENT_HOME/tasks/$task_id/credentials.env"

    cat > "$AGENT_HOME/tasks/$task_id/description.md" <<'EOF'
# Demo: HelloWorld

Lege App\Demo\HelloWorld an.
EOF
    chmod 644 "$AGENT_HOME/tasks/$task_id/description.md"

    docker volume create "task_ws_${task_id}" >/dev/null

    local overlay="$TEST_DIR/overlay-${name}.yml"
    cat > "$overlay" <<EOF
services:
  worker:
    volumes:
      - $FIXTURES/mock-claude/claude:/usr/bin/claude:ro
      - $FAKE_REMOTE:/tmp/fake-remote.git
    environment:
      - MOCK_CLAUDE_ERROR_MODE=$error_mode
EOF
    export AGENT_EXTRA_COMPOSE="$overlay"

    step "$name: concept phase (erwartet exit $expected_exit)"
    set +e
    "$ROOT/agent" concept "$task_id"
    local actual_exit=$?
    set -e

    if [[ "$actual_exit" -eq 0 ]]; then
        fail "$name: concept exited 0 (erwartet $expected_exit)"
    fi
    if [[ -n "$expected_exit" && "$actual_exit" -ne "$expected_exit" ]]; then
        fail "$name: concept exited $actual_exit (erwartet $expected_exit)"
    fi

    step "$name: concept.md darf nicht entstanden sein"
    local concept_exists
    concept_exists="$(docker run --rm -v "task_ws_${task_id}:/workspace" \
        --entrypoint sh argos-worker:latest \
        -c 'test -f /workspace/.agent/concept.md && echo yes || echo no')"
    [[ "$concept_exists" == "no" ]] \
        || fail "$name: concept.md existiert — Phase haette fehlschlagen muessen"

    docker volume rm "task_ws_${task_id}" >/dev/null
    unset AGENT_EXTRA_COMPOSE
    printf '\033[1;32m%s OK\033[0m\n' "$name"
}

step "Setup: fake-remote-Repo initialisieren"
"$FIXTURES/fake-remote-repo/setup.sh" "$FAKE_REMOTE" >/dev/null

step "Setup: Claude-Token-Stub schreiben"
echo "mock-token" > "$AGENT_HOME/claude_oauth_token"
chmod 600 "$AGENT_HOME/claude_oauth_token"

# --- Szenarien ---

run_error_scenario "auth_error"   "auth_error"   3
run_error_scenario "rate_limit"   "rate_limit"   3
run_error_scenario "empty_result" "empty_result" 1
run_error_scenario "invalid_json" "invalid_json" 3

printf '\n\033[1;32mERROR SCENARIOS OK\033[0m\n'
