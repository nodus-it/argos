# MCP Server

Argos ships a built-in [MCP](https://modelcontextprotocol.io) server so an
external MCP client — in particular **Claude Code** — can drive Argos directly:
list workspaces, hand over what to implement as an **automatic task** in a
workspace, follow it, approve its plan, steer it with feedback, and pick up the
pull request.

This turns the usual loop ("plan in Claude Code → copy into the Argos UI →
watch the browser → copy the pull request link") into a single in-session flow,
without leaving your planning client.

If you'd rather automate Argos from scripts or CI than from an interactive MCP
client, the same workspaces are also exposed as a plain HTTP API — see
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

The server exposes nine tools. A `workspace` argument accepts either the
workspace's ULID **or** its exact name; a `task` argument takes the task's
ULID.

### Workspaces (read)

| Tool | What it does |
| --- | --- |
| `workspace_list` | Lists the workspaces you can see: repository, default branch and container status. |
| `workspace_get` | One workspace by id or name, with its open tasks (owner, status, branch, pull request, gates, ticket, `auto.state`). |

### Tasks and automatic runs

| Tool | What it does |
| --- | --- |
| `task_create` | Starts an automatic task in a workspace: plan (per `plan`: off / auto / approve), implement on its own branch, fix failing quality gates, open the pull request. Args: `workspace`, `prompt`, optional `title`, `issue`, `base_branch`, `plan`, `ship`. Returns immediately. |
| `task_get` | One task — poll it to follow a run (`auto.state`, `pull_request`, `gates`). |
| `task_plan_decide` | Approves or rejects a waiting plan (`decision`, optional `feedback`). |
| `task_message` | Writes into a task (`text`); `resume_auto` lets the run continue on its own afterwards. |
| `task_pause` / `task_resume` | Pause automation / hand the task back to it. |
| `task_ship` | Commits, pushes and opens/updates the task's pull request now (optional `draft`). |

Tasks act for you: your Claude agent does the work and you own the task. You
can read every task of a workspace you belong to, but only write into, steer or
ship your own tasks and those you collaborate on (a workspace admin may all).

## Typical flow

A plan → PR round trip from a planning session:

1. `workspace_list` / `workspace_get` — pick the workspace to work in.
2. `task_create` — hand over what to implement (`prompt`). Choose `plan` and
   `ship`, or leave them to the workspace's defaults. It returns immediately.
3. `task_get` — poll the run: its `auto.state` goes queued → planning →
   implementing → gating/fixing → shipping → done.
4. With `plan = approve` the run stops at `awaiting_plan_approval`:
   `task_plan_decide` approves or rejects (with feedback).
5. When the run is done, `task_get` shows the pull request under
   `pull_request`; check out its branch locally to review in your IDE.
6. `task_message` — send review feedback into the task; set `resume_auto` to
   let the run continue on its own. `task_ship` ships the current state on
   demand.

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
- **Tool calls succeed but nothing happens in the UI** — runs are
  asynchronous. Confirm the queue worker (`queue` service) is up; re-read
  with `task_get` to see the state advance.
- **`task_create` fails with "no usable agent"** — you need an active
  Claude agent of your own (or a shared one) under *Agents*.

## See also

- [REST-API.md](REST-API.md) — the same workspaces as a plain HTTP API for
  scripts and CI (the non-MCP automation path).
- [OAUTH.md](OAUTH.md) — the OAuth app wiring Argos uses for git providers.
- [CONFIGURATION.md](CONFIGURATION.md) — the related environment variables
  (`APP_URL`, `PASSPORT_KEYS_PATH`).
