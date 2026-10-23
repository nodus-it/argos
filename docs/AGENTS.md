# Coding Agents and Credentials

When you work in a workspace, the thinking and the typing are done by a
**coding agent** — a large-language-model CLI that runs inside the workspace
container, reads your repository, plans, writes the change and commits it.
Argos does not embed a model of its own; it drives an agent you authenticate.

This document explains which agent Argos supports, how it authenticates, where
you manage those credentials, and how a task picks the agent and model it
runs on. It is written for a user setting up Argos — you do not need to read
the daemon source to follow it.

## Contents

- [What an agent is in Argos](#what-an-agent-is-in-argos)
- [Claude Code](#claude-code)
- [Agent credentials](#agent-credentials)
- [Adding a credential during onboarding](#adding-a-credential-during-onboarding)
- [Managing credentials later](#managing-credentials-later)
- [Credential status](#credential-status)
- [Personal and shared agents](#personal-and-shared-agents)
- [Model selection](#model-selection)
- [Adding more agents](#adding-more-agents)
- [Security](#security)

## What an agent is in Argos

An **agent** is the combination of a coding model and the command-line tool
that drives it. The CLI is installed into the worker image (the base of every
workspace image); inside the running workspace, the workspace daemon hosts the
agent's sessions — one per task, in the task's worktree.

| Agent | Identifier | CLI | Distribution |
|---|---|---|---|
| Claude Code | `claude-code` | `claude` | `@anthropic-ai/claude-code` (npm) |

The agent needs a stack that provides Node — all built-in stacks do, and a
repository's own `.argos/worker.dockerfile` must. The CLI is installed into
the image at build time; see [Stacks and Images](WORKER-STACKS.md).

## Claude Code

Claude Code authenticates with the **OAuth token from your Claude
subscription** (Pro, Max, or Team), *not* a metered API key. Work done in
Argos is billed against your existing Claude plan, not as separate per-token
API usage.

To get a token:

1. Run `claude setup-token` in a terminal where the Claude CLI is logged in.
2. Copy the token it prints.
3. Paste it into Argos (in the setup wizard or under **My account → Claude
   subscriptions**).

When you save the token, Argos validates it against the Anthropic API before
accepting it; if the API is unreachable the token is still saved but marked as
unvalidated.

## Agent credentials

An **agent credential** (shown as an *agent*) is a stored record that pairs
the agent with one set of authentication material:

- **Agent** — Claude Code.
- **Name** — a free-text name to tell agents apart, e.g. "Personal" or
  "Team account".
- **Token** — the OAuth token. Stored encrypted in the database.
- **Status** — Active, Expired, or Revoked (see
  [Credential status](#credential-status)).
- **Owner**, **Parallel sessions** and **Default model** — see
  [Personal and shared agents](#personal-and-shared-agents).
- **Last validated** — when the token was last checked.

You can store more than one agent (for example, a personal and a team Claude
subscription) and choose which one a task uses.

## Adding a credential during onboarding

The agent step of the setup wizard (`${APP_URL}/setup`) asks
for a Claude token. You must authenticate the agent before you can continue to
connect a repository. Paste the output of `claude setup-token`; Argos
validates it and saves it as a shared agent named "Default".

See [SETUP.md](SETUP.md) for the full first-run walkthrough.

## Managing credentials later

After onboarding, manage agents under **My account → Claude subscriptions**
(`${APP_URL}/account/agents`). There you can:

- Add new agents.
- Edit the name, token, status, owner or limits of an existing agent.
- Filter the list by agent or status.
- Delete agents you no longer need.

## Credential status

Each agent carries one of three statuses:

| Status | Meaning |
|---|---|
| Active | Usable for new tasks. |
| Expired | Kept for the record but no longer valid; not offered. |
| Revoked | Disabled; not offered. |

## Personal and shared agents

An agent is either **personal** (it has an owner and runs on that person's
subscription) or **shared** (no owner).

- Every user can add their **own** Claude agent under *My account → Claude
  subscriptions* — paste the output of `claude setup-token`; the profile
  (`/account/profile`) lists them too. Users only see their own agents.
- Administrators see all agents and can assign an owner, or leave it empty for
  a shared agent.
- A **task** runs on the agent its owner picked when creating it — one of
  their own or a shared one (see [Tasks](TASKS.md)). Messages of
  collaborators run on it too. Handing a task over moves it to an agent of the
  new owner (their own first). Automatic tasks started through the API, MCP
  or an issue trigger use the first usable agent of the person they act for.
- **Parallel sessions** (administrators) limits how many tasks may talk to
  Claude on one agent at the same time (default `1`); further messages wait in
  the [session queue](TASKS.md#session-queue). The workspace has its own limit
  on top. When Claude reports that the
  subscription hit its usage limit, the agent shows *Usage limit until …* and
  starts no new sessions before then.

## Model selection

Claude Code reports which models the subscription may use when a session
starts; Argos remembers that list per agent and offers it when you create a
task. Before an agent's first session, the aliases (`sonnet`, `opus`, …)
are offered — they always resolve to the newest model. The agent's **Default
model** preselects the model for new tasks; without one, Argos uses
`sonnet`.

## Adding more agents

Argos ships with Claude Code only, but the seams for another agent (for
example Codex) are in place:

- **Stored credential** — `agent_credentials.agent_name` keeps the agent
  identifier, so credentials for a new agent fit the existing table.
- **Agent enum** — add a case to `App\Enums\AgentName`. Its
  `supportsWorkspaces()` decides whether tasks may run on it; the agent pool
  only offers agents whose case returns `true`.
- **Image** — an `AgentRunner` with its `AgentSpec` (npm package, CLI binary,
  required stack capabilities) and an install script under
  `.tools/docker/worker/agents/`, registered in the `AgentRegistry`. The worker
  image build then installs the CLI.
- **Runtime** — the workspace daemon wraps the agent in `TaskSession`
  behind a `QueryFactory`. A new agent is a second session implementation that
  emits the same events and serves the same HTTP routes, so the manager side
  stays unchanged.

## Security

- Tokens are stored **encrypted** in the database.
- Tokens are **never logged**, not even for diagnostics.
- The token reaches the workspace daemon only in memory, when a task's
  session starts — it is never written to disk, into the container
  environment or into the image.

For an overview of how the workspace, manager, and credentials fit together,
see [OVERVIEW.md](OVERVIEW.md).
