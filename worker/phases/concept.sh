#!/usr/bin/env bash
# phases/concept.sh — concept phase: analyse the task, draft the plan.
#
# Sourced by the worker entrypoint. Expects:
#   - lib/{logging,error,result,state,prompts}.sh already sourced
#   - env: TASK_ID, REPO_URL, REPO_TOKEN, BASE_BRANCH, ITERATION,
#          PHASE_FLAGS (JSON), CLAUDE_CODE_OAUTH_TOKEN
#   - /workspace is the task volume (mounted)
#   - /workspace/.agent/description.md is bind-mounted read-only
#     from the host (see lib/docker.sh)

# shellcheck shell=bash

# Single source of truth for the feature-branch prefix.
# Tests and tooling should derive this value from here, not hardcode it.
CONCEPT_BRANCH_PREFIX="feat"

# phase_concept_help: short description for `agent help concept`.
phase_concept_help() {
    echo "Konzept-Phase: Aufgabe analysieren und Plan formulieren."
}

# phase_concept_preconditions: check the run is feasible.
# Returns: 0 if OK, otherwise an exit code with a message on stderr.
phase_concept_preconditions() {
    if [[ ! -f /run/agent/description.md ]]; then
        echo "concept: /run/agent/description.md fehlt — bitte 'agent task new' wiederholen oder description.md unter ~/.agent/tasks/<id>/ anlegen." >&2
        return 2
    fi
    if [[ -z "${REPO_URL:-}" || -z "${REPO_TOKEN:-}" || -z "${BASE_BRANCH:-}" ]]; then
        echo "concept: REPO_URL/REPO_TOKEN/BASE_BRANCH muessen gesetzt sein." >&2
        return 2
    fi
    if [[ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]]; then
        echo "concept: CLAUDE_CODE_OAUTH_TOKEN fehlt." >&2
        return 3
    fi
    return 0
}

# _concept_initial_clone: clone the repo into the volume and create the feature branch.
_concept_initial_clone() {
    set +x
    local auth_url
    auth_url="$(git_auth_inject_token "$REPO_URL" "$REPO_TOKEN")"

    local feature_branch slug
    slug="$(printf '%s' "${TASK_ID}" | tr ' /' '-' | tr -cd 'a-zA-Z0-9._-')"
    feature_branch="${CONCEPT_BRANCH_PREFIX}/${slug}"

    # `git clone` refuses to clone into a non-empty /workspace
    # (/workspace/.agent/ already exists). Use init + fetch + checkout instead.
    cd /workspace || return 1
    if ! git init --quiet --initial-branch="$BASE_BRANCH" 2>/workspace/.agent/logs/clone.err; then
        echo "concept: git init failed (see logs/clone.err)" >&2
        return 1
    fi
    # /workspace/.agent/ holds our state and must NOT be wiped by `git clean -fd`
    # in later phases. .git/info/exclude marks it as locally ignored (no churn
    # against the fake-remote/repo).
    mkdir -p /workspace/.git/info
    grep -qxF '.agent/' /workspace/.git/info/exclude 2>/dev/null \
        || echo '.agent/' >> /workspace/.git/info/exclude
    git remote add origin "$auth_url" 2>/dev/null || git remote set-url origin "$auth_url"
    if ! git fetch --quiet --depth=1 origin "$BASE_BRANCH" 2>>/workspace/.agent/logs/clone.err; then
        echo "concept: git fetch failed (see logs/clone.err)" >&2
        git remote set-url origin "$REPO_URL"
        # remove .git so the next attempt can retry from scratch
        rm -rf /workspace/.git
        return 1
    fi
    if ! git checkout -B "$feature_branch" "origin/$BASE_BRANCH" 2>>/workspace/.agent/logs/clone.err; then
        echo "concept: git checkout failed (see logs/clone.err)" >&2
        git remote set-url origin "$REPO_URL"
        rm -rf /workspace/.git
        return 1
    fi
    # restore the original (token-less) URL so credentials don't persist
    # inside the workspace.
    git remote set-url origin "$REPO_URL"

    state_set_feature_branch "$feature_branch"
}

# _concept_archive_to_history: archive concept/notes into concept.history/.
# Args: $1=mode ("move"|"copy")
# Output: count of history files now present.
_concept_archive_to_history() {
    local mode="$1"
    local concept_file=/workspace/.agent/concept.md
    local notes_file=/workspace/.agent/concept.notes.md
    local hist_dir=/workspace/.agent/concept.history
    mkdir -p "$hist_dir"
    local ts
    ts="$(date -u +%Y%m%dT%H%M%S)"

    if [[ -f "$concept_file" ]]; then
        if [[ "$mode" == "move" ]]; then
            mv "$concept_file" "$hist_dir/concept.${ts}.md"
        else
            cp "$concept_file" "$hist_dir/concept.${ts}.md"
        fi
    fi
    # Don't archive empty notes — those appear when no feedback was given.
    # The iteration is encoded in the filename for later attribution.
    if [[ -f "$notes_file" && -s "$notes_file" ]]; then
        local iter_suffix=""
        [[ -n "${ITERATION:-}" ]] && iter_suffix=".iter${ITERATION}"
        if [[ "$mode" == "move" ]]; then
            mv "$notes_file" "$hist_dir/concept.notes.${ts}${iter_suffix}.md"
        else
            # In copy mode the file stays — notes are removed AFTER the Claude
            # call so that _concept_build_user_prompt can still read them.
            cp "$notes_file" "$hist_dir/concept.notes.${ts}${iter_suffix}.md"
        fi
    fi

    find "$hist_dir" -maxdepth 1 -type f -name 'concept.*.md' 2>/dev/null | wc -l
}

# _concept_build_user_prompt: produce the user-prompt markdown on stdout.
# Args: $1=fresh ("true"|"false"), $2=has_existing ("true"|"false")
_concept_build_user_prompt() {
    local fresh="$1" has_existing="$2"
    local description_file=/run/agent/description.md
    local concept_file=/workspace/.agent/concept.md
    local notes_file=/workspace/.agent/concept.notes.md

    {
        printf '# Konzept-Aufgabe\n\n'
        printf '## Aufgabenbeschreibung\n\n'
        cat "$description_file"
        printf '\n'

        if [[ "$fresh" == "false" && "$has_existing" == "true" ]]; then
            printf '\n## Vorheriges Konzept (zur Verfeinerung)\n\n'
            cat "$concept_file"
            printf '\n'
        fi

        if [[ -f "$notes_file" && -s "$notes_file" ]]; then
            printf '\n## Anmerkungen des Users (concept.notes.md)\n\n'
            cat "$notes_file"
            printf '\n'
        fi

        printf '\n## Erwartung\n\n'
        printf 'Antworte direkt mit dem Konzept-Markdown gemaess System-Prompt-Format. '
        printf 'KEINE Datei schreiben — der Worker uebernimmt das.\n'
    }
}

# phase_concept_run: main phase logic.
# Returns: exit code (0 ok, 1 general, 2 precondition, 3 auth).
phase_concept_run() {
    cd /workspace 2>/dev/null || {
        echo "concept: /workspace not mounted" >&2
        return 1
    }

    mkdir -p /workspace/.agent/logs

    local fresh
    fresh="$(echo "${PHASE_FLAGS:-}" | jq -r '.fresh // false' 2>/dev/null || echo false)"

    if [[ ! -d /workspace/.git ]]; then
        log_info "concept: cloning $REPO_URL into /workspace"
        _concept_initial_clone || return 1
    fi
    cd /workspace || return 1

    # On --fresh move the prior concept aside; otherwise just copy it.
    local concept_file=/workspace/.agent/concept.md
    local has_existing=false
    [[ -f "$concept_file" ]] && has_existing=true

    local history_count
    if [[ "$fresh" == "true" && "$has_existing" == "true" ]]; then
        history_count="$(_concept_archive_to_history move)"
        has_existing=false
    elif [[ "$has_existing" == "true" ]]; then
        history_count="$(_concept_archive_to_history copy)"
    else
        history_count=0
        # Notes without a concept can exist — archive them too if non-empty.
        if [[ -f /workspace/.agent/concept.notes.md && -s /workspace/.agent/concept.notes.md ]]; then
            history_count="$(_concept_archive_to_history copy)"
        fi
    fi

    local sysprompt
    sysprompt="$(build_system_prompt concept)" || return 1

    local user_prompt_path
    user_prompt_path="$(_concept_build_user_prompt "$fresh" "$has_existing" | render_user_prompt concept user-prompt)"

    local started_at finished_at started_epoch finished_epoch
    started_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    started_epoch=$(date -u +%s)

    local stream_log="/workspace/.agent/logs/concept.${ITERATION}.stream.log"
    local result_json="/workspace/.agent/logs/concept.${ITERATION}.result.json"
    local sysprompt_content
    sysprompt_content="$(cat "$sysprompt")"

    log_info "concept: calling claude (stream-json, max-turns 15)"

    set +e
    claude -p \
        --append-system-prompt "$sysprompt_content" \
        --output-format stream-json \
        --verbose \
        --max-turns 15 \
        --permission-mode bypassPermissions \
        < "$user_prompt_path" \
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
        echo "concept: claude call failed (exit $claude_exit)" >&2
        if claude_check_usage_limit "$stream_log"; then
            echo "  → usage/rate limit — backing off" >&2
            return "$EXIT_USAGE_LIMIT"
        fi
        return 3
    fi

    local is_error
    is_error="$(jq -r '.is_error // false' "$result_json" 2>/dev/null || echo true)"
    if [[ "$is_error" != "false" ]]; then
        local err_msg
        err_msg="$(jq -r '.result // "(no result field)"' "$result_json" 2>/dev/null)"
        echo "concept: claude returned is_error=true: $err_msg" >&2
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

    local concept_text
    concept_text="$(jq -r '.result' "$result_json")"
    if [[ -z "$concept_text" || "$concept_text" == "null" ]]; then
        echo "concept: claude returned empty .result" >&2
        return 1
    fi
    printf '%s\n' "$concept_text" > "${concept_file}.tmp"
    mv "${concept_file}.tmp" "$concept_file"

    # Drop the notes file — it was already copied into history above. Don't
    # call _concept_archive_to_history move here: that would also move the
    # freshly written concept.md into history and clear it from the workspace.
    rm -f /workspace/.agent/concept.notes.md

    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    finished_epoch=$(date -u +%s)
    local duration_ms=$(( (finished_epoch - started_epoch) * 1000 ))
    local session_id cost input_tokens output_tokens
    session_id="$(jq -r '.session_id // ""' "$result_json")"
    cost="$(jq -r '.total_cost_usd // 0' "$result_json")"
    input_tokens="$(jq -r '.usage.input_tokens // 0' "$result_json")"
    output_tokens="$(jq -r '.usage.output_tokens // 0' "$result_json")"

    result_emit \
        phase concept \
        task_id "$TASK_ID" \
        --int iteration "$ITERATION" \
        status completed \
        started_at "$started_at" \
        finished_at "$finished_at" \
        --int duration_ms "$duration_ms" \
        --int exit_code 0 \
        concept_path "$concept_file" \
        --int concept_history_count "$history_count" \
        claude_session_id "$session_id" \
        --raw claude_total_cost_usd "$cost" \
        --int input_tokens "$input_tokens" \
        --int output_tokens "$output_tokens"

    return 0
}
