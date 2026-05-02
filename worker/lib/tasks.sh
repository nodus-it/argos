#!/usr/bin/env bash
# lib/tasks.sh — task lifecycle (id validation, volume management).
#
# One docker volume per task, named `task_ws_<task-id>`. Created on
# `agent task new` and removed on `agent abort` (or optionally on
# `agent push --auto-cleanup`).

# shellcheck shell=bash

# task_id_validate: validate the id against ^[a-z0-9][a-z0-9-]*[a-z0-9]$,
# 40 chars max, or a single alphanumeric character.
# Args: $1=task_id
# Returns: 0 if valid, 1 otherwise (error on stderr).
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

# task_volume_name: print the volume name for a task id.
# Args: $1=task_id
task_volume_name() {
    echo "task_ws_$1"
}

# task_create_volume: create the docker volume for a task (idempotent).
# Args: $1=task_id
task_create_volume() {
    local name
    name="$(task_volume_name "$1")"
    docker volume create "$name" >/dev/null
}

# task_delete_volume: delete the docker volume for a task (idempotent).
# Args: $1=task_id
task_delete_volume() {
    local name
    name="$(task_volume_name "$1")"
    docker volume rm "$name" >/dev/null 2>&1 || true
}

# task_volume_exists: true if the volume exists.
# Args: $1=task_id
task_volume_exists() {
    local name
    name="$(task_volume_name "$1")"
    docker volume inspect "$name" >/dev/null 2>&1
}

# task_list_volumes: print all task volume names (without the prefix), one per line.
task_list_volumes() {
    docker volume ls --filter "name=task_ws_" --format '{{.Name}}' \
        | sed 's/^task_ws_//'
}

# task_list: print all known task ids, deduplicated.
# Combines host-side state (~/.agent/tasks/*) and docker-side volumes.
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

# task_orphans: print volumes that have no matching ~/.agent/tasks/ entry.
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
