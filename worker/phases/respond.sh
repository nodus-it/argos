#!/usr/bin/env bash
# phases/respond.sh — Phase respond: Review-Feedback einarbeiten.
#
# Liest /workspace/.agent/respond.feedback.md (vom Host via PhaseRunner geschrieben),
# fuehrt eine Claude-Session durch und arbeitet das Feedback in den bestehenden
# Feature-Branch ein. Quality-Gates werden anschliessend verifiziert.
# Danach: push-Phase starten um den aktualisierten Branch zu pushen.

# shellcheck shell=bash

phase_respond_help() {
    echo "Respond-Phase: Review-Feedback aus dem UI in den Feature-Branch einarbeiten."
}

phase_respond_preconditions() {
    if [[ ! -d /workspace/.git ]]; then
        echo "respond: /workspace ist nicht initialisiert — bitte zuerst implement + push ausfuehren." >&2
        return 2
    fi
    if [[ ! -f /workspace/.agent/concept.md ]]; then
        echo "respond: /workspace/.agent/concept.md fehlt." >&2
        return 2
    fi
    if [[ ! -f /workspace/.agent/respond.feedback.md ]]; then
        echo "respond: /workspace/.agent/respond.feedback.md fehlt — Feedback ueber das UI eingeben." >&2
        return 2
    fi
    if [[ -z "${REPO_URL:-}" || -z "${REPO_TOKEN:-}" || -z "${BASE_BRANCH:-}" ]]; then
        echo "respond: REPO_URL/REPO_TOKEN/BASE_BRANCH muessen gesetzt sein." >&2
        return 2
    fi
    if [[ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]]; then
        echo "respond: CLAUDE_CODE_OAUTH_TOKEN fehlt." >&2
        return 3
    fi
    return 0
}

# _respond_build_user_prompt: Erzeugt User-Prompt fuer Claude-Respond-Session.
_respond_build_user_prompt() {
    local feedback_file=/workspace/.agent/respond.feedback.md
    local concept_file=/workspace/.agent/concept.md
    {
        printf '# Respond-Phase — Review-Feedback einarbeiten\n\n'
        printf 'Du befindest dich im Workspace `/workspace`. Der Feature-Branch ist bereits ausgecheckt.\n\n'
        printf '## Review-Feedback\n\n'
        cat "$feedback_file"
        printf '\n\n## Urspruengliches Konzept (Referenz)\n\n'
        cat "$concept_file"
        printf '\n\n## Aktuelle Aenderungen auf dem Branch\n\n'
        printf '```\n'
        git -C /workspace log --oneline "origin/${BASE_BRANCH}..HEAD" 2>/dev/null || printf '(keine commits)'
        printf '\n```\n\n'
        printf 'Arbeite das Feedback ein. Nur die adressierten Punkte aendern — kein unrelatiertes Refactoring.\n'
        printf 'Quality-Gates (Pint, Tests) danach eigenstaendig ausfuehren.\n'
    }
}

phase_respond_run() {
    cd /workspace 2>/dev/null || {
        echo "respond: /workspace not mounted" >&2
        return 1
    }
    mkdir -p /workspace/.agent/logs

    local started_at finished_at started_epoch finished_epoch
    started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    started_epoch=$(date -u +%s)

    # System-Prompt
    local sysprompt
    sysprompt="$(build_system_prompt respond)" || return 1
    local sysprompt_content
    sysprompt_content="$(cat "$sysprompt")"

    # User-Prompt
    local user_prompt_path
    user_prompt_path="$(_respond_build_user_prompt | render_user_prompt respond user-prompt)"

    local stream_log="/workspace/.agent/logs/respond.${ITERATION}.stream.log"
    local result_json="/workspace/.agent/logs/respond.${ITERATION}.result.json"
    local max_turns="${MAX_TURNS:-50}"

    log_info "respond: rufe claude (stream-json, max-turns $max_turns) auf"

    set +e
    claude -p \
        --append-system-prompt "$sysprompt_content" \
        --output-format stream-json \
        --verbose \
        --include-partial-messages \
        --permission-mode bypassPermissions \
        --max-turns "$max_turns" \
        < "$user_prompt_path" \
        | tee "$stream_log" \
        | tee >(jq -rj '
            if .type == "assistant" then
                (.message.content[]? | select(.type == "text") | .text // "")
            elif .type == "result" then "\n"
            else empty end
          ' 2>/dev/null >&2) \
        | jq -c 'select(.type == "result")' \
        > "$result_json"
    local claude_exit=${PIPESTATUS[0]}
    set -e

    if [[ ! -s "$result_json" ]]; then
        echo "respond: stream-json lieferte kein result-Event" >&2
        return 3
    fi

    local is_error
    is_error="$(jq -r '.is_error // false' "$result_json" 2>/dev/null || echo true)"
    if [[ "$is_error" != "false" ]]; then
        local err_msg
        err_msg="$(jq -r '.result // "(no result field)"' "$result_json" 2>/dev/null)"
        echo "respond: claude returned is_error=true: $err_msg" >&2
        if echo "$err_msg" | grep -qiE "invalid api key|authentication|oauth|unauthorized|401|token.*expired|invalid_api_key"; then
            echo "  → Claude-OAuth-Token ungültig oder abgelaufen." >&2
            echo "    Token erneuern: claude setup-token" >&2
            echo "    Dann: ./agent init --update-token" >&2
        fi
        return 3
    fi

    if (( claude_exit != 0 )); then
        log_warn "respond: claude exited with code $claude_exit"
    fi

    # Quality-Gates (gleiche Logik wie implement)
    log_info "respond: verifiziere Quality-Gates"
    local gates='{"pint":"skip","pest":"skip","phpunit":"skip","phpstan":"skip"}'
    local failed_gate=""

    if [[ -x /workspace/vendor/bin/pint ]]; then
        if (cd /workspace && vendor/bin/pint --test) \
                &> "/workspace/.agent/logs/pint.${ITERATION}.log"; then
            gates="$(echo "$gates" | jq '.pint = "pass"')"
        else
            gates="$(echo "$gates" | jq '.pint = "fail"')"
            failed_gate="pint"
        fi
    fi

    if [[ -z "$failed_gate" ]]; then
        if [[ -x /workspace/vendor/bin/pest ]]; then
            if (cd /workspace && vendor/bin/pest --no-coverage) \
                    &> "/workspace/.agent/logs/pest.${ITERATION}.log"; then
                gates="$(echo "$gates" | jq '.pest = "pass"')"
            else
                gates="$(echo "$gates" | jq '.pest = "fail"')"
                failed_gate="pest"
            fi
        elif [[ -x /workspace/vendor/bin/phpunit ]]; then
            if (cd /workspace && vendor/bin/phpunit) \
                    &> "/workspace/.agent/logs/phpunit.${ITERATION}.log"; then
                gates="$(echo "$gates" | jq '.phpunit = "pass"')"
            else
                gates="$(echo "$gates" | jq '.phpunit = "fail"')"
                failed_gate="phpunit"
            fi
        fi
    fi

    local session_id cost input_tokens output_tokens status exit_code
    session_id="$(jq -r '.session_id // "unknown"' "$result_json" 2>/dev/null)"
    cost="$(jq -r '.total_cost_usd // 0' "$result_json" 2>/dev/null)"
    input_tokens="$(jq -r '.usage.input_tokens // 0' "$result_json" 2>/dev/null)"
    output_tokens="$(jq -r '.usage.output_tokens // 0' "$result_json" 2>/dev/null)"

    if [[ -n "$failed_gate" ]]; then
        status="quality_gate_failed"
        exit_code=4
    else
        status="completed"
        exit_code=0
    fi

    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    finished_epoch=$(date -u +%s)
    local duration_ms=$(( (finished_epoch - started_epoch) * 1000 ))

    result_emit \
        phase respond \
        task_id "$TASK_ID" \
        --int iteration "$ITERATION" \
        status "$status" \
        started_at "$started_at" \
        finished_at "$finished_at" \
        --int duration_ms "$duration_ms" \
        --int exit_code "$exit_code" \
        ${failed_gate:+failed_gate "$failed_gate"} \
        --raw quality_gates "$gates" \
        claude_session_id "$session_id" \
        --raw claude_total_cost_usd "$cost" \
        --int input_tokens "$input_tokens" \
        --int output_tokens "$output_tokens"

    return "$exit_code"
}
