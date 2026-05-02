#!/usr/bin/env bash
# tests/integration/test_feedback_loop.sh
#
# Smoke-Test fuer den Respond-Feedback-Zyklus:
#   concept → implement → diff → push → [feedback schreiben] → respond
#
# Verifikat:
#   - respond-Phase laeuft erfolgreich durch
#   - state.json zeigt respond.current_status = "completed"

set -euo pipefail
IFS=$'\n\t'

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
FIXTURES="$ROOT/worker/tests/integration/fixtures"
TASK_ID="feedback-$$"

TEST_DIR="$(mktemp -d -t agent-it-XXXXXX)"
export AGENT_HOME="$TEST_DIR/.agent"
mkdir -p "$AGENT_HOME"

FAKE_REMOTE="$TEST_DIR/fake-remote.git"

cleanup() {
    local rc=$?
    set +e
    docker volume rm "task_ws_${TASK_ID}" 2>/dev/null
    # TEST_DIR contains fake-remote.git; rm may partially fail on docker-owned
    # objects (uid mismatch) — tolerate silently, the temp dir is ephemeral.
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

step "Setup: fake-remote-Repo initialisieren"
"$FIXTURES/fake-remote-repo/setup.sh" "$FAKE_REMOTE" >/dev/null

step "Setup: Claude-Token-Stub"
echo "mock-token" > "$AGENT_HOME/claude_oauth_token"
chmod 600 "$AGENT_HOME/claude_oauth_token"

step "Setup: Task-Verzeichnis und description.md"
mkdir -p "$AGENT_HOME/tasks/$TASK_ID"
cat > "$AGENT_HOME/tasks/$TASK_ID/credentials.env" <<EOF
REPO_URL=file:///tmp/fake-remote.git
REPO_TOKEN=dummy-token
BASE_BRANCH=main
EOF
chmod 600 "$AGENT_HOME/tasks/$TASK_ID/credentials.env"

cat > "$AGENT_HOME/tasks/$TASK_ID/description.md" <<'EOF'
# Demo: HelloWorld

Lege App\Demo\HelloWorld mit greet(string) an, plus Pest-Test. (Konzept-Aufgabe)
EOF
chmod 644 "$AGENT_HOME/tasks/$TASK_ID/description.md"

step "Setup: Volume anlegen"
docker volume create "task_ws_${TASK_ID}" >/dev/null

step "Setup: Compose-Overlay mit absoluten Pfaden generieren"
overlay="$TEST_DIR/docker-compose.test.yml"
cat > "$overlay" <<EOF
services:
  worker:
    volumes:
      - $FIXTURES/mock-claude/claude:/usr/bin/claude:ro
      - $FAKE_REMOTE:/tmp/fake-remote.git
EOF
export AGENT_EXTRA_COMPOSE="$overlay"

step "concept $TASK_ID"
"$ROOT/agent" concept "$TASK_ID" || fail "agent concept failed"

step "implement $TASK_ID"
"$ROOT/agent" implement "$TASK_ID" || fail "agent implement failed"

step "diff $TASK_ID"
"$ROOT/agent" diff "$TASK_ID" >/dev/null || fail "agent diff failed"

step "push $TASK_ID --keep"
"$ROOT/agent" push "$TASK_ID" --keep || fail "agent push failed"

step "Feedback in Volume schreiben"
docker run --rm \
    -v "task_ws_${TASK_ID}:/workspace" \
    --entrypoint sh \
    argos-worker:latest \
    -c 'mkdir -p /workspace/.agent && printf "Bitte auch einen Docstring hinzufuegen.\n" > /workspace/.agent/respond.feedback.md'

step "respond $TASK_ID"
"$ROOT/agent" respond "$TASK_ID" || fail "agent respond failed"

step "Verifikation: respond.current_status in state.json ist completed"
respond_status="$(docker run --rm \
    -v "task_ws_${TASK_ID}:/workspace" \
    --entrypoint sh \
    argos-worker:latest \
    -c 'jq -r .phases.respond.current_status /workspace/.agent/state.json')"
[[ "$respond_status" == "completed" ]] \
    || fail "respond current_status = '$respond_status' (erwartet 'completed')"

printf '\n\033[1;32mFEEDBACK LOOP OK\033[0m\n'
