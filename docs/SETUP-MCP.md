# MCP Server

Argos ships a built-in [MCP](https://modelcontextprotocol.io) server so an
external MCP client — in particular **Claude Code** — can drive Argos directly:
list projects and tasks, hand over a plan as a new task, run the
Concept/Implement/Push phases, and pull the resulting feature branch locally.

This turns the usual loop ("plan in Claude Code → copy into the Argos UI →
watch the browser → copy the branch name") into a single in-session flow.

## How it works

- The server is mounted at **`<APP_URL>/mcp`** and is part of the `app`
  service — no extra container.
- Auth is **OAuth 2.1** via Laravel Passport. Tokens must carry the
  `mcp:use` scope. Clients register themselves through Dynamic Client
  Registration (`/oauth/register`); discovery lives under
  `/.well-known/oauth-*`.
- The first connect opens a **browser login** as an Argos user plus a consent
  screen. Argos is single-user today, so this is just your admin login.

## Prerequisites

| Requirement | Why |
|---|---|
| `APP_URL` set to the **public** URL | It doubles as the OAuth issuer and redirect base. It must be reachable from where the MCP client runs, otherwise registration and login fail. |
| Stack reachable over the network | The MCP client talks HTTP to `<APP_URL>/mcp`. Behind a reverse proxy, terminate TLS there and forward to the `nginx` service. |
| Passport keys persisted | Handled automatically — generated once into `PASSPORT_KEYS_PATH` (`/data/passport` in compose) on the persistent volume, so issued tokens survive image rebuilds. |

No flags to flip: the MCP server is always on. It is reachable only with a
valid `mcp:use` token, so an un-authenticated `POST /mcp` returns `401`.

## Connect Claude Code

```bash
claude mcp add --transport http argos https://your-argos.example.com/mcp
```

Then, inside Claude Code, run `/mcp`, pick `argos`, and choose **Authenticate**.
A browser opens at `<APP_URL>/oauth/authorize`; sign in as your Argos admin
user and approve the `mcp:use` scope. The status flips to **connected** and the
tools below become available.

> Other clients that speak HTTP MCP with OAuth (Cursor, VS Code) work the same
> way — their custom redirect schemes are already allow-listed in
> `config/mcp.php` (`custom_schemes`).

## Available tools

| Tool | Kind | What it does |
|---|---|---|
| `project_list` | read | List repository profiles with their workflow defaults and open-task counts. |
| `project_get` | read | One project (by id or name) plus a task overview. |
| `task_list` | read | List tasks, optionally filtered by project and workflow status. |
| `task_get` | read | Full task detail incl. concept, summaries, recent phase runs, the **checkout block** (`repo_url`, `base_branch`, `feature_branch`) and the PR url. |
| `task_create` | write | Create a task from a plan and start the Concept phase. The plan is stored as both the description and the concept notes. |
| `task_concept` | write | Run (or resume) the Concept phase. |
| `task_implement` | write | Run (or resume) the Implement phase (requires a completed concept). |
| `task_pr` | write | Run the Push phase — pushes the branch and opens the PR (requires a completed implement). |
| `task_feedback` | write | Submit review feedback and run the Respond phase. |

A `task`/`project` argument accepts either the ULID or the exact name.

## Typical flow

1. `project_list` — pick the repository profile to work in.
2. `task_create` — hand over the plan. The Concept phase starts automatically
   and the feature branch is created during that run.
3. `task_get` — poll the workflow status. Phases run asynchronously, so write
   tools return immediately; re-read to follow progress.
4. `task_implement`, then `task_pr` — advance once the previous phase is done.
5. After `task_pr`, `task_get` exposes the checkout block, so you can
   `git checkout <feature_branch>` locally and review in your IDE.
6. `task_feedback` — send review feedback, which runs the Respond phase.

## Troubleshooting

- **`401` with `WWW-Authenticate`** — expected without a token; complete the
  OAuth connect above.
- **Client registration fails / redirect rejected** — `APP_URL` does not match
  the URL the client actually reaches, or the client's redirect scheme is not
  in `config/mcp.php` (`custom_schemes` / `redirect_domains`).
- **Token stops working after a rebuild** — `PASSPORT_KEYS_PATH` is not on a
  persistent volume; in the stock compose stack it points at `/data/passport`.

See [Configuration Reference](CONFIGURATION.md) for the related environment
variables.
