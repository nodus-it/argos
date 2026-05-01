#!/usr/bin/env bash
# lib/docker.sh — `docker compose run`-Wrapper fuer Phase-Aufrufe.
#
# Wraps siehe IMPLEMENTATION.md Abschnitt 8.4. Erwartet, dass:
#   - lib/credentials.sh und lib/tasks.sh bereits gesourced sind
#   - der Compose-Service `worker` existiert (siehe docker-compose.yml)
#
# AGENT_REPO_ROOT zeigt auf das Repo-Verzeichnis mit docker-compose.yml.
# Default ist das aktuelle Verzeichnis; CLI setzt es explizit auf den
# Pfad relativ zum agent-Skript.

# shellcheck shell=bash

AGENT_REPO_ROOT="${AGENT_REPO_ROOT:-}"

# docker_run_phase: Startet eine Phase im Worker-Container.
# Args: $1=phase, $2=task_id
# Optional ENV-Inputs (sollen vom Caller gesetzt sein):
#   REPO_URL, REPO_TOKEN, BASE_BRANCH (aus credentials_load_task)
#   CLAUDE_CODE_OAUTH_TOKEN (aus credentials_load_claude_token)
#   PHASE_FLAGS (JSON-String, Default: "{}")
#   MAX_TURNS (Zahl, Default: leer)
# Returns: Exit-Code des Worker-Containers (= Phase-Exit).
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

    # Description-Bind-Mount: liegt unter /run/agent/, NICHT unter /workspace —
    # ein Bind-Mount in /workspace/.agent/* würde Docker zwingen, das parent dir
    # als root anzulegen, was den Worker (uid 1000) am Schreiben hindert.
    local desc_host="${AGENT_HOME:-$HOME/.agent}/tasks/$task_id/description.md"
    local desc_mount=()
    if [[ -f "$desc_host" ]]; then
        desc_mount=(-v "$desc_host:/run/agent/description.md:ro")
    fi

    # Optional: AGENT_EXTRA_COMPOSE (kommagetrennt) — fuer Test-Overlays.
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
        worker "$phase" "$task_id"
}

# docker_run_shell: Startet eine interaktive Shell im Worker mit Volume gemountet.
# Args: $1=task_id
# Returns: Exit-Code der Shell.
docker_run_shell() {
    local task_id="$1"
    local volume
    volume="$(task_volume_name "$task_id")"
    local repo="${AGENT_REPO_ROOT:-$PWD}"

    docker compose -f "$repo/docker-compose.yml" run --rm \
        -v "$volume:/workspace" \
        -e "TASK_ID=$task_id" \
        --entrypoint bash \
        worker -i
}

# docker_copy_from_volume: Kopiert eine Datei aus einem Task-Volume auf den Host.
# Args: $1=task_id, $2=in_volume_path (z.B. .agent/concept.md), $3=host_path
# Returns: 0 bei Erfolg, sonst exit-code des cp.
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

# docker_copy_to_volume: Kopiert eine Host-Datei in ein Task-Volume.
# Args: $1=task_id, $2=host_path, $3=in_volume_path
# Returns: 0 bei Erfolg.
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
