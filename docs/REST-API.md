# Argos REST API

Argos exposes a versioned, server-to-server REST API onto the same workspaces
and tasks you use in the web UI: list workspaces, create them, start
**automatic tasks** that plan, implement, fix failing quality gates and open a
pull request, follow and steer them, and connect your own ticket system. It
runs against the exact same logic as the UI and the [MCP server](SETUP-MCP.md).

## Base URL and versioning

All endpoints live under `/api/v1`:

```
https://your-argos-host/api/v1
```

`v1` is the current and only API version.

## Authentication

The API uses **Sanctum bearer tokens**: send `Authorization: Bearer <token>`
(and `Accept: application/json`) on every request. Tokens carry **abilities**
that gate the endpoints.

### Token kinds

| Kind | Created at | Reach | Acts for |
|---|---|---|---|
| **API client token** | Admin panel → **Configuration → API Clients** → a client → *API tokens* | Every workspace the client's **Runs as** user can see (an Argos administrator sees all) | The client's **Runs as** user |
| **Workspace token** | Workspace **Settings → Integrations → API tokens** (workspace admins), or the workspace in the admin panel | Only that workspace — other workspaces and their tasks answer `404` | The workspace's **automation user** (Settings → Automation) |

The plaintext token is shown **once** on creation; only its hash is stored.

### Abilities

| Ability | Grants |
| --- | --- |
| `workspaces:read` | List and read workspaces |
| `workspaces:write` | Create workspaces — only API client tokens whose **Runs as** user is an Argos administrator; workspace tokens can never get it |
| `tasks:read` | List a workspace's tasks and read a task |
| `tasks:write` | Start automatic tasks, write into tasks, decide plans, pause/resume, ship, post ticket comments |

A request to an endpoint whose ability the token lacks answers `403`.

### Who a request acts for

Every request acts for a **person**: their Claude agent (subscription) does
the work, they own the tasks it starts, and their workspace role decides what
they may do. Without that person (no automation user / no *Runs as* user)
requests answer `422` with `"code": "no_actor"`.

- `admin` and `member` may start tasks; a `viewer` only reads.
- Everybody in a workspace reads all of its tasks, but only a task's owner,
  its collaborators and workspace admins may write into, steer or ship it —
  other tasks answer `403`.
- Starting a task needs a usable Claude agent of the acting person
  (`no_usable_agent` otherwise).

## Endpoints

`{workspace}` and `{task}` are ULIDs.

| Method | Path | Ability | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/workspaces` | `workspaces:read` | Workspaces of the acting person, by name (filter: `status`; max. 200) |
| GET | `/api/v1/workspaces/{workspace}` | `workspaces:read` | One workspace |
| POST | `/api/v1/workspaces` | `workspaces:write` | [Create a workspace](#create-a-workspace) |
| GET | `/api/v1/workspaces/{workspace}/tasks` | `tasks:read` | The workspace's tasks, last updated first (filters: `origin` = `manual`/`api`/`mcp`/`issue`, `archived=1` to include archived; max. 200) |
| GET | `/api/v1/tasks/{task}` | `tasks:read` | One task |
| POST | `/api/v1/workspaces/{workspace}/tasks` | `tasks:write` | [Start an automatic task](#start-a-task) |
| POST | `/api/v1/tasks/{task}/messages` | `tasks:write` | Write into a task |
| POST | `/api/v1/tasks/{task}/plan` | `tasks:write` | Decide a waiting plan |
| POST | `/api/v1/tasks/{task}/pause` | `tasks:write` | Pause automation |
| POST | `/api/v1/tasks/{task}/resume` | `tasks:write` | Hand the task back to automation |
| POST | `/api/v1/tasks/{task}/ship` | `tasks:write` | Commit, push and open/update the pull request now |
| POST | `/api/v1/tasks/{task}/ticket-comments` | `tasks:write` | Show a comment of your own ticket system in the task |

## Workspaces

```bash
curl https://your-argos-host/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": "01J...",
      "name": "my-service",
      "repository": "acme/my-service",
      "platform": "github",
      "default_branch": "main",
      "status": "running",
      "setup": "ready",
      "url": "https://your-argos-host/w/workspaces/01J...",
      "created_at": "2026-10-21T10:00:00.000000Z",
      "updated_at": "2026-10-21T10:00:00.000000Z"
    }
  ]
}
```

| Field | Values |
|---|---|
| `platform` | `github`, `gitlab`, `bitbucket` |
| `status` | the container: `provisioning`, `starting`, `running`, `stopping`, `stopped`, `failed` |
| `setup` | the [setup status](PREPARE-PROJECT.md#setup-status): `needed`, `running`, `testing`, `ready`, `failed`, `needs_agent`, or `null` before the first inspection |

### Create a workspace

`POST /api/v1/workspaces` (`workspaces:write`, API client token of an Argos
administrator; a workspace token gets `403`):

| Field | Required | Meaning |
|---|---|---|
| `name` | yes | Display name (max. 120) |
| `repo_url` | yes | Clone URL, e.g. `https://github.com/acme/app` |
| `platform` | yes | `github`, `gitlab` or `bitbucket` |
| `default_branch` | yes | Branch tasks start from |
| `auth_method` | yes | `pat` or `oauth` |
| `token` | with `pat` | The repository access token |

With `oauth`, the *Runs as* user's connected account for the platform is used
(`422` `no_connected_account` without one). The answer is `201` with the
workspace; the acting user becomes its first admin. Argos then inspects the
repository and, if needed, starts the [setup task](PREPARE-PROJECT.md#the-setup-task)
on that user's subscription — follow `setup`. Other settings (services,
secrets, members, …) are set in the UI.

## Tasks

A task as the API returns it:

```json
{
  "data": {
    "id": "01J...",
    "title": "Add a priority to notes",
    "workspace_id": "01J...",
    "owner": { "id": 3, "name": "Ada" },
    "origin": "api",
    "status": "pr_open",
    "session": "closed",
    "branch": "argos/add-a-priority-to-notes",
    "base_branch": "main",
    "kind": "task",
    "pull_request": {
      "url": "https://github.com/acme/app/pull/7",
      "number": 7,
      "state": "open",
      "checks": "success",
      "review": "approved",
      "conflicts": false
    },
    "auto_merge": false,
    "gates": { "status": "passed", "results": { "...": "..." } },
    "ticket": { "key": "gitlab-42", "number": "42", "title": "…", "url": "https://…" },
    "model": "sonnet",
    "permission_mode": "bypassPermissions",
    "cost_usd": 0.42,
    "auto": {
      "state": "done",
      "message": null,
      "plan": "approve",
      "ship": "pr",
      "fix_rounds": 1,
      "max_fix_rounds": 3,
      "turns": 12
    },
    "url": "https://your-argos-host/w/workspaces/01J...?task=01J...",
    "created_at": "…",
    "updated_at": "…"
  }
}
```

| Field | Values |
|---|---|
| `origin` | `manual`, `api`, `mcp`, `issue` |
| `status` | `preparing`, `running`, `waiting`, `ready`, `pr_open`, `done`, `cancelled`, `archived` (see [Tasks](TASKS.md#status-and-the-task-switcher)) |
| `session` | `closed`, `starting`, `idle`, `running`, `waiting_input`, `error` |
| `kind` | `task`, or `setup` for the [setup task](PREPARE-PROJECT.md#the-setup-task) |
| `pull_request` | `null` until shipped. `state`: `draft`, `open`, `merged`, `closed`; `checks`: `pending`, `success`, `failure`, `none` (or `null` before the first sync); `review`: `approved`, `changes_requested`, `commented` or `null`; `conflicts`: boolean. See [Pull Requests](PULL-REQUESTS.md). |
| `auto_merge` | Whether the provider's auto-merge is (to be) switched on |
| `gates` | The latest gate run (`status`, per-gate `results`) or `null` |
| `ticket` | The linked [ticket](TICKETS.md) or `null` |
| `auto` | `null` for a task nobody runs automatically; otherwise the run state (`queued`, `planning`, `awaiting_plan_approval`, `implementing`, `gating`, `fixing`, `shipping`, `done`, `paused`, `failed`, `budget_exceeded`), its message, settings and counters |

### Start a task

`POST /api/v1/workspaces/{workspace}/tasks` starts an **automatic task**
(see [Tasks → Automatic tasks](TASKS.md#automatic-tasks)). It answers `202`
with the task — it continues asynchronously; poll the task to follow it.

| Field | Required | Meaning |
| --- | --- | --- |
| `prompt` | yes | What to implement (Markdown, max. 20,000) |
| `title` | no | Task title (default: the ticket title or the start of the prompt; max. 120) |
| `base_branch` | no | Branch to start from (default: the workspace's default branch) |
| `auto_merge` | no | `true` switches on the provider's auto-merge once the pull request exists |
| `issue` | no | Ticket number of the workspace's GitHub/GitLab/Linear integration: names the branch after it, links and reports back |
| `ticket` | no | A ticket of your [own system](TICKETS.md#own-ticket-systems): `key` and `title` required, `url` and `body` optional. Cannot be combined with `issue`. |
| `agent_id` | no | One of the acting person's usable agents (default: their first) |
| `model` | no | Model id or alias (`sonnet`, `opus`, …) |
| `plan` | no | `off`, `auto` or `approve` (default: workspace setting) |
| `ship` | no | `pr`, `draft_pr` or `none` (default: workspace setting) |
| `max_fix_rounds` (0–20), `max_turns` (1–1000), `max_cost_usd` (≥ 0), `max_minutes` (1–1440) | no | Budgets (default: workspace setting) |

```bash
curl -X POST https://your-argos-host/api/v1/workspaces/01J.../tasks \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"prompt": "Add a priority (low/medium/high) to notes", "plan": "approve", "auto_merge": true}'
```

A stopped workspace is started; the task is prepared (worktree, database,
setup steps) and then runs. Its `auto.state` moves `queued → planning →
(awaiting_plan_approval) → implementing → gating ⇄ fixing → shipping → done`;
it can also end `failed` or `budget_exceeded`, or be `paused` by a person.

### Steer a task

| Endpoint | Body | Answer |
|---|---|---|
| `POST /tasks/{task}/messages` | `text` (required), `resume_auto` (boolean) | `202` `{"message_id": …}`. In a running automatic task this pauses automation unless `resume_auto` is true. |
| `POST /tasks/{task}/plan` | `decision` = `approve` or `reject`, optional `feedback` | `202` with the task (`no_plan_waiting` when none waits) |
| `POST /tasks/{task}/pause` | — | `200` with the task |
| `POST /tasks/{task}/resume` | — | `202` with the task — it checks the gates and ships |
| `POST /tasks/{task}/ship` | optional `draft` (boolean) | `200` `{"pull_request_url": "…"}` |
| `POST /tasks/{task}/ticket-comments` | `author` (required), `body` (required), optional `url` | `202` — see [Tickets](TICKETS.md#posting-comments) |

## Typical flow

1. `GET /api/v1/workspaces` — find the workspace.
2. `POST /api/v1/workspaces/{workspace}/tasks` — start an automatic task.
3. Poll `GET /api/v1/tasks/{task}` and follow `auto.state`.
4. With `plan` = `approve`, decide the plan once the state is
   `awaiting_plan_approval`: `POST /api/v1/tasks/{task}/plan`.
5. When the state is `done`, the pull request is under `pull_request`; keep
   polling to see its checks, reviews and — once merged — `status: done`.
6. `POST /api/v1/tasks/{task}/messages` — send feedback (with `resume_auto` to
   let the run continue on its own).

## Responses and errors

- Reads return `200` with the resource in a `data` envelope (object or array).
- Writes that start asynchronous work return `202`.
- Domain failures answer `422` with a `message` and a machine-readable `code`,
  e.g. `no_actor`, `no_usable_agent`, `no_plan_waiting`, `capacity` (too many
  workspaces running), `no_connected_account`, and others named after the
  failure. A refused permission answers `403` with `"code": "forbidden"`.

| Status | Meaning |
| --- | --- |
| `401` | Missing or invalid token. |
| `403` | The token lacks the ability, or the acting person may not do this (`"code": "forbidden"`). |
| `404` | Not found — or hidden from a workspace token or from the acting person. |
| `422` | Validation error (`errors` per field), or a domain failure with a `code`. |

```json
{
  "message": "The prompt field is required.",
  "errors": { "prompt": ["The prompt field is required."] }
}
```

## Interactive API reference

An auto-generated OpenAPI reference (Scramble) is at
`https://your-argos-host/docs/api`, the raw document at `/docs/api.json` —
for signed-in Argos users.

## See also

- [SETUP-MCP.md](SETUP-MCP.md) — the MCP server, the same tasks for an AI
  client.
- [TASKS.md](TASKS.md#automatic-tasks) — how automatic tasks work.
- [TICKETS.md](TICKETS.md#own-ticket-systems) — own ticket systems and the
  signed webhook.
