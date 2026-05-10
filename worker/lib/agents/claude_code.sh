#!/usr/bin/env bash
# lib/agents/claude_code.sh — Claude Code runner.
#
# Maps the generic agent-runner contract (see lib/agent.sh) onto
# Anthropic's `claude` CLI. All call sites in worker/phases/ go through
# agent_run, which dispatches here when AGENT_NAME=claude_code.
#
# Depends on:
#   - lib/claude.sh   (claude_check_usage_limit — usage-limit detection)
#   - the `claude` CLI being on PATH inside the worker image
#
# Env mapping:
#   AGENT_TOKEN             → CLAUDE_CODE_OAUTH_TOKEN (only if not already set)
#   AGENT_CONFIG.config_dir → CLAUDE_CONFIG_DIR       (only if not already set)
#   CLAUDE_MODEL            takes precedence over --model arg (preserves prior behaviour)
#
# shellcheck shell=bash

# agent_claude_code_run: Invoke `claude -p` with the supplied prompts.
# Args (named, all optional unless noted):
#   --system-prompt-file PATH      required: file whose contents go to --append-system-prompt
#   --user-prompt-file PATH        required: file fed to claude on stdin
#   --max-turns N                  required: turn limit
#   --model NAME                   optional: --model override (defeated by CLAUDE_MODEL env)
#   --output-format stream-json|json   default: stream-json
#   --verbose                      flag, default: on for stream-json output
#   --include-partial              flag, passes through as --include-partial-messages
#   --resume SESSION_ID            optional: resume a prior session
#   --json-schema PATH             optional: enforce a JSON schema on the response (json output only)
# stdout: raw output from claude (stream-json events or single json object)
# Returns: exit code from claude
agent_claude_code_run() {
    local sysprompt_file="" user_prompt_file=""
    local max_turns="" model="" resume_session=""
    local output_format="stream-json"
    local verbose=true
    local include_partial=false
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
                echo "agent_claude_code_run: unknown arg '$1'" >&2
                return 1
                ;;
        esac
    done

    if [[ -z "$sysprompt_file" || -z "$user_prompt_file" || -z "$max_turns" ]]; then
        echo "agent_claude_code_run: --system-prompt-file, --user-prompt-file and --max-turns are required" >&2
        return 1
    fi
    if [[ ! -f "$sysprompt_file" ]]; then
        echo "agent_claude_code_run: system-prompt file not found: $sysprompt_file" >&2
        return 1
    fi
    if [[ ! -f "$user_prompt_file" ]]; then
        echo "agent_claude_code_run: user-prompt file not found: $user_prompt_file" >&2
        return 1
    fi

    # Map AGENT_TOKEN onto the native Claude env var so the CLI picks it up
    # transparently. We don't overwrite an existing CLAUDE_CODE_OAUTH_TOKEN —
    # legacy callers still set it directly.
    if [[ -n "${AGENT_TOKEN:-}" && -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]]; then
        export CLAUDE_CODE_OAUTH_TOKEN="$AGENT_TOKEN"
    fi

    # Optional config_dir override via AGENT_CONFIG; respects an explicit CLAUDE_CONFIG_DIR.
    if [[ -n "${AGENT_CONFIG:-}" && -z "${CLAUDE_CONFIG_DIR:-}" ]]; then
        local cfg_dir
        cfg_dir="$(printf '%s' "$AGENT_CONFIG" | jq -r '.config_dir // ""' 2>/dev/null || echo "")"
        if [[ -n "$cfg_dir" ]]; then
            export CLAUDE_CONFIG_DIR="$cfg_dir"
        fi
    fi

    # Phase-supplied --model wins; CLAUDE_MODEL is a fallback only when no
    # explicit model was passed. Pre-refactor commit-message hardcoded
    # the model, so the explicit arg must take precedence.
    local effective_model="${model:-${CLAUDE_MODEL:-}}"

    local sysprompt_content
    sysprompt_content="$(cat "$sysprompt_file")"

    local args=(-p)
    [[ -n "$effective_model" ]] && args+=(--model "$effective_model")
    args+=(--append-system-prompt "$sysprompt_content")
    args+=(--output-format "$output_format")
    if [[ "$output_format" == "stream-json" && "$verbose" == "true" ]]; then
        args+=(--verbose)
    fi
    if [[ "$include_partial" == "true" ]]; then
        args+=(--include-partial-messages)
    fi
    if [[ -n "$json_schema_file" ]]; then
        if [[ ! -f "$json_schema_file" ]]; then
            echo "agent_claude_code_run: json-schema file not found: $json_schema_file" >&2
            return 1
        fi
        local schema_content
        schema_content="$(cat "$json_schema_file")"
        args+=(--json-schema "$schema_content")
    fi
    args+=(--max-turns "$max_turns")
    args+=(--permission-mode bypassPermissions)
    if [[ -n "$resume_session" ]]; then
        args+=(--resume "$resume_session")
    fi

    # MCP: when the target project opted in via boost.json (mcp: true),
    # write a per-session config and hand it to claude via --mcp-config. The
    # CLI does NOT read mcpServers from settings.json — only from .mcp.json,
    # ~/.claude.json, or this flag.
    if mcp_should_enable; then
        local mcp_config_dir="${CLAUDE_CONFIG_DIR:-/workspace/.agent/claude-state}"
        local mcp_config_file="${mcp_config_dir}/mcp-laravel-boost.json"
        if mcp_write_claude_config "$mcp_config_file"; then
            args+=(--mcp-config "$mcp_config_file")
            log_info "mcp: claude --mcp-config $mcp_config_file (laravel-boost stdio)"
        else
            log_warn "mcp: failed to write $mcp_config_file — running without MCP"
        fi
    fi

    # REPO_TOKEN is in the env for git, but it must never leak into the
    # claude process — the CLI logs its env at debug levels. Sub-shell + unset
    # ensures the var is gone in the child.
    ( unset REPO_TOKEN
      claude "${args[@]}" < "$user_prompt_file"
    )
}

# agent_claude_code_check: probe whether the claude CLI is reachable.
# Returns: 0 if `claude` is on PATH, non-zero otherwise.
agent_claude_code_check() {
    command -v claude >/dev/null
}

# agent_claude_code_check_usage_limit: thin wrapper around claude_check_usage_limit.
# Args: $1=stream_log_path (optional), $2=err_message (optional)
# Returns: 0 if usage limit detected, 1 otherwise.
agent_claude_code_check_usage_limit() {
    claude_check_usage_limit "$@"
}

# agent_claude_code_check_auth_error: heuristic auth-error detection on a message.
# Args: $1=err_message
# Returns: 0 if the message looks like an auth/oauth/401/token issue, 1 otherwise.
agent_claude_code_check_auth_error() {
    local msg="${1:-}"
    [[ -z "$msg" ]] && return 1
    echo "$msg" | grep -qiE "invalid api key|authentication|oauth|unauthorized|401|token.*expired|invalid_api_key"
}
