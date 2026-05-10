#!/usr/bin/env bash
# lib/mcp.sh — helpers for wiring the target project's MCP server into the
# active agent CLI before each session.
#
# Activation gate (mcp_should_enable):
#   ${MCP_WORKSPACE_DIR:-/workspace}/boost.json contains "mcp": true.
# The target repo decides — if it ships a boost.json with mcp:true, the
# agent runners (lib/agents/*.sh) emit the correct CLI flags so the agent
# spawns `php artisan boost:mcp` as a local stdio subprocess inside the
# worker container. No network access required.
#
# shellcheck shell=bash

# mcp_should_enable: check if the target project's MCP server should be
# activated for this run.
# Args: none. Reads ${MCP_WORKSPACE_DIR:-/workspace}/boost.json.
# Returns: 0 if MCP should be enabled, 1 otherwise.
mcp_should_enable() {
    local boost_json="${MCP_WORKSPACE_DIR:-/workspace}/boost.json"
    if [[ ! -f "$boost_json" ]]; then
        return 1
    fi

    local mcp_enabled
    mcp_enabled="$(jq -r '.mcp // false' "$boost_json" 2>/dev/null || echo false)"
    [[ "$mcp_enabled" == "true" ]]
}

# mcp_write_claude_config: write a JSON config for `claude --mcp-config <file>`
# describing the laravel-boost stdio server.
#
# APP_ENV=local: BoostServiceProvider gates registration on
# `app()->environment('local') || config('app.debug')`. The cloned target
# app boots without that flag, so the subprocess would otherwise die with
# "no commands defined in the boost namespace". Forcing it on the
# subprocess is scoped to the MCP child PHP — it does not affect the
# agent's own artisan calls in the same workspace.
# Args: $1=destination_path
# Returns: 0 on success, non-zero on bad input or jq failure.
mcp_write_claude_config() {
    local dest="$1"
    if [[ -z "$dest" ]]; then
        echo "mcp_write_claude_config: destination path required" >&2
        return 2
    fi
    mkdir -p "$(dirname "$dest")"
    jq -n '{
        mcpServers: {
            "laravel-boost": {
                type: "stdio",
                command: "php",
                args: ["artisan", "boost:mcp"],
                cwd: "/workspace",
                env: {APP_ENV: "local"}
            }
        }
    }' > "$dest"
}

# mcp_codex_config_args: emit `-c key=value` tokens for the codex CLI,
# one token per line. Consumers should read with
# `mapfile -t arr < <(mcp_codex_config_args)` and splice into their args.
# Codex's MCP config lives in $CODEX_HOME/config.toml under [mcp_servers.<name>];
# `-c` overrides parse the value as TOML, so arrays/strings need TOML quoting.
# env={ APP_ENV = "local" }: see mcp_write_claude_config for rationale.
# Args: none.
mcp_codex_config_args() {
    printf '%s\n' '-c'
    printf '%s\n' 'mcp_servers.laravel-boost.command="php"'
    printf '%s\n' '-c'
    printf '%s\n' 'mcp_servers.laravel-boost.args=["artisan", "boost:mcp"]'
    printf '%s\n' '-c'
    printf '%s\n' 'mcp_servers.laravel-boost.env={ APP_ENV = "local" }'
}
