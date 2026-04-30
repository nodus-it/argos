#!/usr/bin/env bash
# phases/registry.sh — Liste aktiver Phasen und Lifecycle-Reihenfolge.
#
# Wird vom Worker-Entrypoint gesourced. Bietet:
#   - PHASE_NAMES                Alle bekannten Phasen (inkl. Sub-Phasen)
#   - PHASE_ORDER_IN_LIFECYCLE   Default-Reihenfolge der User-Phasen
#   - phase_load <name>          Sourcet phases/<name>.sh
#   - phase_known <name>         Prueft ob die Phase registriert ist
#
# Sub-Phasen (z.B. commit-message) sind in PHASE_NAMES aber NICHT in
# PHASE_ORDER_IN_LIFECYCLE — sie werden von anderen Phasen aufgerufen.

# shellcheck shell=bash
# shellcheck disable=SC2034
# (Konstanten werden vom Caller gelesen.)

PHASE_NAMES=(concept implement diff push commit-message)
PHASE_ORDER_IN_LIFECYCLE=(concept implement diff push)

PHASES_DIR="${PHASES_DIR:-/usr/local/share/agent/phases}"

# phase_known: Prueft ob $1 in PHASE_NAMES enthalten ist.
# Args: $1=phase_name
# Returns: 0 wenn ja, 1 sonst.
phase_known() {
    local needle="$1"
    local p
    for p in "${PHASE_NAMES[@]}"; do
        [[ "$p" == "$needle" ]] && return 0
    done
    return 1
}

# phase_load: Sourcet phases/<name>.sh aus $PHASES_DIR.
# Args: $1=phase_name
# Returns: 0 wenn geladen, 1 wenn nicht bekannt oder Datei fehlt.
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
