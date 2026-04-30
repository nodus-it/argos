#!/usr/bin/env bash
# phases/commit-message.sh — Sub-Phase: erzeugt Conventional-Commits-Message.
#
# Wird von phases/push.sh aufgerufen. Liefert ein Result-JSON mit
# subject + body. Defensives Parsing: zuerst .structured_output, sonst
# Fallback auf .result | fromjson (siehe IMPLEMENTATION.md 1.3 Caveat).
#
# Ergebnis-Files in /workspace/.agent/logs/:
#   commit-message.${ITERATION}.json     — rohes Claude-Envelope
#   commit-message.${ITERATION}.subject  — extrahierte Subject-Zeile
#   commit-message.${ITERATION}.body     — extrahierter Body

# shellcheck shell=bash

phase_commit_message_help() {
    echo "Commit-Message-Phase: erzeugt subject+body fuer den Push-Commit (von push aufgerufen)."
}

phase_commit_message_preconditions() {
    if [[ ! -d /workspace/.git ]]; then
        echo "commit-message: /workspace nicht initialisiert." >&2
        return 2
    fi
    if [[ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]]; then
        echo "commit-message: CLAUDE_CODE_OAUTH_TOKEN fehlt." >&2
        return 3
    fi
    return 0
}

# _commit_message_build_user_prompt: Erzeugt User-Prompt mit Diff + Konzept-Hinweis.
_commit_message_build_user_prompt() {
    local concept_file=/workspace/.agent/concept.md
    local base_ref="origin/${BASE_BRANCH:-main}"

    {
        printf '# Commit-Message generieren\n\n'
        printf 'Lies das Konzept und den unten angefuegten Diff. '
        printf 'Antworte mit JSON gemaess Schema (subject, body).\n\n'

        if [[ -f "$concept_file" ]]; then
            printf '## Konzept\n\n'
            cat "$concept_file"
            printf '\n\n'
        fi

        printf '## git diff %s...HEAD\n\n```diff\n' "$base_ref"
        if git rev-parse --verify --quiet "$base_ref" >/dev/null; then
            git diff --no-color "${base_ref}...HEAD" 2>/dev/null | head -n 800
        else
            git diff --no-color HEAD 2>/dev/null | head -n 800
        fi
        printf '\n```\n'
    }
}

# _commit_message_extract: Liest envelope (stdin) und extrahiert subject+body.
# Output: zwei Zeilen: <subject>\n<body> (body kann mehrzeilig sein, am Ende des Blocks).
# Strategie:
#   1) Versuche .structured_output.{subject,body}
#   2) Fallback: .result als JSON parsen (.result | fromjson)
# Returns: 0 wenn extrahiert, 1 sonst.
_commit_message_extract() {
    local envelope
    envelope="$(cat)"

    local subject body
    subject="$(echo "$envelope" | jq -r '.structured_output.subject // empty' 2>/dev/null)"
    body="$(echo "$envelope" | jq -r '.structured_output.body // empty' 2>/dev/null)"

    if [[ -z "$subject" ]]; then
        local parsed
        parsed="$(echo "$envelope" | jq -r '.result // empty' 2>/dev/null)"
        if [[ -n "$parsed" ]]; then
            local subj_try body_try
            subj_try="$(echo "$parsed" | jq -r '.subject // empty' 2>/dev/null || echo "")"
            body_try="$(echo "$parsed" | jq -r '.body // empty' 2>/dev/null || echo "")"
            if [[ -n "$subj_try" ]]; then
                subject="$subj_try"
                body="$body_try"
            fi
        fi
    fi

    if [[ -z "$subject" ]]; then
        return 1
    fi

    printf '%s\n' "$subject"
    printf '%s' "$body"
}

phase_commit_message_run() {
    cd /workspace 2>/dev/null || {
        echo "commit-message: /workspace not mounted" >&2
        return 1
    }
    mkdir -p /workspace/.agent/logs

    local started_at finished_at started_epoch finished_epoch
    started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    started_epoch=$(date -u +%s)

    local sysprompt
    sysprompt="$(build_system_prompt commit-message)" || return 1

    local user_prompt_path
    user_prompt_path="$(_commit_message_build_user_prompt | render_user_prompt commit-message user-prompt)"

    local schema_path="/usr/local/share/agent/schemas/commit-message.schema.json"
    if [[ ! -f "$schema_path" ]]; then
        echo "commit-message: schema nicht gefunden ($schema_path)" >&2
        return 1
    fi

    local schema_content sysprompt_content
    schema_content="$(cat "$schema_path")"
    sysprompt_content="$(cat "$sysprompt")"

    local output_json="/workspace/.agent/logs/commit-message.${ITERATION}.json"

    log_info "commit-message: rufe claude (json + json-schema) auf"
    if ! claude -p \
            --append-system-prompt "$sysprompt_content" \
            --output-format json \
            --json-schema "$schema_content" \
            --max-turns 8 \
            --permission-mode bypassPermissions \
            < "$user_prompt_path" \
            > "$output_json"; then
        echo "commit-message: claude call failed (exit non-zero)" >&2
        return 3
    fi

    local is_error
    is_error="$(jq -r '.is_error // false' "$output_json" 2>/dev/null || echo true)"
    if [[ "$is_error" != "false" ]]; then
        echo "commit-message: claude returned is_error=true" >&2
        return 3
    fi

    local subject body extracted
    extracted="$(_commit_message_extract < "$output_json")" || {
        echo "commit-message: konnte subject/body nicht extrahieren" >&2
        return 1
    }
    subject="$(echo "$extracted" | head -n1)"
    body="$(echo "$extracted" | tail -n +2)"

    printf '%s\n' "$subject" > "/workspace/.agent/logs/commit-message.${ITERATION}.subject"
    printf '%s\n' "$body"    > "/workspace/.agent/logs/commit-message.${ITERATION}.body"

    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    finished_epoch=$(date -u +%s)
    local duration_ms=$(( (finished_epoch - started_epoch) * 1000 ))

    local session_id cost
    session_id="$(jq -r '.session_id // ""' "$output_json")"
    cost="$(jq -r '.total_cost_usd // 0' "$output_json")"

    result_emit \
        phase commit-message \
        task_id "$TASK_ID" \
        --int iteration "$ITERATION" \
        status completed \
        started_at "$started_at" \
        finished_at "$finished_at" \
        --int duration_ms "$duration_ms" \
        --int exit_code 0 \
        subject "$subject" \
        body "$body" \
        claude_session_id "$session_id" \
        --raw claude_total_cost_usd "$cost"

    return 0
}
