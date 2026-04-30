#!/usr/bin/env bash
# lib/tasks.sh — Task-Lifecycle (ID-Validierung, Volume-Management).
#
# Pro Task: ein Docker-Volume `task_ws_<task-id>`, dynamisch erstellt
# beim `agent task new`, gelöscht beim `agent abort` oder optional
# beim `agent push --auto-cleanup`. Siehe WORKER-CONCEPT.md.

# shellcheck shell=bash

# task_id_validate: Prueft Task-ID gegen ^[a-z0-9][a-z0-9-]*[a-z0-9]$, max 40 Zeichen,
# oder einzelner alphanumerischer Char.
# Args: $1=task_id
# Returns: 0 wenn gueltig, 1 sonst (mit Fehler auf stderr).
task_id_validate() {
    local id="$1"
    if [[ -z "$id" ]]; then
        echo "task_id_validate: empty id" >&2
        return 1
    fi
    if (( ${#id} > 40 )); then
        echo "task_id_validate: id too long (max 40)" >&2
        return 1
    fi
    if [[ ${#id} -eq 1 ]]; then
        if [[ "$id" =~ ^[a-z0-9]$ ]]; then
            return 0
        fi
        echo "task_id_validate: single-char id must be [a-z0-9]" >&2
        return 1
    fi
    if [[ "$id" =~ ^[a-z0-9][a-z0-9-]*[a-z0-9]$ ]]; then
        return 0
    fi
    echo "task_id_validate: id must match ^[a-z0-9][a-z0-9-]*[a-z0-9]\$" >&2
    return 1
}

# task_volume_name: Liefert den Volume-Namen fuer eine Task-ID.
# Args: $1=task_id
# Output: Volume-Name (task_ws_<id>) auf stdout.
task_volume_name() {
    echo "task_ws_$1"
}

# task_create_volume: Erstellt das Docker-Volume des Tasks (idempotent).
# Args: $1=task_id
# Returns: 0 bei Erfolg.
task_create_volume() {
    local name
    name="$(task_volume_name "$1")"
    docker volume create "$name" >/dev/null
}

# task_delete_volume: Loescht das Docker-Volume des Tasks (idempotent).
# Args: $1=task_id
# Returns: 0 immer.
task_delete_volume() {
    local name
    name="$(task_volume_name "$1")"
    docker volume rm "$name" >/dev/null 2>&1 || true
}

# task_volume_exists: Prueft ob das Volume existiert.
# Args: $1=task_id
# Returns: 0 wenn ja, 1 sonst.
task_volume_exists() {
    local name
    name="$(task_volume_name "$1")"
    docker volume inspect "$name" >/dev/null 2>&1
}

# task_list_volumes: Liefert alle Task-Volumes (Namen ohne Prefix) zeilenweise.
# Returns: 0 immer.
task_list_volumes() {
    docker volume ls --filter "name=task_ws_" --format '{{.Name}}' \
        | sed 's/^task_ws_//'
}

# task_list: Liefert alle bekannten Tasks zeilenweise auf stdout.
#   - Aus ~/.agent/tasks/* (Host-Side-State) und Volumes (Docker-Side).
#   - Doppelt vorkommende Eintraege werden dedupliziert.
# Returns: 0 immer.
task_list() {
    {
        if [[ -d "${AGENT_HOME:-$HOME/.agent}/tasks" ]]; then
            local d
            for d in "${AGENT_HOME:-$HOME/.agent}/tasks"/*/; do
                [[ -d "$d" ]] || continue
                basename "$d"
            done
        fi
        task_list_volumes
    } | sort -u
}

# task_orphans: Liefert Volumes die kein zugehöriges ~/.agent/tasks/-Verzeichnis haben.
# Returns: 0 immer.
task_orphans() {
    local agent_tasks_dir="${AGENT_HOME:-$HOME/.agent}/tasks"
    local id
    while IFS= read -r id; do
        [[ -z "$id" ]] && continue
        if [[ ! -d "$agent_tasks_dir/$id" ]]; then
            echo "$id"
        fi
    done < <(task_list_volumes)
}
