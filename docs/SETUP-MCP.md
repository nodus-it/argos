# MCP Server

Argos ships a built-in [MCP](https://modelcontextprotocol.io) server so an
external MCP client — in particular **Claude Code** — can drive Argos directly:
list projects and tasks, hand over a plan as a new task, run the
Concept / Implement / Push phases, and pull the resulting feature branch
locally.

This turns the usual loop ("plan in Claude Code → copy into the Argos UI →
watch the browser → copy the branch name") into a single in-session flow,
without leaving your planning client.

If you'd rather automate Argos from scripts or CI than from an interactive MCP
client, the same workflow is also exposed as a plain HTTP API — see
[REST-API.md](REST-API.md). The two surfaces are equivalent; MCP is the
chat-client front door, the REST API is the scripting front door.

## Contents

- [How it works](#how-it-works)
- [Prerequisites](#prerequisites)
- [Connect an MCP client](#connect-an-mcp-client)
- [Available tools](#available-tools)
- [Typical flow](#typical-flow)
- [Security](#security)
- [Troubleshooting](#troubleshooting)

## How it works

- The server is mounted at **`${APP_URL}/mcp`** and is part of the `app`
  service — no extra container. It is built on `laravel/mcp` and speaks the
  streamable-HTTP transport.
- Auth is **OAuth 2.1** via Laravel Passport. Every request must carry an
  access token with the **`mcp:use`** scope; the `/mcp` route is gated by
  `auth:api` plus a `mcp:use` scope check, so a token without that scope is
  rejected even if it is otherwise valid.
- Clients register themselves through OAuth Dynamic Client Registration
  (`/oauth/register`); discovery metadata lives under the
  `/.well-known/oauth-*` endpoints. You do not pre-register a client by hand.
- The first connect opens a **browser login** as an Argos user plus a consent
  screen for the `mcp:use` scope. Argos is single-user today, so this is just
  your admin login.

## Prerequisites

| Requirement | Why |
|---|---|
| `APP_URL` set to the **public** URL | It doubles as the OAuth issuer and redirect base. It must be reachable from where the MCP client runs, otherwise registration and login fail. |
| Stack reachable over the network | The MCP client talks HTTP to `${APP_URL}/mcp`. Behind a reverse proxy, terminate TLS there and forward to the `nginx` service. |
| Passport keys persisted | Handled automatically — generated once into `PASSPORT_KEYS_PATH` (`/data/passport` in compose) on the persistent volume, so issued tokens survive image rebuilds. |

No flags to flip: the MCP server is always on. It is reachable only with a
valid `mcp:use` token, so an un-authenticated `POST /mcp` returns `401`.

## Connect an MCP client

For **Claude Code**:

```bash
claude mcp add --transport http argos https://your-argos.example.com/mcp
```

Then, inside Claude Code, run `/mcp`, pick `argos`, and choose **Authenticate**.
A browser opens at `${APP_URL}/oauth/authorize`; sign in as your Argos admin
user and approve the `mcp:use` scope. The status flips to **connected** and the
tools below become available.

> Other clients that speak HTTP MCP with OAuth (Cursor, VS Code) work the same
> way — their private-use redirect schemes (`claude`, `cursor`, `vscode`) are
> already allow-listed in `config/mcp.php` (`custom_schemes`), and
> `redirect_domains` defaults to `*`.

## Available tools

The server exposes nine tools. A `task` or `project` argument always accepts
either the record's ULID **or** its exact name.

### Projects (read)

| Tool | What it does |
|---|---|
| `project_list` | Lists the configured repository profiles (projects) Argos can run tasks against, with their workflow defaults and the number of open tasks. |
| `project_get` | Returns one project (by id or name) together with an overview of its tasks. |

### Tasks (read)

| Tool | What it does |
|---|---|
| `task_list` | Lists tasks, optionally filtered by `project` and by workflow `status`. |
| `task_get` | Returns full detail for one task: description, concept, implement summaries, recent phase runs, the **checkout block** (`repo_url`, `base_branch`, `feature_branch`) and the PR url. |

The `status` filter accepts a workflow status string: `draft`,
`concept_running`, `concept_review`, `implement_running`, `implement_paused`,
`implement_completed`, `in_review`, `completed`, `failed`, `aborted`.

### Workflow (write)

| Tool | What it does |
|---|---|
| `task_create` | Creates a task from a plan and starts the Concept phase. The plan is stored both as the task description and as the concept notes, so the Concept run respects it. The feature branch is created during the Concept phase. Args: `name`, `project`, `plan`, optional `base_branch`. |
| `task_concept` | Runs (or re-runs) the Concept phase. If the previous Concept run is paused, it resumes with a fresh turn budget. Optional `max_turns`. |
| `task_implement` | Runs (or re-runs) the Implement phase. Requires a completed Concept run; resumes a paused run with a fresh turn budget. Optional `max_turns`. |
| `task_pr` | Runs the Push phase — pushes the feature branch and opens a pull request. Requires a completed Implement run. |
| `task_feedback` | Sends review feedback (`feedback`, Markdown) for a task and runs the Respond phase, which acts on the feedback. |

Write tools that start a phase return immediately: phases run asynchronously in
the worker, so you advance by re-reading `task_get`, not by waiting on the
write call.

## Typical flow

A plan → PR round trip from a planning session:

1. `project_list` — pick the repository profile to work in.
2. `task_create` — hand over the plan. The Concept phase starts automatically
   and the feature branch is created during that run.
3. `task_get` — poll the workflow status. Phases run asynchronously, so write
   tools return immediately; re-read to follow progress.
4. `task_implement`, then `task_pr` — advance once the previous phase is done
   (each gated on the prior phase having completed).
5. After `task_pr`, `task_get` exposes the checkout block, so you can
   `git checkout <feature_branch>` locally and review in your IDE.
6. `task_feedback` — send review feedback, which runs the Respond phase. Repeat
   until the change is ready to merge.

## Security

- **Scope-gated.** Only tokens carrying the `mcp:use` scope reach the server;
  the consent screen at first connect is where that scope is granted. A token
  issued for any other purpose cannot drive Argos over MCP.
- **Token lifetime.** Access tokens expire after **30 days** and refresh
  tokens after **60 days** (`Passport::tokensExpireIn` /
  `refreshTokensExpireIn`). When the access token expires, an OAuth-aware
  client refreshes it transparently; once the refresh token expires too, the
  client re-runs the browser consent.
- **Signing keys persist.** The Passport keys live on the persistent volume
  (`PASSPORT_KEYS_PATH`, `/data/passport` in compose), so an image rebuild does
  not silently invalidate every issued token.
- **Single user today.** Argos is single-user, so the OAuth login is your admin
  login. Anyone holding a valid `mcp:use` token has the same reach as that user
  — treat the token like a password and revoke it from the Argos UI if a client
  is lost.

## Troubleshooting

- **`401` with `WWW-Authenticate`** — expected without a token; complete the
  OAuth connect above.
- **Client registration fails / redirect rejected** — `APP_URL` does not match
  the URL the client actually reaches, or the client's redirect scheme is not
  in `config/mcp.php` (`custom_schemes` / `redirect_domains`).
- **Token stops working after a rebuild** — `PASSPORT_KEYS_PATH` is not on a
  persistent volume; in the stock compose stack it points at `/data/passport`.
- **Tool calls succeed but nothing happens in the UI** — phases run
  asynchronously. Confirm the queue worker (`queue` service) is up; re-read
  with `task_get` to see the status advance.

## See also

- [REST-API.md](REST-API.md) — the same workflow as a plain HTTP API for
  scripts and CI (the non-MCP automation path).
- [OAUTH.md](OAUTH.md) — the OAuth app wiring Argos uses for git providers.
- [CONFIGURATION.md](CONFIGURATION.md) — the related environment variables
  (`APP_URL`, `PASSPORT_KEYS_PATH`).
