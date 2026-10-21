# Argos REST API

Argos exposes a versioned, server-to-server REST API for driving the same
workspaces you use in the web UI — list workspaces, start **automatic tasks**
that plan, implement, fix failing quality gates and open a pull request, and
steer them from your own scripts, CI pipelines, or other automation.

The REST API runs against the exact same workspace logic as the Argos UI and
the [MCP server](SETUP-MCP.md).

If you would rather drive Argos from an AI agent (Claude Desktop, an IDE, etc.)
than write HTTP calls yourself, see [SETUP-MCP.md](SETUP-MCP.md) for the MCP
server — it is the alternative automation surface onto the same workspaces.

## Base URL and versioning

All endpoints live under the `/api/v1` prefix:

```
https://your-argos-host/api/v1
```

`v1` is the current and only API version. Replace `your-argos-host` with the
host where your Argos instance is reachable.

## Authentication

The API uses **Sanctum bearer tokens**. Every request must send the token in an
`Authorization: Bearer <token>` header. Tokens also carry **abilities** (scopes)
that gate which endpoints they may call.

### Token kinds: API client vs. workspace-scoped

A token is bound to one of two owners, which determines its reach:

- **API client token** — bound to an *API Client*, an entry that represents a
  named consumer of the API. It acts for the client's **Runs as** user and
  sees every workspace that person can see (an Argos admin sees all).
- **Workspace-scoped token** — bound to a single *Workspace*. It is confined to
  that one workspace: requests for any other workspace or its tasks return
  `404`.

Regardless of owner, the token's **abilities** still gate every endpoint.

### Creating a token in the UI

Tokens are minted from the Argos admin panel. The plaintext token is shown
**once** on creation — copy it immediately, because only its hash is stored and
it cannot be retrieved again.

**For an API client token:**

1. Open the admin panel and go to **API Clients** (under the Configuration
   navigation group).
2. Create an API Client (give it a descriptive name and a **Runs as** user), or
   open an existing one.
3. On the client's page, use the **API tokens** section to generate a token:
   give it a name and tick the abilities it should carry.
4. Copy the plaintext token from the one-time notification.

**For a workspace-scoped token:**

1. Open the **Workspace** in the admin panel that you want to scope the token
   to.
2. Use the same **API tokens** section on the workspace's page to generate a
   token with a name and the desired abilities.
3. Copy the plaintext token from the one-time notification.

### Abilities

A token carries one or more of these abilities. They mirror the route gates
exactly — a request to an endpoint whose required ability the token lacks is
rejected with `403`.

| Ability | Grants access to |
| --- | --- |
| `workspaces:read` | List and read workspaces |
| `tasks:read` | List a workspace's tasks and read a task |
| `tasks:write` | Start automatic tasks, write into tasks, decide plans, pause/resume, ship |

### Who a request acts for

Every request acts for a **person**: that person's Claude agent (subscription)
does the work, they own the tasks it starts, and their workspace role decides
what they may do.

- A **workspace-scoped token** acts for the workspace's **automation user**
  (workspace → *Automation* tab).
- An **API client token** acts for the client's **Runs as** user (API client
  form).

Without that user, requests answer `422` with `"code": "no_actor"`.

What the person may do follows their workspace role: `admin` and `member` may
start tasks, a `viewer` only reads. Everybody in a workspace sees all of its
tasks, but only a task's owner and its collaborators (or a workspace admin) may
write into, steer or ship it — other tasks answer `403`.

### Using the token

Send it as a bearer token on every request:

```bash
curl https://your-argos-host/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

## Endpoints

A `{workspace}` or `{task}` path parameter accepts the ULID of that resource.

| Method | Path | Ability | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/workspaces` | `workspaces:read` | Workspaces of the acting person (filter: `status`) |
| GET | `/api/v1/workspaces/{workspace}` | `workspaces:read` | One workspace |
| GET | `/api/v1/workspaces/{workspace}/tasks` | `tasks:read` | The workspace's tasks, newest first (filters: `origin`, `archived`) |
| GET | `/api/v1/tasks/{task}` | `tasks:read` | One task with its branch, pull request, gates, ticket and automatic-run state |
| POST | `/api/v1/workspaces/{workspace}/tasks` | `tasks:write` | Start an automatic task |
| POST | `/api/v1/tasks/{task}/messages` | `tasks:write` | Write into a task (`text`); pauses a running automatic task unless `resume_auto` is true |
| POST | `/api/v1/tasks/{task}/plan` | `tasks:write` | Decide a waiting plan: `decision` = `approve` or `reject`, optional `feedback` |
| POST | `/api/v1/tasks/{task}/pause` | `tasks:write` | Pause automation |
| POST | `/api/v1/tasks/{task}/resume` | `tasks:write` | Hand the task back to automation |
| POST | `/api/v1/tasks/{task}/ship` | `tasks:write` | Commit, push and open/update the task's pull request now (`draft`) |

### Workspaces

```bash
curl https://your-argos-host/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
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
      "url": "https://your-argos-host/w/workspaces/01J...",
      "created_at": "2026-06-13T10:00:00.000000Z",
      "updated_at": "2026-06-13T10:00:00.000000Z"
    }
  ]
}
```

`platform` is one of `github`, `gitlab`, `bitbucket`. `status` is the state of
the workspace's container (`stopped`, `running`, …).

### Tasks

A **task** is one piece of work in a workspace — a conversation with Claude on
its own branch, with its own pull request. Each task has an `owner`, a
`status` (`preparing`, `running`, `waiting`, `ready`, `pr_open`, `done`,
`cancelled` or `archived`), a `branch` and `base_branch`, the `pull_request`
(`url`, `number`, `state`), the latest `gates`, the linked `ticket` and, for an
automatic task, its `auto` state.

An **automatic task** plans (optionally), implements, fixes failing quality
gates and ships — see [Workspaces → Auto-threads](WORKSPACES.md#auto-threads).
It runs asynchronously: write endpoints answer `202` and you poll the task.

**Start an automatic task** — `POST /api/v1/workspaces/{workspace}/tasks`:

| Field | Required | Meaning |
| --- | --- | --- |
| `prompt` | yes | What to implement (Markdown) |
| `title` | no | Task title (defaults to the ticket title or the start of the prompt) |
| `base_branch` | no | Branch to start from (defaults to the workspace's default branch) |
| `issue` | no | Ticket number of the workspace's issue tracker: names the branch after it, `Closes #…` in the pull request |
| `agent_id` | no | One of the acting person's usable agents (default: their first) |
| `model` | no | Model id or alias (`sonnet`, `opus`, …) |
| `plan` | no | `off`, `auto` or `approve` (default: workspace setting) |
| `ship` | no | `pr`, `draft_pr` or `none` (default: workspace setting) |
| `max_fix_rounds`, `max_turns`, `max_cost_usd`, `max_minutes` | no | Budgets (default: workspace setting) |

```bash
curl -X POST https://your-argos-host/api/v1/workspaces/01J.../tasks \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" -H "Accept: application/json" \
  -d prompt="Add a priority (low/medium/high) to notes" -d plan=approve
```

A stopped workspace is started; the task waits in the queue until it is up.
The task's `auto.state` moves `queued → planning → (awaiting_plan_approval) →
implementing → gating ⇄ fixing → shipping → done`; it can also end `failed` or
`budget_exceeded`, or be `paused` by a person. The pull request appears under
`pull_request`.

Domain failures answer `422` with a `code`: `no_actor`, `no_usable_agent`,
`no_plan_waiting`, `capacity` (too many workspaces running), and others named
after the failure. A refused permission answers `403` with `"code":
"forbidden"`.

## Typical flow

1. `GET /api/v1/workspaces` — find the workspace to work in.
2. `POST /api/v1/workspaces/{workspace}/tasks` — start an automatic task with
   what to implement.
3. Poll `GET /api/v1/tasks/{task}` and follow its `auto.state`.
4. With `plan` = `approve`, decide the plan once the state is
   `awaiting_plan_approval`: `POST /api/v1/tasks/{task}/plan`.
5. When the state is `done`, the pull request is under `pull_request`.
6. `POST /api/v1/tasks/{task}/messages` — send feedback into the task (with
   `resume_auto` to let the run continue on its own).

## Responses and errors

- Successful reads return `200` with the resource wrapped in a `data` envelope
  (single object) or `data` array (collections).
- Write actions that start asynchronous work return `202 Accepted` with the
  resource in the `data` envelope. The work runs in the background — re-read
  the task to follow it.
- Errors return a JSON body with a `message` field and an appropriate HTTP
  status.

| Status | Meaning |
| --- | --- |
| `401` | Missing or invalid token. |
| `403` | Token lacks the required ability for this endpoint, or the acting person may not do this (`"code": "forbidden"`). |
| `404` | Resource not found — or hidden from a workspace-scoped token or from the acting person. |
| `422` | Validation error (missing/invalid fields), or a domain failure with a `code` (see above). |

A `422` validation example:

```json
{
  "message": "The prompt field is required.",
  "errors": {
    "prompt": ["The prompt field is required."]
  }
}
```

## Interactive API reference

Argos ships an auto-generated, interactive OpenAPI reference (Scramble) at:

```
https://your-argos-host/docs/api
```

The raw OpenAPI document is available at `/docs/api.json`. Access to the docs is
restricted to signed-in Argos users.

## See also

- [SETUP-MCP.md](SETUP-MCP.md) — the MCP server, the alternative automation
  surface onto the same workspaces, for driving Argos from an AI agent.
- [WORKSPACES.md](WORKSPACES.md#auto-threads) — how automatic tasks work.
