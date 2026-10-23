#!/usr/bin/env bash
# workspace-entrypoint.sh — runs the workspace daemon and OpenVSCode Server
# side by side. If either exits, the container exits so Docker's restart
# policy brings the pair back together.
set -euo pipefail
IFS=$'\n\t'

NODE_BIN="/opt/argos/node/bin/node"
CLAUDE_BIN="${ARGOS_CLAUDE_BIN:-$(command -v claude || true)}"
export ARGOS_CLAUDE_BIN="$CLAUDE_BIN"

"$NODE_BIN" /opt/argos/daemon/dist/main.js &
daemon_pid=$!

# The editor listens on loopback only; the daemon's editor gate is its door to
# the network and lets through only requests Traefik's code route vouches for
# (other workspaces share the Docker network).
/opt/openvscode-server/bin/openvscode-server \
    --host 127.0.0.1 \
    --port "${ARGOS_EDITOR_INTERNAL_PORT:-3001}" \
    --without-connection-token \
    --default-folder /workspace/repo \
    --telemetry-level off &
editor_pid=$!

terminate() {
    kill -TERM "$daemon_pid" "$editor_pid" 2>/dev/null || true
    wait "$daemon_pid" "$editor_pid" 2>/dev/null || true
}
trap terminate TERM INT

set +e
wait -n "$daemon_pid" "$editor_pid"
status=$?
set -e
echo "workspace-entrypoint: a process exited (status ${status}); stopping the workspace" >&2
terminate
exit "$status"
