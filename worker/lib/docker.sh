#!/usr/bin/env bash
# lib/docker.sh — `docker compose run` wrapper used to launch worker phases.
#
# Expects lib/credentials.sh and lib/tasks.sh to be sourced first, and the
# `worker-php84` compose service to exist. AGENT_REPO_ROOT points at the repo
# directory containing docker-compose.yml (defaults to $PWD).

# shellcheck shell=bash

AGENT_REPO_ROOT="${AGENT_REPO_ROOT:-}"

# docker_run_phase: run a phase in a fresh worker container.
# Args: $1=phase, $2=task_id
# Optional env inputs (set by the caller):
#   REPO_URL, REPO_TOKEN, BASE_BRANCH (from credentials_load_task)
#   CLAUDE_CODE_OAUTH_TOKEN (from credentials_load_claude_token)
#   PHASE_FLAGS (JSON string, default "{}")
#   MAX_TURNS (number, default empty)
# Returns: the worker container's exit code (= the phase exit).
docker_run_phase() {
    local phase="$1"
    local task_id="$2"

    if [[ -z "$phase" || -z "$task_id" ]]; then
        echo "docker_run_phase: phase and task_id required" >&2
        return 1
    fi

    local volume
    volume="$(task_volume_name "$task_id")"

    local repo="${AGENT_REPO_ROOT:-$PWD}"
    local default_flags='{}'

    # description bind mount goes to /run/agent/, not /workspace/.agent/:
    # mounting under /workspace forces docker to create the parent dir as
    # root, which then blocks the worker (uid 1000) from writing there.
    local desc_host="${AGENT_HOME:-$HOME/.agent}/tasks/$task_id/description.md"
    local desc_mount=()
    if [[ -f "$desc_host" ]]; then
        desc_mount=(-v "$desc_host:/run/agent/description.md:ro")
    fi

    # AGENT_EXTRA_COMPOSE: comma-separated overlay files for tests.
    local extra_compose=()
    if [[ -n "${AGENT_EXTRA_COMPOSE:-}" ]]; then
        local f
        IFS=',' read -ra _extra <<< "$AGENT_EXTRA_COMPOSE"
        for f in "${_extra[@]}"; do
            extra_compose+=(-f "$f")
        done
    fi

    docker compose -f "$repo/docker-compose.yml" "${extra_compose[@]}" run --rm \
        -v "$volume:/workspace" \
        "${desc_mount[@]}" \
        -e "PHASE=$phase" \
        -e "TASK_ID=$task_id" \
        -e "REPO_URL=${REPO_URL:-}" \
        -e "REPO_TOKEN=${REPO_TOKEN:-}" \
        -e "BASE_BRANCH=${BASE_BRANCH:-}" \
        -e "CLAUDE_CODE_OAUTH_TOKEN=${CLAUDE_CODE_OAUTH_TOKEN:-}" \
        -e "PHASE_FLAGS=${PHASE_FLAGS:-$default_flags}" \
        -e "MAX_TURNS=${MAX_TURNS:-}" \
        -e "LOG_LEVEL=${LOG_LEVEL:-info}" \
        worker-php84 "$phase" "$task_id"
}

# docker_run_shell: open an interactive shell in a worker with the task volume mounted.
# Args: $1=task_id
docker_run_shell() {
    local task_id="$1"
    local volume
    volume="$(task_volume_name "$task_id")"
    local repo="${AGENT_REPO_ROOT:-$PWD}"

    docker compose -f "$repo/docker-compose.yml" run --rm \
        -v "$volume:/workspace" \
        -e "TASK_ID=$task_id" \
        --entrypoint bash \
        worker-php84 -i
}

# docker_copy_from_volume: copy a file out of a task volume to the host.
# Args: $1=task_id, $2=in_volume_path (e.g. .agent/concept.md), $3=host_path
docker_copy_from_volume() {
    local task_id="$1"
    local in_volume_path="$2"
    local host_path="$3"
    local volume
    volume="$(task_volume_name "$task_id")"

    docker run --rm \
        -v "$volume:/workspace:ro" \
        --entrypoint sh \
        agent-worker:latest -c "cat /workspace/$in_volume_path" \
        > "$host_path"
}

# docker_copy_to_volume: copy a host file into a task volume.
# Args: $1=task_id, $2=host_path, $3=in_volume_path
docker_copy_to_volume() {
    local task_id="$1"
    local host_path="$2"
    local in_volume_path="$3"
    local volume
    volume="$(task_volume_name "$task_id")"

    docker run --rm -i \
        -v "$volume:/workspace" \
        --entrypoint sh \
        agent-worker:latest -c "cat > /workspace/$in_volume_path" \
        < "$host_path"
}
