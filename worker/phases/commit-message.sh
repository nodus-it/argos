#!/usr/bin/env bash
# phases/commit-message.sh — sub-phase: produce a conventional-commits message.
#
# Called from phases/push.sh. Returns a result JSON with subject + body.
# Defensive parsing: try .structured_output first, then fall back to
# `.result | fromjson`.
#
# Output files in /workspace/.agent/logs/:
#   commit-message.${ITERATION}.json     — raw Claude envelope
#   commit-message.${ITERATION}.subject  — extracted subject line
#   commit-message.${ITERATION}.body     — extracted body

# shellcheck shell=bash

phase_commit_message_help() {
    echo "Commit-Message-Phase: erzeugt subject+body fuer den Push-Commit (von push aufgerufen)."
}

phase_commit_message_preconditions() {
    if [[ ! -d /workspace/.git ]]; then
        echo "commit-message: /workspace nicht initialisiert." >&2
        return 2
    fi
    if ! agent_auth_present; then
        echo "commit-message: keine Authentifizierung für Agent '${AGENT_NAME:-claude_code}' — bitte CLAUDE_CODE_OAUTH_TOKEN (claude_code) oder ~/.codex/auth.json / OPENAI_API_KEY (codex) setzen." >&2
        return 3
    fi
    return 0
}

# _commit_message_build_user_prompt: produce the user prompt with diff + concept reference.
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

        # Compare working tree against base — push runs commit-message before
        # `git commit`, so changes are still uncommitted at this point.
        # 3-dot ${base}...HEAD would yield an empty diff and Claude would
        # generate a generic message.
        printf '## git diff %s\n\n```diff\n' "$base_ref"
        if git rev-parse --verify --quiet "$base_ref" >/dev/null; then
            git diff --no-color "${base_ref}" 2>/dev/null | head -n 800
        else
            git diff --no-color HEAD 2>/dev/null | head -n 800
        fi
        printf '\n```\n'
    }
}

# _commit_message_extract: read the envelope (stdin) and extract subject+body.
# Output: two lines: <subject>\n<body> (body may be multi-line at the end).
# Strategy:
#   1) try .structured_output.{subject,body}
#   2) fallback: parse .result as JSON (.result | fromjson)
# Returns: 0 if extracted, 1 otherwise.
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

    local output_json="/workspace/.agent/logs/commit-message.${ITERATION}.json"

    # commit-message is a 1-shot subroutine. For Claude we want the
    # cheapest available model (Haiku) explicitly — saves tokens on
    # the cheapest possible task. For Codex we DON'T pin a model:
    # Codex with a ChatGPT account refuses any explicit model name
    # and picks the right one based on the user's plan, so the only
    # safe path is to omit --model and let Codex default itself.
    # When a new agent lands, extend this case-switch (empty value
    # means "let the agent runner pick").
    local commit_model
    case "${AGENT_NAME:-claude-code}" in
        codex)         commit_model="" ;;
        claude-code|*) commit_model="claude-haiku-4-5-20251001" ;;
    esac

    local model_args=()
    [[ -n "$commit_model" ]] && model_args=(--model "$commit_model")

    log_info "commit-message: calling agent (json + json-schema)"
    set +e
    agent_run \
        --system-prompt-file "$sysprompt" \
        --user-prompt-file "$user_prompt_path" \
        --max-turns 8 \
        "${model_args[@]}" \
        --output-format json \
        --no-verbose \
        --json-schema "$schema_path" \
      | log_scrub > "$output_json"
    local agent_exit=${PIPESTATUS[0]}
    set -e
    if (( agent_exit != 0 )); then
        echo "commit-message: agent call failed (exit non-zero)" >&2
        local cli_err_text=""
        if [[ -f "$output_json" ]]; then
            cli_err_text="$(jq -r '.error.message // .message // ""' "$output_json" 2>/dev/null || true)"
        fi
        if agent_check_usage_limit "" "$cli_err_text"; then
            echo "  → usage/rate limit — backing off" >&2
            return "$EXIT_USAGE_LIMIT"
        fi
        return 3
    fi

    local is_error
    is_error="$(jq -r '.is_error // false' "$output_json" 2>/dev/null || echo true)"
    if [[ "$is_error" != "false" ]]; then
        local err_msg
        err_msg="$(jq -r '.result // "(no result field)"' "$output_json" 2>/dev/null)"
        echo "commit-message: agent returned is_error=true: $err_msg" >&2
        if agent_check_usage_limit "" "$err_msg"; then
            echo "  → usage/rate limit — backing off" >&2
            return "$EXIT_USAGE_LIMIT"
        fi
        if agent_check_auth_error "$err_msg"; then
            echo "  → Agent-Token ungültig oder abgelaufen." >&2
            echo "    Token erneuern: claude setup-token" >&2
            echo "    Dann: ./agent init --update-token" >&2
        fi
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

    local session_id cost input_tokens output_tokens
    session_id="$(jq -r '.session_id // ""' "$output_json")"
    cost="$(jq -r '.total_cost_usd // 0' "$output_json")"
    input_tokens="$(jq -r '.usage.input_tokens // 0' "$output_json")"
    output_tokens="$(jq -r '.usage.output_tokens // 0' "$output_json")"

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
        --raw claude_total_cost_usd "$cost" \
        --int input_tokens "$input_tokens" \
        --int output_tokens "$output_tokens"

    return 0
}
