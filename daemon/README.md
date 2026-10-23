# Argos workspace daemon

Runs inside a workspace container. It keeps one interactive Claude session per
Argos thread alive (Claude Agent SDK, streaming-input mode), relays tool
permission requests to humans, and reports normalized events to the Argos
manager. The browser never talks to the daemon directly.

## Environment

| Variable | Purpose |
|---|---|
| `ARGOS_DAEMON_TOKEN` | Bearer token for inbound commands and outbound events |
| `ARGOS_EVENTS_URL` | Manager endpoint for event batches (`…/internal/workspaces/<id>/events`) |
| `ARGOS_REPO_CREDENTIAL_URL` | Manager endpoint handing out the repository credential |
| `ARGOS_DAEMON_PORT` | Command API port (default `7777`) |
| `ARGOS_CREDENTIAL_PORT` | Loopback port for the git credential helper (default `7778`) |
| `ARGOS_WORKSPACE_DIR` | Repository checkout (default `/workspace`) |
| `ARGOS_AGENTS_DIR` | Per-agent `CLAUDE_CONFIG_DIR` root (default `/home/agent/.claude-agents`) |
| `ARGOS_CLAUDE_BIN` | Path to the `claude` binary (default: resolved by the SDK) |
| `ARGOS_CREDENTIAL_HELPER` | Path git uses as credential helper (default `/opt/argos/bin/git-credential-argos`) |
| `ARGOS_GATES_SCRIPT` | Quality-gate runner (default `/opt/argos/bin/run-gates.sh`) |

The Claude OAuth token is **not** part of the environment: the manager sends it
per thread in `POST /threads/{id}/start`, and only the `claude` child process
receives it.

## Command API

All routes require `Authorization: Bearer $ARGOS_DAEMON_TOKEN`.

| Route | Purpose |
|---|---|
| `GET /health` | Versions and running threads |
| `POST /workspace/init` | Clone the repository and check out the workspace branch (idempotent) |
| `POST /threads/{id}/start` | Open a session (`agent_id`, `oauth_token`, `model`, `permission_mode`, `resume_session_id?`, `seq_offset`, `append_system_prompt`) |
| `POST /threads/{id}/messages` | Queue a user message (`client_msg_id`, `author`, `text`, `images?`) |
| `POST /threads/{id}/interrupt` | Interrupt the running turn; open approvals are denied |
| `POST /threads/{id}/approvals/{approvalId}` | Answer a permission request (`decision`, `updated_input?`, `message?`) |
| `POST /threads/{id}/permission-mode` | Switch permission mode |
| `POST /threads/{id}/model` | Switch model |
| `POST /threads/{id}/stop` | Close the session |
| `GET /files` | Fuzzy file search for `@` mentions (`q`, `limit`) |
| `GET /git/status` | Branch, upstream, ahead/behind and changed files |
| `GET /git/diff` | Diff of the workspace against `base` (committed, uncommitted and untracked) |
| `POST /git/fetch` | `git fetch --prune origin` |
| `POST /git/commit` | Commit everything (`subject`, `body?`, `author`) |
| `POST /git/push` | Push `HEAD` to `branch` |
| `POST /checkpoints` | Snapshot the working tree for a thread (`thread_id`, `label?`) |
| `GET /checkpoints/diff` | What restoring `ref` would undo |
| `POST /checkpoints/restore` | Reset the working tree to `ref` (refused while any thread is working) |
| `GET /gates` | Running gate run and baseline state |
| `POST /gates/run` | Start a quality-gate run (`run_id`); results arrive as events |
| `GET /gates/fix-prompt` | Fix prompt for a failed gate (`gate`, `run_id`) |
| `POST /oneshot` | One tool-less prompt (`agent_id`, `oauth_token`, `model`, `system_prompt`, `user_prompt`, `json_schema?`) |

Errors answer `{error: {code, message}}`: `validation_failed` (422), unknown
thread/approval/checkpoint (404), conflicts such as `thread_closed`,
`thread_running`, `gates_running`, `no_changes` or a push failure (409),
workspace-init failures (422), failing git commands or gate scripts and
`oneshot_failed` (502), and unconfigured gates/oneshot (501).

## Events

Batched `POST $ARGOS_EVENTS_URL` with `{batch_id, events: [{thread_id, seq, ts, type, data}]}`.
`seq` is monotonic per thread; the manager answers `{acks: {<thread_id>: <seq>}}`
(workspace-level events, `thread_id = null`, are acked under `_workspace`)
and unacknowledged events are re-sent. Thread types: `session.started`,
`commands.updated`, `status`, `user.accepted`, `assistant.delta`,
`thinking.delta`, `assistant.message`, `thinking`, `tool.started`,
`tool.result`, `approval.requested`, `approval.cancelled`, `turn.completed`,
`usage_limit`, `error`, `checkpoint.created`. Workspace types:
`gates.started`, `gates.completed`, `gates.failed`, `gates.baseline`.

## Development

```bash
npm ci
npm run check   # typecheck + lint + tests
npm run build   # dist/
```

Live test against a real subscription (optional):

```bash
ARGOS_LIVE_CLAUDE_TOKEN=… ARGOS_LIVE_REPO_DIR=/path/to/scratch-repo npx vitest run test/live.test.ts
```
