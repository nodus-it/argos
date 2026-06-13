# Argos REST API

Argos exposes a versioned, server-to-server REST API for driving the same task
workflow you use in the web UI — list projects, create tasks, and advance them
through the Concept → Implement → Push (PR) phases from your own scripts, CI
pipelines, or other automation.

The REST API runs against the exact same `TaskService` logic as the Argos UI
and the [MCP server](SETUP-MCP.md), so anything you can do by clicking through a
task you can also do over HTTP.

If you would rather drive Argos from an AI agent (Claude Desktop, an IDE, etc.)
than write HTTP calls yourself, see [SETUP-MCP.md](SETUP-MCP.md) for the MCP
server — it is the alternative automation surface onto the same workflow.

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
| `tasks:read` | List and read tasks |
| `tasks:write` | Create tasks and run/advance phases (feedback, concept, implement, pr) |

### Using the token

Send it as a bearer token on every request:

```bash
curl https://your-argos-host/api/v1/projects \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

## Endpoints

A `{task}` path parameter accepts the task's ULID. A `{repoProfile}` path
parameter accepts the project's ULID.

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
      "auto_concept": false,
      "auto_pr": false,
      "open_tasks": 2,
      "created_at": "2026-06-13T10:00:00.000000Z",
      "updated_at": "2026-06-13T10:00:00.000000Z"
    }
  ]
}
```

`platform` is one of `github`, `gitlab`, `bitbucket`.

### Get a project

```
GET /api/v1/projects/{repoProfile}
```

Requires `projects:read`. Returns a single project in the same shape as the list
entry above. A project-scoped token may only read its own project; any other id
returns `404`.

### List tasks

```
GET /api/v1/tasks
```

Requires `tasks:read`. Returns tasks newest-first. Optional query parameters:

| Parameter | Description |
| --- | --- |
| `project` | Filter by project id or project name. Ignored for project-scoped tokens (already confined to their project). |
| `status` | Filter by `workflow_status` value (see below). |

```bash
curl "https://your-argos-host/api/v1/tasks?project=my-service&status=in_review" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": "01J...",
      "name": "Add rate limiting",
      "project": { "id": "01J...", "name": "my-service" },
      "workflow_status": "in_review",
      "current_phase": "push",
      "current_status": "completed",
      "feature_branch": "argos/add-rate-limiting",
      "pr_url": "https://github.com/acme/my-service/pull/42",
      "created_at": "2026-06-13T10:00:00.000000Z"
    }
  ]
}
```

`workflow_status` is one of: `draft`, `concept_running`, `concept_review`,
`implement_running`, `implement_paused`, `implement_completed`, `in_review`,
`completed`, `failed`, `aborted`.

### Get a task

```
GET /api/v1/tasks/{task}
```

Requires `tasks:read`. Returns the full task detail, including the concept and
implement bodies, a `checkout` block for cloning the result locally, and the
last few phase runs.

```bash
curl https://your-argos-host/api/v1/tasks/01J... \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

```json
{
  "data": {
    "id": "01J...",
    "name": "Add rate limiting",
    "description": "The plan text...",
    "workflow_status": "in_review",
    "current_phase": "push",
    "current_status": "completed",
    "project": { "id": "01J...", "name": "my-service" },
    "concept_md": "# Concept\n...",
    "concept_notes": "The plan text...",
    "implement_summary_nontechnical": "...",
    "implement_summary_technical": "...",
    "checkout": {
      "repo_url": "https://github.com/acme/my-service",
      "base_branch": "main",
      "feature_branch": "argos/add-rate-limiting"
    },
    "pr_url": "https://github.com/acme/my-service/pull/42",
    "phase_runs": [
      {
        "phase": "push",
        "iteration": 1,
        "status": "completed",
        "started_at": "2026-06-13T11:00:00.000000Z",
        "finished_at": "2026-06-13T11:05:00.000000Z"
      }
    ],
    "created_at": "2026-06-13T10:00:00.000000Z",
    "updated_at": "2026-06-13T11:05:00.000000Z"
  }
}
```

### Create a task

```
POST /api/v1/tasks
```

Requires `tasks:write`. Creates a task from a plan and **immediately starts the
Concept phase**. Because phases run asynchronously, this returns `202 Accepted`
right away with the new task; poll `GET /api/v1/tasks/{task}` to follow progress.

Request body:

| Field | Required | Description |
| --- | --- | --- |
| `name` | yes | Task name (max 255 chars). |
| `plan` | yes | The plan. Stored as both the task description and the concept notes. |
| `project` | conditional | Project id or name. Required for full-access tokens; optional for project-scoped tokens. If supplied with a project-scoped token, it must match the token's project. |
| `base_branch` | no | Branch to base the work on (max 255 chars). Defaults to the project's default branch. |

```bash
curl -X POST https://your-argos-host/api/v1/tasks \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
        "name": "Add rate limiting",
        "plan": "Add a rate limiter to the public API...",
        "project": "my-service",
        "base_branch": "main"
      }'
```

Responds `202 Accepted` with the task in the same shape as **Get a task**.

### Submit feedback

```
POST /api/v1/tasks/{task}/feedback
```

Requires `tasks:write`. Sends review feedback, which runs the Respond phase.

| Field | Required | Description |
| --- | --- | --- |
| `feedback` | yes | The feedback text. |

```bash
curl -X POST https://your-argos-host/api/v1/tasks/01J.../feedback \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{ "feedback": "Please also cover the websocket route." }'
```

Responds `202 Accepted` with the task.

### Run / continue the Concept phase

```
POST /api/v1/tasks/{task}/concept
```

Requires `tasks:write`. Starts the Concept phase, or — if the previous Concept
run is paused — continues it.

| Field | Required | Description |
| --- | --- | --- |
| `max_turns` | no | Integer 10–1000. Only applies when continuing a paused run; otherwise defaults are used. |

Responds `202 Accepted` with the task.

### Run / continue the Implement phase

```
POST /api/v1/tasks/{task}/implement
```

Requires `tasks:write`. Starts the Implement phase, or continues a paused
Implement run. **Requires a completed Concept run first** — otherwise returns
`409`.

| Field | Required | Description |
| --- | --- | --- |
| `max_turns` | no | Integer 10–1000. Only applies when continuing a paused run. |

Responds `202 Accepted` with the task.

### Open the pull request (Push phase)

```
POST /api/v1/tasks/{task}/pr
```

Requires `tasks:write`. Runs the Push phase, which opens the pull request.
**Requires a completed Implement run first** — otherwise returns `409`.

```bash
curl -X POST https://your-argos-host/api/v1/tasks/01J.../pr \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

Responds `202 Accepted` with the task. Once the Push phase completes, re-read
the task to find the `pr_url` and the `checkout` block.

## Typical flow

1. `GET /api/v1/projects` — find the project to work in.
2. `POST /api/v1/tasks` — create a task with the plan (Concept starts
   automatically).
3. Poll `GET /api/v1/tasks/{task}` until `workflow_status` reaches
   `concept_review`.
4. `POST /api/v1/tasks/{task}/implement` — run the implementation; poll again.
5. `POST /api/v1/tasks/{task}/pr` — open the pull request; poll for `pr_url`.
6. `POST /api/v1/tasks/{task}/feedback` — send review feedback if needed.

## Responses and errors

- Successful reads return `200` with the resource wrapped in a `data` envelope
  (single object) or `data` array (collections).
- Write actions that kick off an asynchronous phase return `202 Accepted` with
  the task in the `data` envelope. The work runs in the background — re-read the
  task to follow it.
- Errors return a JSON body with a `message` field and an appropriate HTTP
  status.

| Status | Meaning |
| --- | --- |
| `401` | Missing or invalid token. |
| `403` | Token lacks the required ability for this endpoint. |
| `404` | Resource not found — or hidden from a project-scoped token. |
| `409` | Conflicting state, e.g. a phase is already running, the task is completed, or a required prior phase has not completed. |
| `422` | Validation error (missing/invalid fields). |

A `409` conflict example:

```json
{
  "message": "A phase is already running for this task."
}
```

A `422` validation example:

```json
{
  "message": "The name field is required.",
  "errors": {
    "name": ["The name field is required."]
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
  surface onto the same task workflow, for driving Argos from an AI agent.
