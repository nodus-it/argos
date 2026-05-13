#!/usr/bin/env bash
# lib/state.sh — persistence and manipulation of the per-task state.json.
#
# Schema in schemas/state.schema.json. Atomic writes via .tmp+mv so a crash
# cannot corrupt the file. STATE_FILE is overridable for tests.

# shellcheck shell=bash

STATE_FILE="${STATE_FILE:-/workspace/.agent/state.json}"
STATE_SCHEMA_VERSION=1

# _state_now: current UTC timestamp in ISO8601.
_state_now() {
    date -u +"%Y-%m-%dT%H:%M:%SZ"
}

# state_init: write the initial state.json for a new task.
# Args: $1=task_id, $2=repo_url, $3=base_branch
# Returns: 0 on success.
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
                push:       $empty,
                respond:    $empty
            }
        }' > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

# state_read: print the current state.json to stdout.
# Returns: 0 on success, 1 if the file is missing.
state_read() {
    if [[ ! -f "$STATE_FILE" ]]; then
        echo "state_read: $STATE_FILE not found" >&2
        return 1
    fi
    cat "$STATE_FILE"
}

# state_write_atomic: replace state.json atomically with JSON read from stdin.
# Refuses to write unparseable JSON.
state_write_atomic() {
    local content
    content="$(cat)"
    if ! jq -e . <<< "$content" >/dev/null 2>&1; then
        echo "state_write_atomic: invalid JSON refused" >&2
        return 1
    fi
    printf '%s' "$content" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

# state_set_feature_branch: set repo.feature_branch (first concept run only).
# Args: $1=branch_name
state_set_feature_branch() {
    local branch="$1"
    local now
    now="$(_state_now)"
    jq --arg b "$branch" --arg now "$now" \
        '.repo.feature_branch = $b | .updated_at = $now' \
        "$STATE_FILE" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

# state_get_feature_branch: print repo.feature_branch or empty string.
state_get_feature_branch() {
    jq -r '.repo.feature_branch // ""' "$STATE_FILE"
}

# state_set_pr_url: set repo.pr_url after a successful push/MR.
# Args: $1=pr_url
state_set_pr_url() {
    local pr_url="$1"
    local now
    now="$(_state_now)"
    jq --arg u "$pr_url" --arg now "$now" \
        '.repo.pr_url = $u | .updated_at = $now' \
        "$STATE_FILE" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

# state_get_pr_url: print repo.pr_url or empty string.
state_get_pr_url() {
    jq -r '.repo.pr_url // ""' "$STATE_FILE"
}

# state_add_iteration: append a new iteration to a phase.
# Args: $1=phase, $2=flags_json (e.g. '{"fresh":false}'), $3=optional started_at
# Output: the new iteration number on stdout.
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

# state_update_iteration: finalise a running iteration.
# Args: $1=phase, $2=n, $3=status, $4=exit_code,
#       $5=optional error_message, $6=optional failed_gate
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

# state_finalize_running: if the given iteration is still "running", flip it
# to "failed" with the provided exit code + error message. Idempotent: if the
# phase script already called state_update_iteration normally, this is a no-op.
#
# Used by the worker entrypoint as an EXIT-trap so a hard-killed container or
# `exit` short-circuit cannot leave the state.json stuck on "running" forever.
#
# Args: $1=phase, $2=n, $3=exit_code, $4=optional error_message
state_finalize_running() {
    local phase="$1"
    local n="$2"
    local exit_code="$3"
    local err="${4:-worker exited without finalizing iteration}"

    [[ -f "$STATE_FILE" ]] || return 0
    [[ -n "$phase" && -n "$n" ]] || return 0

    local idx=$(( n - 1 ))
    local current
    current="$(jq -r ".phases[\"$phase\"].iterations[$idx].status // \"\"" \
                "$STATE_FILE" 2>/dev/null || echo "")"
    if [[ "$current" != "running" ]]; then
        return 0
    fi

    state_update_iteration "$phase" "$n" failed "$exit_code" "$err"
}

# state_get_iteration_count: number of iterations of a phase.
# Args: $1=phase
state_get_iteration_count() {
    local phase="$1"
    jq -r ".phases[\"$phase\"].iterations | length" "$STATE_FILE"
}

# state_get_current_status: current_status of a phase.
# Args: $1=phase
state_get_current_status() {
    local phase="$1"
    jq -r ".phases[\"$phase\"].current_status" "$STATE_FILE"
}

# state_validate: check required top-level fields and schema_version.
# Returns: 0 if valid, 1 otherwise.
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
