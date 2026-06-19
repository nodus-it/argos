# Coding Agents and Credentials

When Argos runs a task, the thinking and the typing are done by a **coding
agent** — a large-language-model CLI that runs inside the worker container,
reads your repository, proposes a concept, writes the implementation, and
prepares the pull request. Argos does not embed a model of its own; it drives
an agent you authenticate.

This document explains, in plain terms, which agents Argos supports, how each
one authenticates, where you manage those credentials, and how a project or an
individual task picks the agent that runs it. It is written for a user setting
up Argos — you do not need to read the worker source to follow it.

## Contents

- [What an agent is in Argos](#what-an-agent-is-in-argos)
- [Claude Code](#claude-code)
- [OpenAI Codex](#openai-codex)
- [Agent credentials](#agent-credentials)
- [Adding a credential during onboarding](#adding-a-credential-during-onboarding)
- [Managing credentials later](#managing-credentials-later)
- [Credential status](#credential-status)
- [How a project selects its agent](#how-a-project-selects-its-agent)
- [Per-task overrides](#per-task-overrides)
- [Model selection](#model-selection)
- [Security](#security)

## What an agent is in Argos

An **agent** is the combination of a coding model and the command-line tool
that drives it, installed into the worker image and executed for each phase of
a task (concept, implement, push). Argos ships support for two agents:

| Agent | Identifier | CLI | Distribution |
|---|---|---|---|
| Claude Code | `claude-code` | `claude` | `@anthropic-ai/claude-code` (npm) |
| OpenAI Codex | `codex` | `codex` | `@openai/codex` (npm) |

Both agents require a worker stack that provides Node — the CLI is installed
into the image at build time. See [WORKER-STACKS.md](WORKER-STACKS.md) for how
the agent is baked into the runnable image.

Each agent needs **its own kind of credential**, described below. The
credential is stored in Argos and handed to the worker container as
environment variables only at run time — never written into the image and
never logged.

## Claude Code

Claude Code is the default agent. Its key advantage: it authenticates with the
**OAuth token from your Claude subscription** (Pro, Max, or Team), *not* a
metered API key. Work done by Argos is billed against your existing Claude
plan, not as separate per-token API usage.

To get a token:

1. Run `claude setup-token` in a terminal where the Claude CLI is logged in.
2. Copy the token it prints.
3. Paste it into Argos (during onboarding or on the Agent Credentials page).

Argos stores the token and passes it to the worker as the
`CLAUDE_CODE_OAUTH_TOKEN` environment variable. When you save the token in the
onboarding wizard, Argos validates it against the Anthropic API before
accepting it; if the API is unreachable the token is still saved but marked as
unvalidated.

Claude Code offers three models, selectable per phase (see
[Model selection](#model-selection)):

| Model | Default phase |
|---|---|
| Claude Opus 4.7 | concept |
| Claude Sonnet 4.6 | implement |
| Claude Haiku 4.5 | commit message |

## OpenAI Codex

Codex is the alternative agent, for users who prefer OpenAI's models or already
have a ChatGPT plan that includes Codex.

Codex authenticates with the contents of its `auth.json` file:

1. Run `codex login` locally and sign in (with ChatGPT, or an `OPENAI_API_KEY`).
2. Open `~/.codex/auth.json` and copy its entire contents.
3. Paste the JSON into Argos.

Argos stores the JSON encrypted and, at run time, recreates the `auth.json`
file inside the worker container (delivered via the `CODEX_AUTH_JSON_CONTENT`
environment variable, which the worker writes to disk and then clears before
any phase runs). Unlike Claude Code, Codex has **no fallback** — a task that
resolves to Codex fails immediately if no Codex credential is configured.

Codex currently exposes a single model, GPT-5 Codex, used for every phase.

## Agent credentials

An **agent credential** is a stored record that pairs one agent with one set of
authentication material:

- **Agent** — Claude Code or OpenAI Codex.
- **Description** — a free-text name to tell credentials apart, e.g. "Personal"
  or "Team account".
- **Secret** — for Claude Code, the OAuth token; for Codex, the `auth.json`
  contents. Stored encrypted in the database.
- **Status** — Active, Expired, or Revoked (see
  [Credential status](#credential-status)).
- **Last validated** — when the secret was last checked.

You can store more than one credential per agent (for example, a personal and a
team Claude subscription) and choose which one a task uses.

## Adding a credential during onboarding

The first step of the onboarding wizard (`${APP_URL}/admin/onboarding`) is
"Agents". You must authenticate **at least one** agent before you can continue
to connect a repository.

- For **Claude Code**, paste the output of `claude setup-token`. Argos
  validates it and saves it as a credential named "Default".
- For **OpenAI Codex**, run `codex login`, then paste the contents of
  `~/.codex/auth.json`. Argos validates that it is well-formed JSON and saves it.

You can add or change agents later — onboarding only needs one to get you to a
working project. See [SETUP.md](SETUP.md) for the full first-run walkthrough.

## Managing credentials later

After onboarding, manage every credential on the **Agent Credentials** page
under the *Worker* navigation group (`${APP_URL}/admin/agent-credentials`).
There you can:

- Add new credentials for either agent.
- Edit the description, secret, or status of an existing credential.
- Filter the list by agent or status.
- Delete credentials you no longer need.

The form shows the right secret field for the chosen agent: a revealable token
input for Claude Code, or a multi-line `auth.json` paste box for Codex.

## Credential status

Each credential carries one of three statuses:

| Status | Meaning |
|---|---|
| Active | Usable. Only Active credentials are picked automatically for a task. |
| Expired | Kept for the record but no longer valid; not auto-selected. |
| Revoked | Disabled; not auto-selected. |

When a task does not name a credential explicitly, Argos uses the **first
active credential** for the resolved agent (ordered by creation date).

## How a project selects its agent

Each project (repo profile) can set a default agent in its **Worker** settings.
If a project leaves the agent unset, tasks fall back to **Claude Code**. The
project default also drives which models and credentials are offered for its
tasks. See [PROJECTS.md](PROJECTS.md) for project configuration.

The effective agent for a task is resolved in this order:

1. The task's agent override, if set.
2. The project's default agent, if set.
3. Claude Code.

## Per-task overrides

On a task's **Worker** tab you can override the inherited defaults for that one
task:

- **Agent (Override)** — run this task with a different agent than the project
  default. Leaving it empty uses the project default. Changing the agent clears
  any previously chosen credential and pinned models, because they belonged to
  the old agent.
- **Agent Credential** — which stored credential the agent uses. Leaving it
  empty uses the first active credential for the resolved agent.

## Model selection

For agents that offer more than one model (Claude Code), you can pin the model
per phase — at the project level or, more narrowly, per task:

- **Concept model** — used while the agent works out the approach.
- **Implement model** — used while the agent writes the change.

The resolution order for each phase is: task override → project default →
the agent's built-in default for that phase. Leaving both selectors empty uses
the agent default (for Claude Code: Opus 4.7 for concept, Sonnet 4.6 for
implement). Codex offers a single model, so model selection has no effect for it.

## Security

- Secrets (Claude OAuth tokens, Codex `auth.json`) are stored **encrypted** in
  the database.
- Tokens are **never logged**, not even for diagnostics.
- Credentials are passed to the worker container only as environment variables
  at run time, and Codex's `auth.json` env-var is cleared on disk before any
  phase script runs. Nothing secret is baked into the worker image.

For an overview of how the worker, manager, and credentials fit together, see
[OVERVIEW.md](OVERVIEW.md).
