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
    if [[ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]]; then
        echo "implement: CLAUDE_CODE_OAUTH_TOKEN fehlt." >&2
        return 3
    fi
    return 0
}

# _implement_reset_branch: --fresh reset of the workspace.
_implement_reset_branch() {
    log_info "implement: git fetch + reset --hard origin/${BASE_BRANCH}"
    set +x
    local auth_url
    auth_url="$(git_auth_inject_token "$REPO_URL" "$REPO_TOKEN")"
    git -C /workspace remote set-url origin "$auth_url"
    if ! git -C /workspace fetch --quiet origin "$BASE_BRANCH"; then
        echo "implement: git fetch failed" >&2
        git -C /workspace remote set-url origin "$REPO_URL"
        return 1
    fi
    git -C /workspace reset --hard "origin/$BASE_BRANCH"
    # -fd without -x: keep vendor/ and node_modules/ (which are gitignored).
    git -C /workspace clean -fd
    git -C /workspace remote set-url origin "$REPO_URL"
}

# _implement_setup_toolchain: composer install / npm ci if a manifest exists.
_implement_setup_toolchain() {
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

# _implement_changed_php_files: list PHP files modified or added by Claude in /workspace.
# Output: NUL-separated list on stdout. Excludes deleted files and untracked-ignored paths.
_implement_changed_php_files() {
    (cd /workspace && {
        git diff -z --name-only --diff-filter=ACMR HEAD -- '*.php'
        git ls-files -z --others --exclude-standard -- '*.php'
    } | sort -uz)
}

# _implement_run_quality_gates: run Pint, Pest/PHPUnit, PHPStan (when configured).
# Output: JSON {pint, pest, phpunit, phpstan} on stdout.
# Return: always 0 (status is captured in the JSON).
_implement_run_quality_gates() {
    local gates='{"pint":"skip","pest":"skip","phpunit":"skip","phpstan":"skip"}'

    if [[ -x /workspace/vendor/bin/pint ]]; then
        # Only check files Claude actually touched — pre-existing style debt
        # in the rest of the repo is not Claude's to fix and would otherwise
        # block every implement run.
        # mapfile -d '' reads the NUL-separated list directly into an array;
        # using $(...) would strip the NULs and concatenate filenames.
        local -a changed_files=()
        mapfile -d '' -t changed_files < <(_implement_changed_php_files)

        if [[ ${#changed_files[@]} -eq 0 ]]; then
            gates="$(echo "$gates" | jq '.pint = "skip"')"
        else
            if (cd /workspace && vendor/bin/pint --test "${changed_files[@]}") \
                    &> "/workspace/.agent/logs/pint.${ITERATION}.log"; then
                gates="$(echo "$gates" | jq '.pint = "pass"')"
            else
                gates="$(echo "$gates" | jq '.pint = "fail"')"
            fi
        fi
    fi

    if [[ -x /workspace/vendor/bin/pest ]]; then
        if (cd /workspace && vendor/bin/pest --no-coverage) \
                &> "/workspace/.agent/logs/pest.${ITERATION}.log"; then
            gates="$(echo "$gates" | jq '.pest = "pass"')"
        else
            gates="$(echo "$gates" | jq '.pest = "fail"')"
        fi
    elif [[ -x /workspace/vendor/bin/phpunit ]]; then
        if (cd /workspace && vendor/bin/phpunit) \
                &> "/workspace/.agent/logs/phpunit.${ITERATION}.log"; then
            gates="$(echo "$gates" | jq '.phpunit = "pass"')"
        else
            gates="$(echo "$gates" | jq '.phpunit = "fail"')"
        fi
    fi

    if [[ -f /workspace/phpstan.neon || -f /workspace/phpstan.neon.dist ]] \
            && [[ -x /workspace/vendor/bin/phpstan ]]; then
        if (cd /workspace && vendor/bin/phpstan analyse --no-progress) \
                &> "/workspace/.agent/logs/phpstan.${ITERATION}.log"; then
            gates="$(echo "$gates" | jq '.phpstan = "pass"')"
        else
            gates="$(echo "$gates" | jq '.phpstan = "fail"')"
        fi
    fi

    echo "$gates" > "/workspace/.agent/quality-gates.${ITERATION}.json"
    printf '%s' "$gates"
}

# _implement_quality_gate_verdict: derive the failing-gate name from the gates JSON.
# Args: $1=gates_json
# Output: failed-gate name, or empty.
# Return: 0 if all blocking gates pass, 4 if one fails.
_implement_quality_gate_verdict() {
    local gates="$1"
    local pint pest phpunit phpstan
    pint="$(echo "$gates" | jq -r '.pint')"
    pest="$(echo "$gates" | jq -r '.pest')"
    phpunit="$(echo "$gates" | jq -r '.phpunit')"
    phpstan="$(echo "$gates" | jq -r '.phpstan')"
    if [[ "$pint" == "fail" ]]; then
        printf 'pint'; return 4
    fi
    if [[ "$pest" == "fail" ]]; then
        printf 'pest'; return 4
    fi
    if [[ "$phpunit" == "fail" ]]; then
        printf 'phpunit'; return 4
    fi
    if [[ "$phpstan" == "fail" ]]; then
        printf 'phpstan'; return 4
    fi
    printf ''; return 0
}

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
    local result_json="/workspace/.agent/logs/implement.${ITERATION}.result.json"
    local sysprompt_content
    sysprompt_content="$(cat "$sysprompt")"
    local max_turns="${MAX_TURNS:-200}"

    # Resume mode: if continue=true AND a previous session_id is provided AND
    # its session file is still on disk, ask Claude to continue that session
    # instead of starting fresh.
    local resume_args=() resume_input="$user_prompt_path"
    if [[ "$continue_run" == "true" && -n "${RESUME_SESSION_ID:-}" ]]; then
        local cfg_dir="${CLAUDE_CONFIG_DIR:-$HOME/.claude}"
        local session_file="$cfg_dir/projects/-workspace/${RESUME_SESSION_ID}.jsonl"
        if [[ -f "$session_file" ]]; then
            log_info "implement: resuming session $RESUME_SESSION_ID"
            resume_args=(--resume "$RESUME_SESSION_ID")
            local continue_prompt
            continue_prompt="$(_implement_build_continue_prompt | render_user_prompt implement continue-prompt)"
            resume_input="$continue_prompt"
        else
            log_warn "implement: RESUME_SESSION_ID set but session file not found ($session_file) — starting fresh session"
        fi
    fi

    log_info "implement: calling claude (stream-json, max-turns $max_turns${resume_args[*]:+, resume})"

    set +e
    claude -p \
        --append-system-prompt "$sysprompt_content" \
        --output-format stream-json \
        --verbose \
        --include-partial-messages \
        --permission-mode bypassPermissions \
        --max-turns "$max_turns" \
        "${resume_args[@]}" \
        < "$resume_input" \
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
    local claude_exit=${PIPESTATUS[0]}
    set -e

    if (( claude_exit != 0 )); then
        log_warn "implement: claude exited with code $claude_exit"
        if claude_check_usage_limit "$stream_log"; then
            echo "  → usage/rate limit — backing off" >&2
            rm -f /workspace/.agent/implement.notes.md
            return "$EXIT_USAGE_LIMIT"
        fi
    fi

    # Drop the notes file after the Claude call — it was written from the DB
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
        echo "implement: claude returned is_error=true: $err_msg" >&2
        if claude_check_usage_limit "" "$err_msg"; then
            echo "  → usage/rate limit — backing off" >&2
            return "$EXIT_USAGE_LIMIT"
        fi
        if echo "$err_msg" | grep -qiE "invalid api key|authentication|oauth|unauthorized|401|token.*expired|invalid_api_key"; then
            echo "  → Claude-OAuth-Token ungültig oder abgelaufen." >&2
            echo "    Token erneuern: claude setup-token" >&2
            echo "    Dann: ./agent init --update-token" >&2
        fi
        return 3
    fi

    # Verification step: run the quality gates.
    log_info "implement: verifying quality gates (pint/pest/phpstan)"
    local gates
    gates="$(_implement_run_quality_gates)"

    local failed_gate gate_exit
    set +e
    failed_gate="$(_implement_quality_gate_verdict "$gates")"
    gate_exit=$?
    set -e

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
