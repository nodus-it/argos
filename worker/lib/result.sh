#!/usr/bin/env bash
# lib/result.sh — build the per-phase result JSON on stdout.
#
# Each phase emits a single JSON object on stdout when it finishes
# (see schemas/result.<phase>.schema.json). This library provides the
# central `result_emit` function that checks the required fields and
# uses jq to produce well-formed JSON.

# shellcheck shell=bash

# result_emit: build a phase-result JSON from key/value pairs and print it.
# Args: $@ = alternating key/value pairs (key value key value ...) or a
#         marker pair:
#           --int <key> <value>   numeric value (no JSON quoting)
#           --raw <key> <jq>      raw jq expression (e.g. arrays, objects)
#
# Usage:
#   result_emit \
#       phase concept \
#       task_id "$TASK_ID" \
#       --int iteration "$ITERATION" \
#       status completed \
#       started_at "$STARTED_AT" \
#       finished_at "$FINISHED_AT" \
#       --int duration_ms 1500 \
#       --int exit_code 0 \
#       concept_path "/workspace/.agent/concept.md"
#
# Required fields (checked after build):
#   phase, task_id, iteration, status, started_at, finished_at, exit_code
#
# Returns: 0 on success, 1 if a required field is missing or jq fails.
result_emit() {
    local args=()
    local jq_filter='{}'
    local key val

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --int|--raw)
                key="$2"
                val="$3"
                shift 3
                args+=(--argjson "rv_${key}" "$val")
                jq_filter+=" | .${key} = \$rv_${key}"
                ;;
            *)
                key="$1"
                val="$2"
                shift 2
                args+=(--arg "rv_${key}" "$val")
                jq_filter+=" | .${key} = \$rv_${key}"
                ;;
        esac
    done

    local out
    if ! out="$(jq -nc "${args[@]}" "$jq_filter")"; then
        echo "result_emit: jq failed" >&2
        return 1
    fi

    local required=(phase task_id iteration status started_at finished_at exit_code)
    local field
    for field in "${required[@]}"; do
        if ! jq -e "has(\"$field\")" <<< "$out" >/dev/null; then
            echo "result_emit: required field missing: $field" >&2
            return 1
        fi
    done

    echo "$out"
}
