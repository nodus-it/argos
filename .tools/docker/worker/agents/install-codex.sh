#!/usr/bin/env bash
# agents/install-codex.sh — install OpenAI's Codex CLI into the worker image.
#
# Invoked as a build step in Dockerfile.compose. Stack must already
# provide node + npm (capability `node`). Version is read from the
# AGENT_VERSION build-arg if set, otherwise the latest tag is pulled.

set -euo pipefail
IFS=$'\n\t'

VERSION="${AGENT_VERSION:-latest}"

if [[ "$VERSION" == "latest" ]]; then
    npm install -g "@openai/codex"
else
    npm install -g "@openai/codex@${VERSION}"
fi

if ! codex --version >/dev/null 2>&1; then
    echo "install-codex: codex --version failed after install" >&2
    exit 1
fi
