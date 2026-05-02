#!/usr/bin/env bash
# phases/registry.sh — registered phases and lifecycle order.
#
# Sourced by the worker entrypoint. Provides:
#   - PHASE_NAMES                all known phases (incl. sub-phases)
#   - PHASE_ORDER_IN_LIFECYCLE   default order of the user-facing phases
#   - phase_load <name>          source phases/<name>.sh
#   - phase_known <name>         true if the phase is registered
#
# Sub-phases (e.g. commit-message) appear in PHASE_NAMES but NOT in
# PHASE_ORDER_IN_LIFECYCLE — they are invoked from other phases.

# shellcheck shell=bash
# shellcheck disable=SC2034
# (constants are read by callers.)

PHASE_NAMES=(concept implement diff push respond commit-message)
PHASE_ORDER_IN_LIFECYCLE=(concept implement diff push respond)

PHASES_DIR="${PHASES_DIR:-/usr/local/share/agent/phases}"

# phase_known: true if $1 is in PHASE_NAMES.
# Args: $1=phase_name
phase_known() {
    local needle="$1"
    local p
    for p in "${PHASE_NAMES[@]}"; do
        [[ "$p" == "$needle" ]] && return 0
    done
    return 1
}

# phase_load: source phases/<name>.sh from $PHASES_DIR.
# Args: $1=phase_name
# Returns: 0 if loaded, 1 if unknown or the file is missing.
phase_load() {
    local name="$1"
    if ! phase_known "$name"; then
        echo "phase_load: unknown phase '$name'" >&2
        return 1
    fi
    local file="$PHASES_DIR/${name}.sh"
    if [[ ! -f "$file" ]]; then
        echo "phase_load: file not found: $file" >&2
        return 1
    fi
    # shellcheck disable=SC1090
    source "$file"
}
