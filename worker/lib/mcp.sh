#!/usr/bin/env bash
# lib/mcp.sh — helpers for configuring the target project's MCP server.
#
# When ARGOS_MCP_ENABLED=true and the target project's boost.json has "mcp": true,
# mcp_setup writes a laravel-boost stdio entry into the Claude settings file so that
# the Claude CLI starts the project's MCP server as a local subprocess.
# No network access required — everything runs inside the worker container.
#
# shellcheck shell=bash

# mcp_setup: configure the target project's laravel-boost MCP server for Claude.
# Reads ${MCP_WORKSPACE_DIR:-/workspace}/boost.json to decide whether MCP is
# requested by the project. Writes the stdio server entry into
# ${CLAUDE_CONFIG_DIR}/settings.json.
# No-op when ARGOS_MCP_ENABLED != "true" or boost.json is absent/mcp=false.
# Args: none
# Returns: 0 always
mcp_setup() {
    if [[ "${ARGOS_MCP_ENABLED:-}" != "true" ]]; then
        return 0
    fi

    local workspace_dir="${MCP_WORKSPACE_DIR:-/workspace}"
    local boost_json="${workspace_dir}/boost.json"
    if [[ ! -f "$boost_json" ]]; then
        log_info "mcp: ARGOS_MCP_ENABLED=true but boost.json not found — skipping"
        return 0
    fi

    local mcp_enabled
    mcp_enabled="$(jq -r '.mcp // false' "$boost_json" 2>/dev/null || echo false)"
    if [[ "$mcp_enabled" != "true" ]]; then
        log_info "mcp: boost.json mcp=false — skipping"
        return 0
    fi

    local config_dir="${CLAUDE_CONFIG_DIR:-/workspace/.agent/claude-state}"
    mkdir -p "$config_dir"

    local settings_file="${config_dir}/settings.json"
    local mcp_entry
    mcp_entry='{"type":"stdio","command":"php","args":["artisan","boost:mcp"],"cwd":"/workspace"}'

    if [[ -f "$settings_file" ]]; then
        jq --argjson entry "$mcp_entry" \
             '.mcpServers["laravel-boost"] = $entry' \
             "$settings_file" \
        > "${settings_file}.tmp"
    else
        jq -n --argjson entry "$mcp_entry" \
             '{mcpServers: {"laravel-boost": $entry}}' \
        > "${settings_file}.tmp"
    fi
    mv "${settings_file}.tmp" "$settings_file"

    log_info "mcp: configured laravel-boost MCP server (stdio, boost:mcp in /workspace)"
}
