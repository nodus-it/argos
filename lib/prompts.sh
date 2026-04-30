#!/usr/bin/env bash
# lib/prompts.sh — System-Prompt-Komposition fuer Claude-Sessions.
#
# Siehe IMPLEMENTATION.md Abschnitt 10. Baut den finalen
# System-Prompt aus mehreren Layern:
#   1. /usr/local/share/agent/prompts/<phase>.system.md  (Worker-eigen)
#   2. /usr/local/share/agent/prompts/user.global.system.md  (User-global, optional)
#   3. Dynamische Marker (TASK_ID, BASE_BRANCH, ITERATION)
#
# Output: /workspace/.agent/runtime/<phase>.system.merged.md
#
# Ueber PROMPTS_DIR / RUNTIME_DIR konfigurierbar fuer Tests.

# shellcheck shell=bash

PROMPTS_DIR="${PROMPTS_DIR:-/usr/local/share/agent/prompts}"
RUNTIME_DIR="${RUNTIME_DIR:-/workspace/.agent/runtime}"

# build_system_prompt: Erzeugt merged System-Prompt-Datei fuer eine Phase.
# Args: $1=phase
# Required env: TASK_ID, BASE_BRANCH, ITERATION
# Output: Pfad zur erzeugten Datei auf stdout.
# Returns: 0 bei Erfolg, 1 wenn phase-spezifischer Prompt fehlt.
build_system_prompt() {
    local phase="$1"
    local phase_prompt="$PROMPTS_DIR/${phase}.system.md"
    local user_global="$PROMPTS_DIR/user.global.system.md"
    local out="$RUNTIME_DIR/${phase}.system.merged.md"

    if [[ ! -f "$phase_prompt" ]]; then
        echo "build_system_prompt: missing $phase_prompt" >&2
        return 1
    fi

    mkdir -p "$RUNTIME_DIR"

    {
        cat "$phase_prompt"

        if [[ -f "$user_global" ]]; then
            printf '\n---\n\n'
            cat "$user_global"
        fi

        printf '\n---\n\n'
        printf '# Aktueller Kontext\n'
        printf -- '- Task-ID: %s\n' "${TASK_ID:-<unset>}"
        printf -- '- Base-Branch: %s\n' "${BASE_BRANCH:-<unset>}"
        printf -- '- Iteration: %s\n' "${ITERATION:-<unset>}"
    } > "${out}.tmp"
    mv "${out}.tmp" "$out"

    echo "$out"
}

# render_user_prompt: Schreibt einen User-Prompt-File ins Runtime-Verzeichnis.
# Args: $1=phase, $2=name (z.B. "user-prompt"), $3=optional content
# Wenn $3 leer ist, wird stdin gelesen.
# Output: Pfad zur Datei auf stdout.
# Returns: 0 bei Erfolg.
render_user_prompt() {
    local phase="$1"
    local name="$2"
    local content="${3:-}"

    mkdir -p "$RUNTIME_DIR"
    local out="$RUNTIME_DIR/${phase}.${name}.md"

    if [[ -n "$content" ]]; then
        printf '%s\n' "$content" > "${out}.tmp"
    else
        cat > "${out}.tmp"
    fi
    mv "${out}.tmp" "$out"
    echo "$out"
}
