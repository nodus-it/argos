#!/usr/bin/env bash
# lib/agent.sh — agent-runner dispatcher.
#
# Phase scripts call agent_run / agent_check / agent_check_usage_limit
# instead of invoking the underlying CLI (claude / codex / …) directly.
# This indirection keeps phase logic agent-agnostic; the concrete runner
# lives in lib/agents/<name>.sh and is selected via $AGENT_NAME.
#
# Contract (env, set by the worker entrypoint):
#   AGENT_NAME       slug, e.g. claude_code (default: claude_code)
#   AGENT_TOKEN      auth token, mapped onto the agent's native env var
#   AGENT_CONFIG     JSON with agent-specific settings (optional)
#
# shellcheck shell=bash

# agent_run: Execute one agent call for a phase.
# Args (named):
#   --system-prompt-file PATH   path to system-prompt file (required)
#   --user-prompt-file PATH     path to user-prompt file, fed to the CLI on stdin (required)
#   --max-turns N               turn limit for the agent (required)
#   --model NAME                optional model override
#   --include-partial           pass through to agent if it supports it (claude: --include-partial-messages)
#   --resume SESSION_ID         resume an existing session (claude only for now)
# stdout: raw streaming output of the agent (today: claude stream-json)
# Returns: exit code from the underlying CLI
agent_run() {
    local agent="${AGENT_NAME:-claude_code}"
    case "$agent" in
        claude_code)
            agent_claude_code_run "$@"
            ;;
        *)
            echo "agent: unknown AGENT_NAME='$agent' — no runner registered" >&2
            return 30
            ;;
    esac
}

# agent_check: Quick availability probe — is the configured agent CLI present?
# Returns: 0 if reachable, non-zero otherwise.
agent_check() {
    local agent="${AGENT_NAME:-claude_code}"
    case "$agent" in
        claude_code) agent_claude_code_check ;;
        *)
            echo "agent: unknown AGENT_NAME='$agent'" >&2
            return 30
            ;;
    esac
}

# agent_check_usage_limit: Detect agent-specific usage/rate-limit signal.
# Args: $1=stream_log_path (optional), $2=err_message (optional)
# Returns: 0 if usage limit detected, 1 otherwise.
# Side effect: writes ${RUNTIME_DIR}/usage_limit.env on detection (delegated to the agent runner).
agent_check_usage_limit() {
    local agent="${AGENT_NAME:-claude_code}"
    case "$agent" in
        claude_code) agent_claude_code_check_usage_limit "$@" ;;
        *) return 1 ;;
    esac
}

# agent_check_auth_error: Decide whether an error message indicates an auth issue.
# Args: $1=err_message
# Returns: 0 if auth error, 1 otherwise.
agent_check_auth_error() {
    local agent="${AGENT_NAME:-claude_code}"
    case "$agent" in
        claude_code) agent_claude_code_check_auth_error "$@" ;;
        *) return 1 ;;
    esac
}
