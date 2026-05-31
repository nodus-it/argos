#!/usr/bin/env bash
# phases/concept.sh — concept phase: analyse the task, draft the plan.
#
# Sourced by the worker entrypoint. Expects:
#   - lib/{logging,error,result,state,prompts}.sh already sourced
#   - env: TASK_ID, REPO_URL, REPO_TOKEN, BASE_BRANCH, ITERATION,
#          PHASE_FLAGS (JSON), CLAUDE_CODE_OAUTH_TOKEN
#   - /workspace is the task volume (mounted)
#   - /workspace/.agent/description.md is bind-mounted read-only
#     from the host (PhaseRunner manages the docker run invocation)

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
    if ! agent_auth_present; then
        echo "concept: keine Authentifizierung für Agent '${AGENT_NAME:-claude_code}' — bitte CLAUDE_CODE_OAUTH_TOKEN (claude_code) oder ~/.codex/auth.json / OPENAI_API_KEY (codex) setzen." >&2
        return 3
    fi
    return 0
}

# _concept_emit_clone_err: stream the contents of logs/clone.err to stderr so
# the host-side bg.log captures the real git error, not just a generic message.
# Args: none. Reads /workspace/.agent/logs/clone.err if present.
_concept_emit_clone_err() {
    local err_file=/workspace/.agent/logs/clone.err
    [[ -s "$err_file" ]] || return 0
    echo "----- clone.err -----" >&2
    sed 's/^/    /' "$err_file" >&2
    echo "---------------------" >&2
}

# _concept_classify_fetch_err: classify the contents of clone.err into one of
# the known git fetch failure modes. Output: a short tag on stdout — one of
# "branch_not_found", "auth", "network", "unknown".
# Args: none. Reads /workspace/.agent/logs/clone.err.
_concept_classify_fetch_err() {
    local err_file=/workspace/.agent/logs/clone.err
    [[ -s "$err_file" ]] || { echo "unknown"; return 0; }

    # Order matters: auth/network signals are more specific than the generic
    # "couldn't find remote ref" line that some servers emit on auth failure.
    if grep -qiE 'authentication failed|invalid credentials|HTTP 401|HTTP 403|could not read Username' "$err_file"; then
        echo "auth"
        return 0
    fi
    if grep -qiE 'TLS connection|GnuTLS|SSL_read|RPC failed|early EOF|unexpected disconnect|fetch-pack: invalid|Could not resolve host|Connection refused|Connection reset|Operation timed out|HTTP 5[0-9]{2}' "$err_file"; then
        echo "network"
        return 0
    fi
    if grep -qiE "couldn't find remote ref|fatal: Remote branch .* not found|HTTP 404" "$err_file"; then
        echo "branch_not_found"
        return 0
    fi

    echo "unknown"
}

# _concept_initial_clone: clone the repo into the volume and create the feature branch.
_concept_initial_clone() {
    set +x
    local auth_header
    auth_header="$(git_auth_header "$REPO_TOKEN")"

    local feature_branch slug
    slug="$(printf '%s' "${TASK_ID}" | tr ' /' '-' | tr -cd 'a-zA-Z0-9._-')"
    feature_branch="${CONCEPT_BRANCH_PREFIX}/${slug}"

    # `git clone` refuses to clone into a non-empty /workspace
    # (/workspace/.agent/ already exists). Use init + fetch + checkout instead.
    cd /workspace || return 1
    if ! git init --quiet --initial-branch="$BASE_BRANCH" 2>/workspace/.agent/logs/clone.err; then
        echo "concept: git init failed" >&2
        _concept_emit_clone_err
        return 1
    fi
    # /workspace/.agent/ holds our state and must NOT be wiped by `git clean -fd`
    # in later phases. .git/info/exclude marks it as locally ignored (no churn
    # against the fake-remote/repo).
    mkdir -p /workspace/.git/info
    grep -qxF '.agent/' /workspace/.git/info/exclude 2>/dev/null \
        || echo '.agent/' >> /workspace/.git/info/exclude
    # Token-less origin URL — auth comes via the per-command http.extraheader.
    git remote add origin "$REPO_URL" 2>/dev/null || git remote set-url origin "$REPO_URL"
    if ! git -c "http.extraheader=$auth_header" fetch --quiet --depth=1 origin "$BASE_BRANCH" 2>>/workspace/.agent/logs/clone.err; then
        local kind
        kind="$(_concept_classify_fetch_err)"
        case "$kind" in
            branch_not_found)
                echo "concept: git fetch failed — Branch '$BASE_BRANCH' im Repo $REPO_URL nicht gefunden." >&2
                ;;
            auth)
                echo "concept: git fetch failed — Authentifizierung am Repo $REPO_URL abgelehnt (Token gültig? Scope ausreichend?)." >&2
                ;;
            network)
                echo "concept: git fetch failed — Netzwerk-/TLS-Fehler beim Verbinden mit $REPO_URL (siehe clone.err für Details)." >&2
                ;;
            *)
                echo "concept: git fetch failed (siehe clone.err für Details)." >&2
                ;;
        esac
        _concept_emit_clone_err
        # remove .git so the next attempt can retry from scratch
        rm -rf /workspace/.git
        return 1
    fi
    if ! git checkout -B "$feature_branch" "origin/$BASE_BRANCH" 2>>/workspace/.agent/logs/clone.err; then
        echo "concept: git checkout failed" >&2
        _concept_emit_clone_err
        rm -rf /workspace/.git
        return 1
    fi

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

# _concept_build_continue_prompt: short user prompt for `claude --resume`.
# Used when the previous run hit max-turns; the conversation history is
# already in the resumed session, so we just need to nudge Claude to finish.
_concept_build_continue_prompt() {
    {
        printf '# Konzept-Phase fortsetzen\n\n'
        printf 'Der vorherige Lauf wurde wegen des Turn-Limits abgebrochen. '
        printf 'Die Sitzung wird jetzt fortgesetzt — du hast den vollen Kontext '
        printf 'und siehst deine bisherige Recherche.\n\n'
        printf 'Bitte schliesse das Konzept jetzt ab:\n'
        printf -- '- pruefe, ob du genug Kontext fuer einen vollstaendigen Plan hast\n'
        printf -- '- antworte direkt mit dem Konzept-Markdown gemaess System-Prompt-Format\n'
        printf -- '- KEINE Datei schreiben — der Worker uebernimmt das\n'
    }
}

# _concept_setup_toolchain: composer install if a manifest exists. Concept is
# read-only by design but still benefits from a populated vendor/ — Boost's
# MCP server (php artisan boost:mcp) and tools like `php artisan route:list`
# need it. Failure is non-fatal: concept can still produce a plan from the
# description and source tree alone, just without Boost discovery.
_concept_setup_toolchain() {
    if [[ ! -f /workspace/composer.json ]]; then
        return 0
    fi
    # Same rationale as in implement: composer post-autoload-dump boots
    # Laravel which reads .env via vlucas; seed the file so Boost MCP /
    # artisan commands don't crash on the first read. The Vite hot stub keeps
    # apps that touch Vite in boot() from aborting package:discover (no built
    # assets in the worker → no manifest).
    quality_ensure_workspace_dotenv
    quality_ensure_vite_hot

    log_info "concept: composer install"
    if ! (cd /workspace && composer install --no-interaction --prefer-dist --no-progress 2>&1 \
            | tee "/workspace/.agent/logs/composer-install.${ITERATION}.log") ; then
        log_warn "concept: composer install failed — Boost MCP and vendor-based artisan commands will be unavailable for this run"
    fi
    return 0
}

# phase_concept_run: main phase logic.
# Returns: exit code (0 ok, 1 general, 2 precondition, 3 auth).
phase_concept_run() {
    cd /workspace 2>/dev/null || {
        echo "concept: /workspace not mounted" >&2
        return 1
    }

    mkdir -p /workspace/.agent/logs

    local fresh continue_run
    fresh="$(echo "${PHASE_FLAGS:-}" | jq -r '.fresh // false' 2>/dev/null || echo false)"
    continue_run="$(echo "${PHASE_FLAGS:-}" | jq -r '.continue // false' 2>/dev/null || echo false)"

    if [[ ! -d /workspace/.git ]]; then
        log_info "concept: cloning $REPO_URL into /workspace"
        _concept_initial_clone || return 1
    fi
    cd /workspace || return 1

    _concept_setup_toolchain

    # On --fresh move the prior concept aside; otherwise just copy it.
    # In continue-mode we never touch history — the resume picks up the
    # same in-flight session and the previous concept.md is irrelevant.
    local concept_file=/workspace/.agent/concept.md
    local has_existing=false
    [[ -f "$concept_file" ]] && has_existing=true

    local history_count
    if [[ "$continue_run" == "true" ]]; then
        history_count=0
    elif [[ "$fresh" == "true" && "$has_existing" == "true" ]]; then
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
    local stderr_log="/workspace/.agent/logs/concept.${ITERATION}.stderr.log"
    local result_json="/workspace/.agent/logs/concept.${ITERATION}.result.json"
    local max_turns="${MAX_TURNS:-30}"

    # Resume mode: if continue=true AND a previous session_id is provided AND
    # the agent runner confirms the session file is still on disk, ask the
    # agent to continue that session instead of starting fresh. The
    # existence check is agent-aware so Codex doesn't fall through Claude's
    # CLAUDE_CONFIG_DIR path layout.
    local resume_args=() resume_input="$user_prompt_path"
    if [[ "$continue_run" == "true" && -n "${RESUME_SESSION_ID:-}" ]]; then
        if agent_session_file_exists "$RESUME_SESSION_ID"; then
            log_info "concept: resuming session $RESUME_SESSION_ID"
            resume_args=(--resume "$RESUME_SESSION_ID")
            local continue_prompt
            continue_prompt="$(_concept_build_continue_prompt | render_user_prompt concept continue-prompt)"
            resume_input="$continue_prompt"
        else
            log_warn "concept: RESUME_SESSION_ID=$RESUME_SESSION_ID has no session file — starting fresh session"
        fi
    fi

    log_info "concept: calling agent (stream-json, max-turns $max_turns${resume_args[*]:+, resume})"

    # Capture the CLI's stderr so the auth-error heuristic + manager-side
    # error_log promotion can read it after the run.
    export AGENT_STDERR_LOG="$stderr_log"
    : > "$stderr_log"

    set +e
    agent_run \
        --system-prompt-file "$sysprompt" \
        --user-prompt-file "$resume_input" \
        --max-turns "$max_turns" \
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

    # The CLI may exit non-zero even after emitting a clean `result` event
    # (e.g. error_max_turns surfaces as both is_error=true AND exit 1).
    # Inspect the result_json before treating the exit code as fatal so a
    # max-turns hit lands as Paused (resumable) rather than Failed.
    local result_subtype="" result_is_error="false"
    if [[ -s "$result_json" ]]; then
        result_subtype="$(jq -r '.subtype // ""' "$result_json" 2>/dev/null || echo "")"
        result_is_error="$(jq -r '.is_error // false' "$result_json" 2>/dev/null || echo true)"
    fi

    if [[ "$result_subtype" == "error_max_turns" ]]; then
        echo "concept: max-turns reached — pausing (resume to continue)" >&2
        return "$EXIT_MAX_TURNS"
    fi

    if (( agent_exit != 0 )); then
        echo "concept: agent call failed (exit $agent_exit)" >&2
        if agent_check_usage_limit "$stream_log"; then
            echo "  → usage/rate limit — backing off" >&2
            return "$EXIT_USAGE_LIMIT"
        fi
        # The CLI may have died with a 401 before emitting a result event;
        # the auth message went to stderr (captured in stderr_log), not to
        # the stream. Surface a precise hint and exit as auth failure.
        if agent_check_auth_error_log "$stderr_log"; then
            echo "  → Agent-Token ungültig oder abgelaufen — Worker kann sich nicht authentifizieren." >&2
            echo "    Token in den Agent-Credentials erneuern (claude setup-token / ~/.codex/auth.json) und neu starten." >&2
            return "$EXIT_AUTH"
        fi
        return 3
    fi

    if [[ "$result_is_error" != "false" ]]; then
        local err_msg
        err_msg="$(jq -r '.result // "(no result field)"' "$result_json" 2>/dev/null)"
        echo "concept: agent returned is_error=true: $err_msg" >&2
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

    local concept_text
    concept_text="$(jq -r '.result' "$result_json")"
    if [[ -z "$concept_text" || "$concept_text" == "null" ]]; then
        echo "concept: agent returned empty .result" >&2
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
