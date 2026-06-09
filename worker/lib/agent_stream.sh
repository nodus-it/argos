# shellcheck shell=bash
# lib/agent_stream.sh — fan-out for the agent's stream-json output.
#
# The agent CLI emits one JSON event per line on stdout (assistant text,
# thinking, tool_use, tool_result, result, …). We need that stream in three
# places at once:
#   1. a persistent per-iteration log file (so the manager can sync the full
#      transcript into the DB after the phase),
#   2. the worker's stderr, which the manager captures live into the task's
#      .bg.log and renders as the CLI-near live view (AgentStreamParser),
#   3. the caller's stdout, unchanged, so it can still extract the `result`
#      event downstream.
#
# Input is expected to be already token-scrubbed (pipe through log_scrub
# first). This lib is source-only — it must not set `set -euo pipefail`.

# agent_stream_tee: Mirror the agent stream-json to a log file and to stderr,
# passing it through on stdout unchanged.
#
# Replaces the old per-phase `jq -rj` projection: instead of a lossy text
# rendering (text + truncated tool name only), the full JSON reaches the
# live view, where AgentStreamParser separates argos/thinking/text/tool_use/
# tool_result by event type.
#
# Args: =stream_log  path of the file the full stream is persisted to
# Returns: 0; forwards stdin to stdout unchanged
agent_stream_tee() {
    local stream_log="${1:?agent_stream_tee: stream_log path required}"
    tee "$stream_log" | tee /dev/stderr
}
