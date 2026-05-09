#!/usr/bin/env bash
# lib/agents/codex.sh — OpenAI Codex CLI runner (worker side).
#
# Invokes `codex exec --json` and translates the trailing event into
# the stream-json shape phase scripts expect (single result-event with
# is_error / result / session_id / total_cost_usd / usage). Codex emits
# its own newline-delimited event stream — we pass intermediate events
# through as-is and synthesise the final result-event from the run's
# exit status + stdout aggregation.
#
# Codex doesn't have a separate --append-system-prompt flag (as of
# 2026-Q2), so we prepend the system prompt to the user prompt with
# a markdown fence; if Codex grows a flag later, swap it in here.
#
# Env mapping:
#   AGENT_TOKEN  → OPENAI_API_KEY (only if not already set)
#
# shellcheck shell=bash

# agent_codex_run: Invoke `codex exec --json` with the supplied prompts.
# Args (named):
#   --system-prompt-file PATH      required
#   --user-prompt-file PATH        required (fed via stdin after system prepend)
#   --max-turns N                  required — passed through as -c approval/turns
#   --model NAME                   optional
#   --output-format stream-json|json   default: stream-json
#   --verbose / --no-verbose       no-op for codex (already verbose to stderr)
#   --include-partial              no-op (codex always emits intermediate events)
#   --resume SESSION_ID            optional: --resume flag for codex
#   --json-schema PATH             optional: --output-schema for structured output
# stdout: stream-json events. The intermediate events are codex's own
#         shape (passed through); the final event has type=result with
#         the field set phase scripts read.
# Returns: codex exit code (0 OK; non-zero handled by the phase script)
agent_codex_run() {
    local sysprompt_file="" user_prompt_file=""
    local max_turns="" model="" resume_session=""
    local output_format="stream-json"
    local include_partial=false
    local verbose=true
    local json_schema_file=""

    while (( $# > 0 )); do
        case "$1" in
            --system-prompt-file) sysprompt_file="$2"; shift 2 ;;
            --user-prompt-file)   user_prompt_file="$2"; shift 2 ;;
            --max-turns)          max_turns="$2"; shift 2 ;;
            --model)              model="$2"; shift 2 ;;
            --output-format)      output_format="$2"; shift 2 ;;
            --verbose)            verbose=true; shift ;;
            --no-verbose)         verbose=false; shift ;;
            --include-partial)    include_partial=true; shift ;;
            --resume)             resume_session="$2"; shift 2 ;;
            --json-schema)        json_schema_file="$2"; shift 2 ;;
            *)
                echo "agent_codex_run: unknown arg '$1'" >&2
                return 1
                ;;
        esac
    done

    if [[ -z "$sysprompt_file" || -z "$user_prompt_file" || -z "$max_turns" ]]; then
        echo "agent_codex_run: --system-prompt-file, --user-prompt-file and --max-turns are required" >&2
        return 1
    fi
    if [[ ! -f "$sysprompt_file" ]]; then
        echo "agent_codex_run: system-prompt file not found: $sysprompt_file" >&2
        return 1
    fi
    if [[ ! -f "$user_prompt_file" ]]; then
        echo "agent_codex_run: user-prompt file not found: $user_prompt_file" >&2
        return 1
    fi
    # silence "set but never used"
    : "$verbose" "$include_partial"

    # Map AGENT_TOKEN onto codex's expected env var.
    if [[ -n "${AGENT_TOKEN:-}" && -z "${OPENAI_API_KEY:-}" ]]; then
        export OPENAI_API_KEY="$AGENT_TOKEN"
    fi

    # System + user prompt concatenated. If codex grows --system-prompt
    # later, replace this with a flag-based pass-through.
    local combined_prompt_file
    combined_prompt_file="$(mktemp /tmp/argos-codex-XXXXXX.txt)"
    {
        echo "# System Instructions"
        echo
        cat "$sysprompt_file"
        echo
        echo "---"
        echo
        cat "$user_prompt_file"
    } > "$combined_prompt_file"

    # Default to --skip-git-repo-check: codex refuses to run outside a
    # git-tracked directory otherwise, and the worker's /workspace
    # only becomes a git repo *during* the concept phase (after clone).
    #
    # --dangerously-bypass-approvals-and-sandbox: codex defaults to a
    # read-only sandbox in `exec` mode plus an approval-on-write step
    # that has no human in the loop here, so every patch ends up
    # rejected. The flag is explicitly meant for "environments that
    # are externally sandboxed" — the worker container is exactly that
    # (Docker isolates /workspace; codex' bwrap layer is redundant and
    # also can't acquire user namespaces in unprivileged containers).
    # Same trust model we already extend to Claude Code.
    local args=(exec --json --skip-git-repo-check --dangerously-bypass-approvals-and-sandbox)
    [[ -n "$model" ]] && args+=(--model "$model")
    [[ -n "$resume_session" ]] && args+=(--resume "$resume_session")
    if [[ -n "$json_schema_file" ]]; then
        if [[ ! -f "$json_schema_file" ]]; then
            echo "agent_codex_run: json-schema file not found: $json_schema_file" >&2
            rm -f "$combined_prompt_file"
            return 1
        fi
        args+=(--output-schema "$json_schema_file")
    fi

    # MCP: when the target project opted in via boost.json + ARGOS_MCP_ENABLED,
    # splice `-c mcp_servers.<name>.<key>=<toml-value>` overrides into the
    # codex args. Codex parses each value as TOML, so the values from
    # mcp_codex_config_args are pre-quoted accordingly.
    if mcp_should_enable; then
        local mcp_args=()
        mapfile -t mcp_args < <(mcp_codex_config_args)
        args+=("${mcp_args[@]}")
        log_info "mcp: codex -c mcp_servers.laravel-boost.* (stdio php artisan boost:mcp)"
    fi

    args+=(-)   # read prompt from stdin

    # Capture codex's raw output to a temp file so we can synthesise
    # the result-event after the run completes. The phase script's
    # expectation depends on output-format:
    #   stream-json: stream every event through stdout AND keep them in
    #                raw_out (used by concept/implement to follow tool
    #                calls live).
    #   json:        only the single synthesized result-event reaches
    #                stdout (claude's `--output-format json` semantics
    #                — `jq` against stdout reads exactly one object).
    local raw_out
    raw_out="$(mktemp /tmp/argos-codex-out-XXXXXX.jsonl)"

    set +e
    if [[ "$output_format" == "stream-json" ]]; then
        ( unset REPO_TOKEN
          codex "${args[@]}" < "$combined_prompt_file"
        ) | tee "$raw_out"
        local rc=${PIPESTATUS[0]}
    else
        ( unset REPO_TOKEN
          codex "${args[@]}" < "$combined_prompt_file"
        ) > "$raw_out"
        local rc=$?
    fi
    set -e

    # Emit a synthetic result-event so the phase scripts can read
    # is_error / result / session_id like they do for claude.
    _agent_codex_emit_result_event "$raw_out" "$rc" "$output_format"

    rm -f "$combined_prompt_file" "$raw_out"
    return "$rc"
}

# _agent_codex_emit_result_event: collapse the raw codex output into a
# single trailing result-event matching the claude stream-json shape.
# Args: $1=raw output file, $2=codex exit code, $3=output_format
_agent_codex_emit_result_event() {
    local raw="$1" rc="$2" fmt="$3"

    local final_text=""
    local session_id=""
    local input_tokens=0 output_tokens=0 total_cost=0
    local is_error="false"

    if [[ "$rc" != "0" ]]; then
        is_error="true"
    fi

    if [[ -s "$raw" ]]; then
        # Codex (v0.129) event shapes — verified against a real run:
        #   {type:"thread.started",  thread_id:"…"}
        #   {type:"item.completed",  item:{type:"agent_message", text:"…"}}
        #   {type:"turn.completed",  usage:{input_tokens, output_tokens, …}}
        # The transformer is tolerant: each query falls back to legacy
        # field names too, so an upstream rename only causes blanks
        # rather than a failed phase.
        final_text="$(jq -rs '
            map(select(type == "object" and .type == "item.completed" and .item.type == "agent_message"))
            | map(.item.text // "")
            | join("\n")
        ' "$raw" 2>/dev/null || true)"
        if [[ -z "$final_text" ]]; then
            final_text="$(jq -rs '
                map(select(type == "object"))
                | (map(.message // .text // .delta // empty) | add // "")
                | tostring
            ' "$raw" 2>/dev/null || true)"
        fi
        session_id="$(jq -rs '
            map(select(type == "object"))
            | (map(.thread_id // .session_id // .conversation_id // empty) | first // "")
        ' "$raw" 2>/dev/null || true)"
        input_tokens="$(jq -rs '
            map(select(type == "object"))
            | (map(.usage.input_tokens // .input_tokens // empty) | last // 0)
        ' "$raw" 2>/dev/null || echo 0)"
        output_tokens="$(jq -rs '
            map(select(type == "object"))
            | (map(.usage.output_tokens // .output_tokens // empty) | last // 0)
        ' "$raw" 2>/dev/null || echo 0)"
    fi

    if [[ -z "$final_text" && "$is_error" == "true" ]]; then
        final_text="codex exec exited with code $rc"
    fi

    local event
    event="$(jq -nc \
        --arg result "$final_text" \
        --arg session_id "$session_id" \
        --argjson is_error "$is_error" \
        --argjson input_tokens "${input_tokens:-0}" \
        --argjson output_tokens "${output_tokens:-0}" \
        --argjson total_cost "${total_cost:-0}" \
        '{
            type: "result",
            is_error: $is_error,
            result: $result,
            session_id: $session_id,
            total_cost_usd: $total_cost,
            usage: { input_tokens: $input_tokens, output_tokens: $output_tokens }
        }')"

    if [[ "$fmt" == "stream-json" ]]; then
        printf '%s\n' "$event"
    else
        # json (single-object) format — phase script reads it directly
        printf '%s\n' "$event"
    fi
}

# agent_codex_check: probe whether the codex CLI is on PATH.
agent_codex_check() {
    command -v codex >/dev/null
}

# agent_codex_check_usage_limit: detect rate-limit signals in output.
# Args: $1=stream_log_path (optional), $2=err_message (optional)
agent_codex_check_usage_limit() {
    local log_file="${1:-}"
    local err_msg="${2:-}"

    local needle="rate.?limit|usage.?limit|quota.?exceeded|429|too.?many.?request"

    if [[ -n "$err_msg" ]] && echo "$err_msg" | grep -qiE "$needle"; then
        return 0
    fi
    if [[ -n "$log_file" && -f "$log_file" ]] && grep -qiE "$needle" "$log_file"; then
        return 0
    fi

    return 1
}

# agent_codex_check_auth_error: heuristic auth-error detection.
agent_codex_check_auth_error() {
    local msg="${1:-}"
    [[ -z "$msg" ]] && return 1
    echo "$msg" | grep -qiE "invalid api key|authentication|oauth|unauthorized|401|sign[ -]?in"
}
