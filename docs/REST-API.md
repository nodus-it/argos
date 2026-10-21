# Argos REST API

Argos exposes a versioned, server-to-server REST API for driving the same
workspaces you use in the web UI — list projects, start **automatic runs** that
plan, implement, fix failing quality gates and open a pull request, and steer
them from your own scripts, CI pipelines, or other automation.

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

### Token kinds: full vs. project-scoped

A token is bound to one of two owners, which determines its reach:

- **API client token (full access)** — bound to an *API Client*, an entry that
  represents a named consumer of the API. These tokens can see and act across
  **all** projects.
- **Project-scoped token** — bound to a single *Project* (repo profile). These
  tokens are confined to that one project: requests for any other project's
  data return `404`, and the `project` field is resolved against (and validated
  for) the token's own project.

Regardless of owner, the token's **abilities** still gate every endpoint.

### Creating a token in the UI

Tokens are minted from the Argos admin panel. The plaintext token is shown
**once** on creation — copy it immediately, because only its hash is stored and
it cannot be retrieved again.

**For a full-access token:**

1. Open the admin panel and go to **API Clients** (under the Configuration
   navigation group).
2. Create an API Client (give it a descriptive name), or open an existing one.
3. On the client's page, use the **API tokens** section to generate a token:
   give it a name and tick the abilities it should carry.
4. Copy the plaintext token from the one-time notification.

**For a project-scoped token:**

1. Open the **Project** (repo profile) you want to scope the token to.
2. Use the same **API tokens** section on the project's page to generate a
   token with a name and the desired abilities.
3. Copy the plaintext token from the one-time notification.

### Abilities

A token carries one or more of these abilities. They mirror the route gates
exactly — a request to an endpoint whose required ability the token lacks is
rejected.

| Ability | Grants access to |
| --- | --- |
| `projects:read` | List and read projects |
| `workspaces:read` | List and read workspaces and their threads |
| `workspaces:write` | Start automatic runs, write into threads, decide plans, pause/resume, ship |

### Who a workspace request acts for

Workspace endpoints always act for a **person**: that person's Claude agent
(subscription) does the work and they own the workspaces it creates.

- A **project-scoped token** acts for the project's **automation user**
  (project → *Automation* tab).
- An **API client token** acts for the client's **Runs as** user (API client
  form).

Without that user, workspace requests answer `422` with `"code": "no_actor"`.

### Using the token

Send it as a bearer token on every request:

```bash
curl https://your-argos-host/api/v1/projects \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

## Endpoints

A `{repoProfile}`, `{workspace}` or `{thread}` path parameter accepts the
ULID of that resource.

### List projects

```
GET /api/v1/projects
```

Requires `projects:read`. Returns all projects (or just the bound project, for
a project-scoped token), ordered by name.

```bash
curl https://your-argos-host/api/v1/projects \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": "01J...",
      "name": "my-service",
      "url": "https://github.com/acme/my-service",
      "platform": "github",
      "default_branch": "main",
      "auto_settings": {
        "plan": "auto",
        "ship": "pr",
        "max_fix_rounds": 3,
        "max_turns": 60,
        "max_cost_usd": 0,
        "max_minutes": 120
      },
      "open_workspaces": 2,
      "created_at": "2026-06-13T10:00:00.000000Z",
      "updated_at": "2026-06-13T10:00:00.000000Z"
    }
  ]
}
```

`platform` is one of `github`, `gitlab`, `bitbucket`. `auto_settings` are the
effective defaults for automatic runs in this project (installation defaults
overridden by the project's *Automation* tab). `open_workspaces` counts the
project's workspaces that are neither stopped nor failed.

### Get a project

```
GET /api/v1/projects/{repoProfile}
```

Requires `projects:read`. Returns a single project in the same shape as the list
entry above. A project-scoped token may only read its own project; any other id
returns `404`.

### Workspaces and automatic runs

An **automatic run** is a thread that plans (optionally), implements, fixes
failing quality gates and ships — see [Workspaces → Auto-threads](WORKSPACES.md#auto-threads).
Runs are asynchronous: write endpoints answer `202` and you poll the workspace.

| Method | Path | Ability | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/workspaces` | `workspaces:read` | Workspaces of the acting person (filters: `origin`, `status`) |
| GET | `/api/v1/workspaces/{workspace}` | `workspaces:read` | One workspace with its threads, pull request and latest gate results |
| GET | `/api/v1/threads/{thread}` | `workspaces:read` | One thread with its automatic-run state |
| POST | `/api/v1/workspaces` | `workspaces:write` | Create a workspace and start an automatic run |
| POST | `/api/v1/workspaces/{workspace}/threads` | `workspaces:write` | Start another automatic run in an existing workspace |
| POST | `/api/v1/workspaces/{workspace}/ship` | `workspaces:write` | Commit, push and open/update the pull request now (`draft`) |
| POST | `/api/v1/threads/{thread}/messages` | `workspaces:write` | Write into a thread (`text`); pauses a running automatic run unless `resume_auto` is true |
| POST | `/api/v1/threads/{thread}/plan` | `workspaces:write` | Decide a waiting plan: `decision` = `approve` or `reject`, optional `feedback` |
| POST | `/api/v1/threads/{thread}/pause` | `workspaces:write` | Pause automation |
| POST | `/api/v1/threads/{thread}/resume` | `workspaces:write` | Hand the thread back to automation |

**Start a run** — `POST /api/v1/workspaces`:

| Field | Required | Meaning |
| --- | --- | --- |
| `project` | yes | Project ULID or exact name |
| `prompt` | yes | What to implement (Markdown) |
| `title` | no | Workspace/thread title (defaults to the start of the prompt) |
| `base_branch` | no | Branch to start from (defaults to the project's default branch) |
| `issue` | no | Issue number of the project's issue tracker: names the workspace and branch after it, `Closes #…` in the pull request |
| `agent_id` | no | One of the acting person's usable agents (default: their first) |
| `model` | no | Model id or alias (`sonnet`, `opus`, …) |
| `plan` | no | `off`, `auto` or `approve` (default: project setting) |
| `ship` | no | `pr`, `draft_pr` or `none` (default: project setting) |
| `max_fix_rounds`, `max_turns`, `max_cost_usd`, `max_minutes` | no | Budgets (default: project setting) |

```bash
curl -X POST https://your-argos-host/api/v1/workspaces \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" -H "Accept: application/json" \
  -d project=widget -d prompt="Add a priority (low/medium/high) to notes" -d plan=approve
```

The thread's `auto.state` moves `queued → planning → (awaiting_plan_approval) →
implementing → gating ⇄ fixing → shipping → done`; it can also end `failed` or
`budget_exceeded`, or be `paused` by a person. The pull request appears under
`pull_request`.

Domain failures answer `422` with a `code`: `no_actor`, `no_usable_agent`,
`no_plan_waiting`, `capacity` (too many workspaces running), and others named
after the failure.

## Typical flow

1. `GET /api/v1/projects` — find the project to work in.
2. `POST /api/v1/workspaces` — start an automatic run with what to implement.
3. Poll `GET /api/v1/workspaces/{workspace}` and follow the thread's
   `auto.state`.
4. With `plan` = `approve`, decide the plan once the state is
   `awaiting_plan_approval`: `POST /api/v1/threads/{thread}/plan`.
5. When the state is `done`, the pull request is under `pull_request`.
6. `POST /api/v1/threads/{thread}/messages` — send feedback into the thread
   (with `resume_auto` to let the run continue on its own).

## Responses and errors

- Successful reads return `200` with the resource wrapped in a `data` envelope
  (single object) or `data` array (collections).
- Write actions that start asynchronous work return `202 Accepted` with the
  resource in the `data` envelope. The work runs in the background — re-read
  the workspace to follow it.
- Errors return a JSON body with a `message` field and an appropriate HTTP
  status.

| Status | Meaning |
| --- | --- |
| `401` | Missing or invalid token. |
| `403` | Token lacks the required ability for this endpoint. |
| `404` | Resource not found — or hidden from a project-scoped token. |
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
- [WORKSPACES.md](WORKSPACES.md#auto-threads) — how automatic runs work.
