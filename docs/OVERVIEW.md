# How Argos Works

Welcome to Argos. This page is the big picture: what Argos is, the few
concepts you need in your head, and how a piece of work flows from a sentence
you type to a pull request you can review. Read it once, then follow the
links into the detail docs when you need them.

## What is Argos

Argos turns a **task description into a reviewed pull request**. You describe
what you want — a feature, a fix, a refactor — and Argos drafts a concept,
implements it inside an isolated worker container, runs the project's quality
gates, pushes a branch, and opens the pull request. You stay in control: you
review the concept before it's built and the pull request before it's merged.

Two things make Argos different from calling an API in a loop:

- **It runs on your Claude subscription, not the API.** Argos uses the Claude
  Code OAuth token from `claude setup-token`, so your existing Pro / Max /
  Team plan covers the work — there's no per-token API billing.
- **Every task runs in its own throwaway container.** The agent never touches
  your manager host or your other tasks. When the work is done, the container
  is gone.

> Argos is currently tuned for PHP / Laravel projects — the implement phase
> wires up Composer, npm, Pint, and Pest/PHPUnit as quality gates. Other
> stacks work, but the prompts and gates are sharpest for Laravel today.

## The mental model: Manager and Worker

Argos is two halves, and it helps to keep them separate in your mind.

### The Manager — the app you use

The Manager is the web application you log into at `${APP_URL}/admin`. It's
the control room: you connect your accounts here, define projects, create
tasks, watch them run, read the live agent stream, and review the results.
The Manager holds all your data and decides *what* should happen.

Importantly, **the Manager itself never runs the AI.** It has no Claude
session of its own. It orchestrates — it decides when a phase should run and
hands the job to a worker.

### The Worker — the container that does the work

A Worker is an **ephemeral Docker container** that the Manager spins up for a
single phase of a single task. It clones the repository, runs the Claude
agent, executes the quality gates, and reports back. Then it's torn down.

The Worker is deliberately sandboxed: it gets the repository, the credentials
it needs as environment variables, and nothing else. It has **no Docker
socket** and no path back into the Manager. The image it runs in is defined
by a [Worker Stack](WORKER-STACKS.md) — think "the toolbox the agent gets" —
which you can customize per project.

This split is the heart of Argos: a stable, stateful Manager you interact
with, and disposable Workers that do the risky, expensive work in isolation.

## The core flow at a glance

A typical piece of work moves through these steps. Most of it is automatic —
your job is the two review gates.

1. **Connect credentials.** Paste your Claude token and link a Git account
   (or a Personal Access Token). See [Credentials](CREDENTIALS.md) and
   [OAuth Apps](OAUTH.md).
2. **Create a Project.** A Project points Argos at one repository and carries
   its defaults — which branch to start from, which worker stack, which
   models. See [Projects](PROJECTS.md).
3. **Create a Task.** Describe what you want. The **Concept** phase starts
   (drafting a plan and creating the feature branch), and you get a written
   concept to review.
4. **Argos runs the phases.** After you approve the concept, the
   **Implement** phase writes the code and runs the quality gates, then the
   **Push** phase opens the pull request:

   ```
   Concept  →  Implement  →  Push (Pull Request)
   ```

5. **You review.** Read the diff and the pull request. If something needs
   changing, send feedback and the **Respond** phase reworks the branch — as
   many rounds as you like.

Phases run asynchronously: when you kick one off, the Manager queues the job
and the page updates as the Worker makes progress. For the full walkthrough —
every status, the review docks, retries, and feedback rounds — see
[Tasks](TASKS.md).

## Glossary

A one-line definition of each core concept. Follow the link for the detail.

- **[Project](PROJECTS.md)** — one repository plus its defaults (base branch,
  worker stack, models, auth). Every task belongs to a project.
- **[Task](TASKS.md)** — one unit of work, from description to pull request,
  moving through the phases.
- **[Phase](TASKS.md)** — a single step of a task's life: Concept (plan),
  Implement (write code), Push (open the PR), and Respond (rework on
  feedback). Each phase is one isolated worker run.
- **[Worker Stack](WORKER-STACKS.md)** — the Docker image definition a worker
  runs in: the base image plus the tools the agent gets. Built on demand,
  customizable per project (drop a `.argos/worker.dockerfile` for full
  control).
- **[Agent](AGENTS.md)** — the AI coding tool the worker drives inside the
  container (Claude Code is the default).
- **[Credential](CREDENTIALS.md)** — a stored secret Argos needs: your Claude
  token to run the agent, and a Git token to read and write the repository —
  either a **Personal Access Token (PAT)** or a full **OAuth** account
  binding (which also unlocks repo/branch pickers). See [OAuth Apps](OAUTH.md).
- **[Task Provider](SETUP-TASK-PROVIDERS.md)** — a connection to an issue
  tracker (GitHub, GitLab, Linear) so you can import issues straight into
  Argos as tasks.
- **[Live Demo](LIVE-DEMOS.md)** — an ephemeral, throwaway deployment of a
  task's implemented branch, so you can click through the change in a browser
  before merging.
- **[MCP / REST API](SETUP-MCP.md)** — drive Argos from outside the web UI:
  from Claude Code via the built-in MCP server, or programmatically via the
  [REST API v1](REST-API.md) with Sanctum bearer tokens.

## Where to next

- **New install?** Start with [Setup](SETUP.md) to finish connecting accounts
  and configuring the stack.
- **Want repo/branch pickers and per-user binding?** Connect an
  [OAuth App](OAUTH.md).
- **Getting a repository ready for Argos?** See
  [Preparing a Project](PREPARE-PROJECT.md) — covers the worker build
  environment and the live-demo contract.
- **Ready to run something?** Head to [Projects](PROJECTS.md), then
  [Tasks](TASKS.md).
- **Automating?** Wire up the [MCP server](SETUP-MCP.md) or the
  [REST API](REST-API.md).
