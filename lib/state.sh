#!/usr/bin/env bash
# lib/state.sh — Persistenz und Manipulation der Task-state.json.
#
# Schema in schemas/state.schema.json. Lifecycle und Felder beschrieben
# in IMPLEMENTATION.md Abschnitt 2. Atomare Writes via .tmp+mv damit
# Crashes die Datei nicht zerstören.
#
# Die Datei wird über STATE_FILE referenziert (default
# /workspace/.agent/state.json). Tests können STATE_FILE überschreiben.

# shellcheck shell=bash

STATE_FILE="${STATE_FILE:-/workspace/.agent/state.json}"
STATE_SCHEMA_VERSION=1

# _state_now: Aktueller UTC-Timestamp im ISO8601-Format.
_state_now() {
    date -u +"%Y-%m-%dT%H:%M:%SZ"
}

# state_init: Erstellt initiales state.json fuer einen neuen Task.
# Args: $1=task_id, $2=repo_url, $3=base_branch
# Output: keine; legt $STATE_FILE an.
# Returns: 0 bei Erfolg, sonst Fehler von jq/mkdir/mv.
state_init() {
    local task_id="$1"
    local repo_url="$2"
    local base_branch="$3"
    local now
    now="$(_state_now)"

    mkdir -p "$(dirname "$STATE_FILE")"

    local empty_phase='{"iterations":[],"current_status":"pending"}'
    jq -n \
        --arg task_id "$task_id" \
        --argjson schema_version "$STATE_SCHEMA_VERSION" \
        --arg now "$now" \
        --arg repo_url "$repo_url" \
        --arg base_branch "$base_branch" \
        --argjson empty "$empty_phase" \
        '{
            task_id: $task_id,
            schema_version: $schema_version,
            created_at: $now,
            updated_at: $now,
            repo: {
                url: $repo_url,
                base_branch: $base_branch,
                feature_branch: null
            },
            phases: {
                concept:    $empty,
                implement:  $empty,
                diff:       $empty,
                push:       $empty
            }
        }' > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

# state_read: Gibt aktuellen state.json-Inhalt auf stdout.
# Returns: 0 bei Erfolg, 1 wenn Datei fehlt.
state_read() {
    if [[ ! -f "$STATE_FILE" ]]; then
        echo "state_read: $STATE_FILE not found" >&2
        return 1
    fi
    cat "$STATE_FILE"
}

# state_write_atomic: Ersetzt state.json atomar durch JSON von stdin.
# Stdin: vollständiges State-JSON
# Returns: 0 bei Erfolg.
state_write_atomic() {
    local content
    content="$(cat)"
    # jq-Validierung: parsbar?
    if ! jq -e . <<< "$content" >/dev/null 2>&1; then
        echo "state_write_atomic: invalid JSON refused" >&2
        return 1
    fi
    printf '%s' "$content" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

# state_set_feature_branch: Setzt repo.feature_branch (Erst-Belegung beim ersten concept-Run).
# Args: $1=branch_name
# Returns: 0 bei Erfolg.
state_set_feature_branch() {
    local branch="$1"
    local now
    now="$(_state_now)"
    jq --arg b "$branch" --arg now "$now" \
        '.repo.feature_branch = $b | .updated_at = $now' \
        "$STATE_FILE" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

# state_get_feature_branch: Liefert repo.feature_branch oder leer.
state_get_feature_branch() {
    jq -r '.repo.feature_branch // ""' "$STATE_FILE"
}

# state_add_iteration: Fügt eine neue Iteration zur Phase hinzu.
# Args: $1=phase, $2=flags_json (z.B. '{"fresh":false}'), $3=optional started_at
# Output: Iterationsnummer (n) auf stdout.
# Returns: 0 bei Erfolg.
state_add_iteration() {
    local phase="$1"
    local flags_json="${2:-}"
    [[ -z "$flags_json" ]] && flags_json='{}'
    local started_at="${3:-$(_state_now)}"
    local now
    now="$(_state_now)"

    local next_n
    next_n="$(jq -r ".phases[\"$phase\"].iterations | length + 1" "$STATE_FILE")"

    jq --arg phase "$phase" \
       --argjson n "$next_n" \
       --arg started "$started_at" \
       --argjson flags "$flags_json" \
       --arg now "$now" \
       '
        .phases[$phase].iterations += [{
            n: $n,
            started_at: $started,
            finished_at: null,
            status: "running",
            exit_code: null,
            flags: $flags,
            failed_gate: null,
            error_message: null
        }]
        | .phases[$phase].current_status = "running"
        | .updated_at = $now
       ' \
       "$STATE_FILE" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"

    echo "$next_n"
}

# state_update_iteration: Setzt Endstatus einer laufenden Iteration.
# Args: $1=phase, $2=n, $3=status, $4=exit_code,
#       $5=optional error_message, $6=optional failed_gate
# Returns: 0 bei Erfolg.
state_update_iteration() {
    local phase="$1"
    local n="$2"
    local status="$3"
    local exit_code="$4"
    local err="${5:-}"
    local failed_gate="${6:-}"
    local now
    now="$(_state_now)"

    local idx=$(( n - 1 ))

    jq --arg phase "$phase" \
       --argjson idx "$idx" \
       --arg status "$status" \
       --argjson exit_code "$exit_code" \
       --arg err "$err" \
       --arg failed_gate "$failed_gate" \
       --arg now "$now" \
       '
        .phases[$phase].iterations[$idx].finished_at = $now
        | .phases[$phase].iterations[$idx].status     = $status
        | .phases[$phase].iterations[$idx].exit_code  = $exit_code
        | .phases[$phase].iterations[$idx].error_message = (if $err == "" then null else $err end)
        | .phases[$phase].iterations[$idx].failed_gate   = (if $failed_gate == "" then null else $failed_gate end)
        | .phases[$phase].current_status = $status
        | .updated_at = $now
       ' \
       "$STATE_FILE" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

# state_get_iteration_count: Anzahl Iterationen einer Phase.
# Args: $1=phase
# Output: Zahl auf stdout.
state_get_iteration_count() {
    local phase="$1"
    jq -r ".phases[\"$phase\"].iterations | length" "$STATE_FILE"
}

# state_get_current_status: Aktueller current_status einer Phase.
# Args: $1=phase
# Output: Status-String auf stdout.
state_get_current_status() {
    local phase="$1"
    jq -r ".phases[\"$phase\"].current_status" "$STATE_FILE"
}

# state_validate: Pruefe Pflichtfelder und Schema-Version. Manuelle Field-Checks.
# Returns: 0 wenn OK, 1 sonst.
state_validate() {
    [[ -f "$STATE_FILE" ]] || { echo "state_validate: missing $STATE_FILE" >&2; return 1; }

    local fields=(task_id schema_version created_at updated_at repo phases)
    local f
    for f in "${fields[@]}"; do
        if ! jq -e "has(\"$f\")" "$STATE_FILE" >/dev/null; then
            echo "state_validate: missing top-level field: $f" >&2
            return 1
        fi
    done

    local sv
    sv="$(jq -r .schema_version "$STATE_FILE")"
    if [[ "$sv" != "$STATE_SCHEMA_VERSION" ]]; then
        echo "state_validate: schema_version mismatch: file=$sv expected=$STATE_SCHEMA_VERSION" >&2
        return 1
    fi

    local p
    for p in concept implement diff push; do
        if ! jq -e ".phases | has(\"$p\")" "$STATE_FILE" >/dev/null; then
            echo "state_validate: missing phase entry: $p" >&2
            return 1
        fi
    done
    return 0
}
