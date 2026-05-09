#!/usr/bin/env bash
# agents/install-claude-code.sh — install Anthropic's Claude Code CLI into the worker image.
#
# Invoked as a build step in Dockerfile.compose. The version is read from
# the AGENT_VERSION build-arg if set, otherwise pinned via the npm tag.
# Stack must already provide node + npm (capability `node`).

set -euo pipefail
IFS=$'\n\t'

VERSION="${AGENT_VERSION:-latest}"

if [[ "$VERSION" == "latest" ]]; then
    npm install -g "@anthropic-ai/claude-code"
else
    npm install -g "@anthropic-ai/claude-code@${VERSION}"
fi

# Smoke test: claude --version should return non-empty.
if ! claude --version >/dev/null 2>&1; then
    echo "install-claude-code: claude --version failed after install" >&2
    exit 1
fi
