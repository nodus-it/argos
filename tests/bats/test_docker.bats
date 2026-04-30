#!/usr/bin/env bats

bats_require_minimum_version 1.5.0

setup() {
    TEST_DIR="$(mktemp -d)"
    export AGENT_REPO_ROOT="$TEST_DIR/repo"
    export AGENT_HOME="$TEST_DIR/.agent"
    mkdir -p "$AGENT_REPO_ROOT" "$AGENT_HOME/tasks"
    touch "$AGENT_REPO_ROOT/docker-compose.yml"

    # Mock docker: schreibt alle Argumente nach $TEST_DIR/docker.log
    export DOCKER_LOG="$TEST_DIR/docker.log"
    : > "$DOCKER_LOG"

    docker() {
        echo "$@" >> "$DOCKER_LOG"
        return 0
    }
    export -f docker

    # shellcheck source=../../lib/tasks.sh
    source lib/tasks.sh
    # shellcheck source=../../lib/docker.sh
    source lib/docker.sh
}

teardown() {
    rm -rf "$TEST_DIR"
}

@test "docker_run_phase ohne phase/task_id schlaegt fehl" {
    run --separate-stderr docker_run_phase "" ""
    [ "$status" -ne 0 ]
    [[ "$stderr" == *"required"* ]]
}

@test "docker_run_phase passes phase, task_id, env vars und volume an docker compose" {
    REPO_URL="https://e.com/r.git" \
    REPO_TOKEN="ghp_x" \
    BASE_BRANCH="main" \
    CLAUDE_CODE_OAUTH_TOKEN="sk-ant-oat01-tok" \
    PHASE_FLAGS='{"fresh":true}' \
    MAX_TURNS="50" \
    docker_run_phase concept task-001
    cat "$DOCKER_LOG"
    grep -q -- "-v task_ws_task-001:/workspace" "$DOCKER_LOG"
    grep -q -- "PHASE=concept" "$DOCKER_LOG"
    grep -q -- "TASK_ID=task-001" "$DOCKER_LOG"
    grep -q -- "REPO_URL=https://e.com/r.git" "$DOCKER_LOG"
    grep -q -- "REPO_TOKEN=ghp_x" "$DOCKER_LOG"
    grep -q -- "BASE_BRANCH=main" "$DOCKER_LOG"
    grep -q -- "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-tok" "$DOCKER_LOG"
    grep -q -- 'PHASE_FLAGS={"fresh":true}' "$DOCKER_LOG"
    grep -q -- "MAX_TURNS=50" "$DOCKER_LOG"
    grep -q -- " worker concept task-001" "$DOCKER_LOG"
}

@test "docker_run_phase setzt PHASE_FLAGS auf {} wenn leer" {
    docker_run_phase concept task-x
    grep -q -- "PHASE_FLAGS={}" "$DOCKER_LOG"
}

@test "docker_run_phase mountet description.md ro wenn vorhanden" {
    mkdir -p "$AGENT_HOME/tasks/task-001"
    echo "Task X" > "$AGENT_HOME/tasks/task-001/description.md"
    docker_run_phase concept task-001
    grep -q -- "$AGENT_HOME/tasks/task-001/description.md:/run/agent/description.md:ro" "$DOCKER_LOG"
}

@test "docker_run_phase laesst description-Mount weg wenn Datei fehlt" {
    docker_run_phase concept task-no-desc
    ! grep -q -- "description.md" "$DOCKER_LOG"
}

@test "docker_run_phase respektiert AGENT_EXTRA_COMPOSE" {
    AGENT_EXTRA_COMPOSE="/tmp/overlay-a.yml,/tmp/overlay-b.yml" docker_run_phase concept task-x
    grep -q -- "-f /tmp/overlay-a.yml" "$DOCKER_LOG"
    grep -q -- "-f /tmp/overlay-b.yml" "$DOCKER_LOG"
}

@test "docker_run_shell mountet Volume und ueberschreibt Entrypoint" {
    docker_run_shell task-001
    grep -q -- "-v task_ws_task-001:/workspace" "$DOCKER_LOG"
    grep -q -- "--entrypoint bash" "$DOCKER_LOG"
}
