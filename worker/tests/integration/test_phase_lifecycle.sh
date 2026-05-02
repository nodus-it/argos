#!/usr/bin/env bash
# tests/integration/test_phase_lifecycle.sh
#
# End-to-end-Smoketest fuer concept → implement → diff → push.
# Setup:
#   - frische AGENT_HOME unter $TEST_DIR
#   - bare fake-remote.git
#   - Mock-Claude statt echtem Claude (via Compose-Overlay)
#   - Task-Volume task_ws_<id>
#   - Description.md auf Host
#
# Wird vom run-all.sh aufgerufen.

set -euo pipefail
IFS=$'\n\t'

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
FIXTURES="$ROOT/worker/tests/integration/fixtures"
TASK_ID="lifecycle-$$"

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

step "Setup: Claude-Token-Stub schreiben"
mkdir -p "$AGENT_HOME"
echo "mock-token" > "$AGENT_HOME/claude_oauth_token"
chmod 600 "$AGENT_HOME/claude_oauth_token"

step "Setup: Task-Verzeichnis und description.md auf Host"
mkdir -p "$AGENT_HOME/tasks/$TASK_ID"
cat > "$AGENT_HOME/tasks/$TASK_ID/credentials.env" <<EOF
REPO_URL=file:///tmp/fake-remote.git
REPO_TOKEN=dummy-token
BASE_BRANCH=main
EOF
chmod 600 "$AGENT_HOME/tasks/$TASK_ID/credentials.env"

cat > "$AGENT_HOME/tasks/$TASK_ID/description.md" <<'EOF'
# Demo: HelloWorld

Lege App\Demo\HelloWorld mit greet(string) an, plus Pest-Test.
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

step "concept lifecycle-$$"
"$ROOT/agent" concept "$TASK_ID" || fail "agent concept failed"

step "Verifikation: concept.md im Volume vorhanden"
volume_concept="$(docker run --rm -v "task_ws_${TASK_ID}:/workspace" --entrypoint sh argos-worker:latest -c 'cat /workspace/.agent/concept.md 2>/dev/null')"
[[ -n "$volume_concept" ]] || fail "concept.md fehlt oder ist leer"
[[ "$volume_concept" == *"HelloWorld"* ]] || fail "concept.md erwaehnt HelloWorld nicht"

step "Verifikation: state.json hat concept-Iteration completed"
state_status="$(docker run --rm -v "task_ws_${TASK_ID}:/workspace" --entrypoint sh argos-worker:latest -c 'jq -r .phases.concept.current_status /workspace/.agent/state.json')"
[[ "$state_status" == "completed" ]] || fail "concept current_status = '$state_status' (erwartet 'completed')"

step "implement $TASK_ID"
"$ROOT/agent" implement "$TASK_ID" || fail "agent implement failed"

step "Verifikation: HelloWorld-Files vom Mock geschrieben"
file_check="$(docker run --rm -v "task_ws_${TASK_ID}:/workspace" --entrypoint sh argos-worker:latest -c 'ls /workspace/app/Demo/HelloWorld.php /workspace/tests/Feature/Demo/HelloWorldTest.php')"
[[ "$file_check" == *"HelloWorld.php"* ]] || fail "HelloWorld.php fehlt"

step "diff $TASK_ID"
"$ROOT/agent" diff "$TASK_ID" >/dev/null || fail "agent diff failed"

step "push $TASK_ID --keep"
"$ROOT/agent" push "$TASK_ID" --keep || fail "agent push failed"

step "Verifikation: feature_branch existiert in fake-remote"
feature_branch="$(docker run --rm -v "task_ws_${TASK_ID}:/workspace" --entrypoint sh argos-worker:latest -c 'jq -r .repo.feature_branch /workspace/.agent/state.json')"
[[ "$feature_branch" == ai/${TASK_ID}-* ]] || fail "feature_branch ungewohnt: '$feature_branch'"
remote_branches="$(git -C "$FAKE_REMOTE" branch --list)"
[[ "$remote_branches" == *"$feature_branch"* ]] || fail "Branch '$feature_branch' fehlt in fake-remote"

step "Verifikation: Commit-Subject auf dem Branch beginnt mit 'feat:'"
remote_commit="$(git -C "$FAKE_REMOTE" log "$feature_branch" -1 --pretty=%s)"
[[ "$remote_commit" =~ ^feat: ]] || fail "remote commit subject = '$remote_commit'"

printf '\n\033[1;32mPHASE LIFECYCLE OK\033[0m\n'
