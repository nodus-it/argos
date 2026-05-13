#!/usr/bin/env bash
# phases/implement.sh — implement phase: apply the code changes.
#
# Default: --fresh (git reset --hard origin/$BASE_BRANCH, git clean -fd
# without -x so vendor/ and node_modules/ survive), composer install / npm ci
# if a manifest exists, then a Claude session. Quality gates (Pint,
# Pest/PHPUnit, PHPStan when configured) are re-run by the worker AFTER the
# Claude session as a verification step — Claude is expected to have made
# them pass already. All four are blocking.

# shellcheck shell=bash

phase_implement_help() {
    echo "Implement-Phase: Code-Aenderungen umsetzen, Quality-Gates eigenstaendig durchlaufen."
}

phase_implement_preconditions() {
    if [[ ! -d /workspace/.git ]]; then
        echo "implement: /workspace ist nicht initialisiert — bitte 'agent concept' zuerst." >&2
        return 2
    fi
    if [[ ! -f /workspace/.agent/concept.md ]]; then
        echo "implement: /workspace/.agent/concept.md fehlt — bitte 'agent concept' zuerst." >&2
        return 2
    fi
    if [[ -z "${REPO_URL:-}" || -z "${REPO_TOKEN:-}" || -z "${BASE_BRANCH:-}" ]]; then
        echo "implement: REPO_URL/REPO_TOKEN/BASE_BRANCH muessen gesetzt sein." >&2
        return 2
    fi
    if ! agent_auth_present; then
        echo "implement: keine Authentifizierung für Agent '${AGENT_NAME:-claude_code}' — bitte CLAUDE_CODE_OAUTH_TOKEN (claude_code) oder ~/.codex/auth.json / OPENAI_API_KEY (codex) setzen." >&2
        return 3
    fi
    return 0
}

# _implement_reset_branch: --fresh reset of the workspace.
_implement_reset_branch() {
    log_info "implement: git fetch + reset --hard origin/${BASE_BRANCH}"
    set +x
    local auth_header
    auth_header="$(git_auth_header "$REPO_TOKEN")"
    if ! git -C /workspace -c "http.extraheader=$auth_header" fetch --quiet origin "$BASE_BRANCH"; then
        echo "implement: git fetch failed" >&2
        return 1
    fi
    git -C /workspace reset --hard "origin/$BASE_BRANCH"
    # -fd without -x: keep vendor/ and node_modules/ (which are gitignored).
    git -C /workspace clean -fd
}

# _implement_setup_toolchain: composer install / npm ci if a manifest exists.
_implement_setup_toolchain() {
    # Seed .env before composer install: post-autoload-dump runs
    # package:discover, which boots the target Laravel app. Without .env
    # vlucas/phpdotenv logs a "Failed to open" warning that ends up in the
    # composer log AND later in every pest run.
    quality_ensure_workspace_dotenv

    if [[ -f /workspace/composer.json ]]; then
        log_info "implement: composer install"
        if ! (cd /workspace && composer install --no-interaction --prefer-dist --no-progress 2>&1 \
                | tee "/workspace/.agent/logs/composer-install.${ITERATION}.log") ; then
            echo "implement: composer install failed (see logs)" >&2
            return 1
        fi
    fi
    if [[ -f /workspace/package-lock.json ]]; then
        log_info "implement: npm ci"
        if ! (cd /workspace && npm ci --no-audit --no-fund 2>&1 \
                | tee "/workspace/.agent/logs/npm-ci.${ITERATION}.log") ; then
            echo "implement: npm ci failed (see logs)" >&2
            return 1
        fi
    fi
    return 0
}

# _implement_build_user_prompt: produce the user prompt for the Claude implement session.
_implement_build_user_prompt() {
    local concept_file=/workspace/.agent/concept.md
    local notes_file=/workspace/.agent/implement.notes.md
    {
        printf '# Implement-Phase\n\n'
        printf 'Du befindest dich im Workspace `/workspace`. Setze das folgende Konzept um.\n\n'
        printf '## Konzept\n\n'
        cat "$concept_file"
        printf '\n'

        if [[ -f "$notes_file" && -s "$notes_file" ]]; then
            printf '\n## Anmerkungen des Users (implement.notes.md)\n\n'
            cat "$notes_file"
            printf '\n'
        fi

        printf '\n## Quality-Gates\n\n'
        printf 'Wie im System-Prompt beschrieben: Pint und Tests selbst laufen lassen, '
        printf 'iterieren bis gruen. KEIN git commit, KEIN git push — uebernehmen die '
        printf 'nachfolgenden Phasen.\n'
    }
}

# _implement_build_continue_prompt: short user prompt for `claude --resume`.
# Used when the previous run hit max-turns; the conversation history is
# already in the resumed session, so we just need to nudge Claude to keep
# going and finish the quality gates.
_implement_build_continue_prompt() {
    {
        printf '# Implement-Phase fortsetzen\n\n'
        printf 'Der vorherige Lauf wurde wegen des Turn-Limits abgebrochen. '
        printf 'Die Sitzung wird jetzt fortgesetzt — du hast den vollen Kontext '
        printf 'und siehst deine bisherigen Aenderungen im Workspace.\n\n'
        printf 'Bitte mache jetzt fertig, was noch offen ist:\n'
        printf '- pruefe `git status` und entscheide, welche Dateien noch fehlen\n'
        printf '- vervollstaendige fehlende Tests / Migrationen / Routen\n'
        printf '- lasse anschliessend die Quality-Gates lokal laufen '
        printf '(Pint, Pest/PHPUnit, PHPStan falls konfiguriert) und iteriere bis gruen\n'
        printf '- schreibe die Implementierungs-Summaries '
        printf '(implement.summary.nontechnical.md / implement.summary.technical.md) '
        printf 'falls noch nicht geschehen\n\n'
        printf 'KEIN git commit, KEIN git push.\n'
    }
}

# Backward-compat wrappers — implementation lives in lib/quality.sh.
_implement_changed_php_files() { quality_changed_php_files; }
_implement_run_quality_gates() { quality_gates_run "$@"; }
_implement_quality_gate_verdict() { quality_gate_verdict "$@"; }

phase_implement_run() {
    cd /workspace 2>/dev/null || {
        echo "implement: /workspace not mounted" >&2
        return 1
    }
    mkdir -p /workspace/.agent/logs

    local fresh continue_run
    fresh="$(echo "${PHASE_FLAGS:-}" | jq -r '.fresh // false' 2>/dev/null || echo false)"
    continue_run="$(echo "${PHASE_FLAGS:-}" | jq -r '.continue // false' 2>/dev/null || echo false)"

    # Default to fresh=true when neither --fresh nor --continue is set.
    if [[ "$fresh" == "false" && "$continue_run" == "false" ]]; then
        fresh="true"
    fi

    if [[ "$fresh" == "true" ]]; then
        _implement_reset_branch || return 1
    fi
    _implement_setup_toolchain || return 1

    local sysprompt
    sysprompt="$(build_system_prompt implement)" || return 1

    local user_prompt_path
    user_prompt_path="$(_implement_build_user_prompt | render_user_prompt implement user-prompt)"

    local started_at finished_at started_epoch finished_epoch
    started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    started_epoch=$(date -u +%s)

    local stream_log="/workspace/.agent/logs/implement.${ITERATION}.stream.log"
    local stderr_log="/workspace/.agent/logs/implement.${ITERATION}.stderr.log"
    local result_json="/workspace/.agent/logs/implement.${ITERATION}.result.json"
    local max_turns="${MAX_TURNS:-200}"

    # Resume mode: if continue=true AND a previous session_id is provided AND
    # the agent runner confirms the session file is still on disk, ask the
    # agent to continue that session instead of starting fresh. The
    # existence check is agent-aware (claude/codex use different layouts).
    local resume_args=() resume_input="$user_prompt_path"
    if [[ "$continue_run" == "true" && -n "${RESUME_SESSION_ID:-}" ]]; then
        if agent_session_file_exists "$RESUME_SESSION_ID"; then
            log_info "implement: resuming session $RESUME_SESSION_ID"
            resume_args=(--resume "$RESUME_SESSION_ID")
            local continue_prompt
            continue_prompt="$(_implement_build_continue_prompt | render_user_prompt implement continue-prompt)"
            resume_input="$continue_prompt"
        else
            log_warn "implement: RESUME_SESSION_ID=$RESUME_SESSION_ID has no session file — starting fresh session"
        fi
    fi

    log_info "implement: calling agent (stream-json, max-turns $max_turns${resume_args[*]:+, resume})"

    # Capture CLI stderr so the auth-error heuristic + manager-side
    # error_log promotion can inspect it after the run.
    export AGENT_STDERR_LOG="$stderr_log"
    : > "$stderr_log"

    set +e
    agent_run \
        --system-prompt-file "$sysprompt" \
        --user-prompt-file "$resume_input" \
        --max-turns "$max_turns" \
        --include-partial \
        "${resume_args[@]}" \
      | log_scrub \
      | tee "$stream_log" \
      | tee >(jq -rj '
            if .type == "assistant" then
                (.message.content[]? |
                    if .type == "text" then (.text // "")
                    elif .type == "tool_use" then
                        "\n[tool:" + .name + "] " +
                        (.input.file_path // .input.command // (.input | tostring)[0:120]) + "\n"
                    else empty end
                )
            elif .type == "result" then "\n"
            else empty end
          ' >&2 2>/dev/null) \
      | jq -c 'select(.type == "result")' \
      > "$result_json"
    local agent_exit=${PIPESTATUS[0]}
    set -e

    if (( agent_exit != 0 )); then
        log_warn "implement: agent exited with code $agent_exit"
        if agent_check_usage_limit "$stream_log"; then
            echo "  → usage/rate limit — backing off" >&2
            rm -f /workspace/.agent/implement.notes.md
            return "$EXIT_USAGE_LIMIT"
        fi
        # 401 / token expired: surface a precise hint, return EXIT_AUTH.
        if agent_check_auth_error_log "$stderr_log"; then
            echo "  → Agent-Token ungültig oder abgelaufen — Worker kann sich nicht authentifizieren." >&2
            echo "    Token in den Agent-Credentials erneuern (claude setup-token / ~/.codex/auth.json) und neu starten." >&2
            rm -f /workspace/.agent/implement.notes.md
            return "$EXIT_AUTH"
        fi
    fi

    # Drop the notes file after the agent call — it was written from the DB
    # by writeImplementNotesToVolume before the run and is no longer needed.
    rm -f /workspace/.agent/implement.notes.md

    if [[ ! -s "$result_json" ]]; then
        echo "implement: stream-json produced no result event" >&2
        return 3
    fi

    local is_error
    is_error="$(jq -r '.is_error // false' "$result_json" 2>/dev/null || echo true)"
    if [[ "$is_error" != "false" ]]; then
        local err_msg
        err_msg="$(jq -r '.result // "(no result field)"' "$result_json" 2>/dev/null)"
        echo "implement: agent returned is_error=true: $err_msg" >&2
        if agent_check_usage_limit "" "$err_msg"; then
            echo "  → usage/rate limit — backing off" >&2
            return "$EXIT_USAGE_LIMIT"
        fi
        if agent_check_auth_error "$err_msg" || agent_check_auth_error_log "$stderr_log"; then
            echo "  → Agent-Token ungültig oder abgelaufen." >&2
            echo "    Token erneuern: claude setup-token" >&2
            echo "    Dann: ./agent init --update-token" >&2
            return "$EXIT_AUTH"
        fi
        return 3
    fi

    # Quality gate verification with remediation loop.
    # If a gate fails the worker builds a focused fix prompt and runs a short
    # Claude session (~30 turns) to correct the specific issue, then re-checks.
    # Capped at GATE_RETRY_LIMIT attempts (default 3) to bound cost and time.
    local max_gate_retries="${GATE_RETRY_LIMIT:-3}"
    local gate_retry=0
    local gates="" failed_gate="" gate_exit=0

    while true; do
        local log_suffix="$ITERATION"
        if (( gate_retry > 0 )); then
            log_suffix="${ITERATION}.fix${gate_retry}"
            log_info "implement: re-verifying quality gates (fix ${gate_retry}/${max_gate_retries})"
        else
            log_info "implement: verifying quality gates"
        fi

        gates="$(quality_gates_run "$log_suffix")"
        set +e
        failed_gate="$(quality_gate_verdict "$gates")"
        gate_exit=$?
        set -e

        if [[ "$gate_exit" -eq 0 ]]; then break; fi

        # Bail out if the previous fix attempt produced byte-identical gate
        # output — Claude isn't moving the needle and further sessions just
        # burn tokens. (Only relevant after at least one fix run.)
        if (( gate_retry >= 1 )) \
                && quality_gate_log_converged "$failed_gate" "$ITERATION" "$gate_retry"; then
            log_warn "implement: gate '$failed_gate' produced identical output as the prior attempt — fix loop converged, stopping"
            break
        fi

        if (( gate_retry >= max_gate_retries )); then
            log_warn "implement: '$failed_gate' still failing after ${max_gate_retries} fix attempt(s) — giving up"
            break
        fi

        gate_retry=$(( gate_retry + 1 ))
        log_info "implement: gate '$failed_gate' failed — starting fix session ${gate_retry}/${max_gate_retries}"

        local gate_log
        case "$failed_gate" in
            artisan)    gate_log="/workspace/.agent/logs/artisan-smoke.${log_suffix}.log" ;;
            pint)       gate_log="/workspace/.agent/logs/pint.${log_suffix}.log" ;;
            pest)       gate_log="/workspace/.agent/logs/pest.${log_suffix}.log" ;;
            phpunit)    gate_log="/workspace/.agent/logs/phpunit.${log_suffix}.log" ;;
            phpstan)    gate_log="/workspace/.agent/logs/phpstan.${log_suffix}.log" ;;
            migrations) gate_log="/workspace/.agent/logs/migrations.${log_suffix}.log" ;;
            debug_code) gate_log="/workspace/.agent/logs/debug-code.${log_suffix}.log" ;;
            *)          gate_log="" ;;
        esac

        local fix_prompt_path
        fix_prompt_path="$(mktemp /tmp/argos-fix-XXXXXX.txt)"
        quality_gate_fix_prompt "$failed_gate" "$gate_log" > "$fix_prompt_path"

        local fix_stream_log="/workspace/.agent/logs/implement.${ITERATION}.fix${gate_retry}.stream.log"
        local fix_result_json="/workspace/.agent/logs/implement.${ITERATION}.fix${gate_retry}.result.json"

        set +e
        agent_run \
            --system-prompt-file "$sysprompt" \
            --user-prompt-file "$fix_prompt_path" \
            --max-turns "${GATE_FIX_MAX_TURNS:-30}" \
            --include-partial \
          | log_scrub \
          | tee "$fix_stream_log" \
          | tee >(jq -rj '
                if .type == "assistant" then
                    (.message.content[]? |
                        if .type == "text" then (.text // "")
                        elif .type == "tool_use" then
                            "\n[fix] " +
                            (.input.file_path // .input.command // (.input | tostring)[0:80]) + "\n"
                        else empty end
                    )
                elif .type == "result" then "\n"
                else empty end
              ' >&2 2>/dev/null) \
          | jq -c 'select(.type == "result")' \
          > "$fix_result_json"
        local fix_exit=${PIPESTATUS[0]}
        set -e

        rm -f "$fix_prompt_path"

        if (( fix_exit != 0 )); then
            log_warn "implement: fix session exited with code $fix_exit"
            if agent_check_usage_limit "$fix_stream_log"; then
                echo "  → usage/rate limit during fix — backing off" >&2
                return "$EXIT_USAGE_LIMIT"
            fi
        fi
    done

    local status="completed"
    if (( gate_exit == 4 )); then
        status="quality_gate_failed"
    fi

    # Collect the list of changed files.
    local changed_files_json='[]'
    if [[ -d /workspace/.git ]]; then
        local changed
        changed="$(git -C /workspace status --porcelain | awk '{$1=$1; print $2}' \
                    | jq -R . | jq -sc .)"
        changed_files_json="${changed:-[]}"
    fi

    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    finished_epoch=$(date -u +%s)
    local duration_ms=$(( (finished_epoch - started_epoch) * 1000 ))

    local session_id cost input_tokens output_tokens
    session_id="$(jq -r '.session_id // ""' "$result_json")"
    cost="$(jq -r '.total_cost_usd // 0' "$result_json")"
    input_tokens="$(jq -r '.usage.input_tokens // 0' "$result_json")"
    output_tokens="$(jq -r '.usage.output_tokens // 0' "$result_json")"

    local emit_args=(
        phase implement
        task_id "$TASK_ID"
        --int iteration "$ITERATION"
        status "$status"
        started_at "$started_at"
        finished_at "$finished_at"
        --int duration_ms "$duration_ms"
        --int exit_code "$gate_exit"
        --int gate_retries "$gate_retry"
        --raw changed_files "$changed_files_json"
        --raw quality_gates "$gates"
        claude_session_id "$session_id"
        --raw claude_total_cost_usd "$cost"
        --int input_tokens "$input_tokens"
        --int output_tokens "$output_tokens"
    )
    if [[ -n "$failed_gate" ]]; then
        emit_args+=(failed_gate "$failed_gate")
    fi
    result_emit "${emit_args[@]}"

    return "$gate_exit"
}
