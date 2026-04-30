#!/usr/bin/env bash
# lib/result.sh — Phase-Result-JSON-Konstruktion auf stdout.
#
# Jede Phase emittiert am Ende ein einzelnes JSON-Objekt auf stdout
# (siehe IMPLEMENTATION.md Abschnitt 7 und schemas/result.<phase>.schema.json).
# Diese Library liefert eine zentrale `result_emit`-Funktion die das
# Pflichtfeld-Set prüft und über jq sauberes JSON baut.

# shellcheck shell=bash

# result_emit: Baut Phase-Result-JSON aus key/value-Paaren und gibt es auf stdout.
# Args: $@ = Schlüssel/Wert-Paare alternierend (key value key value ...)
#         oder spezielle Marker:
#           --int <key> <value>   numerischer Wert (ohne JSON-Quoting)
#           --raw <key> <jq>      roher jq-Ausdruck (z.B. Arrays, Objekte)
#
# Nutzung:
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
# Pflichtfelder (werden nach dem Bau geprüft):
#   phase, task_id, iteration, status, started_at, finished_at, exit_code
#
# Returns: 0 bei Erfolg, 1 wenn ein Pflichtfeld fehlt oder jq fehlschlägt.
result_emit() {
    local args=()
    local jq_filter='{}'
    local key val

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --int|--raw)
                # Numerischer Wert oder roher jq-Ausdruck — kein Quoting
                key="$2"
                val="$3"
                shift 3
                args+=(--argjson "rv_${key}" "$val")
                jq_filter+=" | .${key} = \$rv_${key}"
                ;;
            *)
                # Default: String key/value pair
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
