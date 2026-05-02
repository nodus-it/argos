#!/usr/bin/env bash
# lib/prompts.sh — system-prompt composition for Claude sessions.
#
# Builds the final system prompt from three layers:
#   1. /usr/local/share/agent/prompts/<phase>.system.md (worker-owned)
#   2. /usr/local/share/agent/prompts/user.global.system.md (user-global, optional)
#   3. dynamic markers (TASK_ID, BASE_BRANCH, ITERATION)
#
# Output: /workspace/.agent/runtime/<phase>.system.merged.md
# PROMPTS_DIR / RUNTIME_DIR are overridable for tests.

# shellcheck shell=bash

PROMPTS_DIR="${PROMPTS_DIR:-/usr/local/share/agent/prompts}"
RUNTIME_DIR="${RUNTIME_DIR:-/workspace/.agent/runtime}"

# build_system_prompt: produce the merged system prompt for a phase.
# Args: $1=phase
# Required env: TASK_ID, BASE_BRANCH, ITERATION
# Output: path to the produced file on stdout.
# Returns: 0 on success, 1 if the phase-specific prompt is missing.
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

# render_user_prompt: write a user-prompt file into the runtime directory.
# Args: $1=phase, $2=name (e.g. "user-prompt"), $3=optional content
# If $3 is empty, content is read from stdin.
# Output: path to the file on stdout.
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
