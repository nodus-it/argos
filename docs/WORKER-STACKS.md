# Worker Stacks and Images

When Argos runs a task, the actual work — cloning your repo, running the
agent, building branches — happens inside a short-lived Docker container.
The environment that container runs in is described by a **Worker Stack**,
and the runnable image is assembled on demand from the stack, an agent, and
the Argos worker code.

This document explains, in plain terms, what a stack is, how images get
built, and how a project chooses the environment its tasks run in. It is
written for an operator picking or managing that environment — you do not
need to read the worker source to follow it.

## Contents

- [What a Worker Stack is](#what-a-worker-stack-is)
- [The three layers of a worker image](#the-three-layers-of-a-worker-image)
- [Built-in, custom, and repo-defined stacks](#built-in-custom-and-repo-defined-stacks)
- [How images build on demand](#how-images-build-on-demand)
- [What triggers a rebuild](#what-triggers-a-rebuild)
- [Where to see image builds](#where-to-see-image-builds)
- [Agent version tracking](#agent-version-tracking)
- [How a project selects its stack](#how-a-project-selects-its-stack)
- [Reference: stack fields](#reference-stack-fields)

## What a Worker Stack is

A Worker Stack is the **base toolchain** the worker runs in — the operating
system plus the languages and tools your project needs to build and test
(for example PHP, Composer, and Node). A stack is, at heart, a Dockerfile
together with some metadata that describes it.

Each stack carries:

- A **slug** (`name`) — a unique identifier, e.g. `php-8.4`.
- A **display name** (`label`) — what you see in the stack pickers on tasks
  and projects, e.g. `PHP 8.4`.
- A **base image** — a reference-only note of the upstream `FROM` image
  (e.g. `php:8.4-cli-bookworm`). The real `FROM` lives inside the Dockerfile;
  this field just documents it for the overview.
- A **Dockerfile body** — the full Dockerfile that becomes the stack image
  at build time. This is the source of truth for what the environment
  actually contains.
- **Capabilities** — tags like `php`, `composer`, `node`. These are not
  cosmetic: an agent checks them before it is allowed to run on a stack
  (Claude Code, for instance, needs `node`). A task on a stack that is
  missing an agent's required capability is refused before any build runs.
- **Common tools** — documentation-only tags for the extra tools installed
  (`git`, `gh`, `jq`, `curl`, …). Informational only; not used for
  validation.
- A **status** — `active`, `deprecated`, or `disabled`. A disabled stack is
  skipped by the resolver even when a task or project still references it.

Manage stacks in the admin panel under the **Worker** navigation group, at
`${APP_URL}/admin/worker-stacks`.

## The three layers of a worker image

The stack is only the bottom layer. The image Argos actually runs is built
in three stacked layers, each cached independently:

1. **Stack layer** — the base toolchain, built from the stack's Dockerfile
   (`FROM php:8.4-…`, plus your tools). Tagged `argos-stack:<name>-<hash>`.
2. **Agent layer** — the coding agent CLI (Claude Code or Codex), installed
   on top of the stack. A change in agent version invalidates only this
   layer. See [Agents](AGENTS.md) for what an agent is and how credentials
   work.
3. **Worker-code layer** — the Argos phase scripts, libraries, prompts, and
   schemas, plus the worker entrypoint. This changes only when you ship a new
   Argos version, so it is cached aggressively.

The final image is tagged
`argos-worker:<stack>-<stackHash>-<libHash>-<agent>-<version>`. You never
build these by hand — the manager assembles them when a task needs them (see
below).

## Built-in, custom, and repo-defined stacks

There are three ways a stack can come into being.

### Built-in stacks (synced automatically)

Argos ships with built-in stacks out of the box — currently **PHP 8.3** and
**PHP 8.4**. These are defined in the Argos source manifest and **synced into
the database automatically after every database migration**. The sync is
idempotent: a stack whose definition hasn't changed is left alone; a changed
definition updates the built-in fields; a built-in that is removed from the
manifest is flipped to `deprecated` (never deleted, so projects that still
reference it keep working).

Built-in stacks are **read-only** in the UI — you cannot edit or delete them.
To customise one, use the **Duplicate** action: it clones the stack into a
fresh editable copy (forced to non-built-in, auto-suffixed name) that the
automatic sync will never overwrite. This is the supported path for "I want
PHP 8.4 but with an extra apt package".

### Custom stacks

A custom stack is any stack you create or duplicate yourself
(`is_builtin = false`). You own its Dockerfile entirely, you can edit and
delete it, and the built-in sync never touches it. Use a custom stack when
your projects need a toolchain the built-ins don't cover (a different runtime,
extra system packages, an in-house base image, …).

### Repo-defined image (BYOI)

A project can also bring its own image definition by shipping a Dockerfile in
the repository itself — **Bring Your Own Image (BYOI)**. In that mode Argos
reads the repo's Dockerfile and uses it as the stack base, then still layers
the agent and worker code on top exactly like a normal stack. This keeps the
environment definition versioned alongside the code it builds. See
[BYOI](BYOI.md) for the file location and the full contract.

## How images build on demand

Worker images are **built lazily, on the first task that needs them** — there
is no manual pre-build step in the normal flow. When a task starts, the
manager:

1. **Resolves** the `(stack, agent)` pair for the task (see
   [How a project selects its stack](#how-a-project-selects-its-stack)) and
   computes the deterministic image tag.
2. **Checks** whether an image with that exact tag already exists. If it does,
   the task uses it immediately.
3. **Builds** it if it is missing: first the stack image (skipped if its
   content hash is already cached), then the worker image (stack + agent +
   worker code).
4. **Validates** the fresh image with a smoke test — every baseline tool
   (`bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl`) plus the agent's
   CLI binary must be present. If any are missing the build is marked
   **failed** and the broken image is untagged, so it can't silently get
   reused.

Because the tag is content-derived, an unchanged environment is built exactly
once and reused for every subsequent task.

## What triggers a rebuild

The worker image tag is a **fingerprint** of everything that goes into the
image. A new image is built whenever any of these three change:

- **The stack** — specifically, a change to the stack's Dockerfile body. The
  tag embeds an 8-character hash of that Dockerfile, so editing it produces a
  new tag and a new build.
- **The worker code** — a fingerprint (`libHash`) over the worker libraries,
  phase scripts, prompts, schemas, and the worker entrypoint/Dockerfile.
  Shipping a new Argos version with changed worker code rebuilds images even
  if the stack and agent are untouched.
- **The agent version** — the agent name and its pinned version are part of
  the tag, so moving the agent to a new version produces a new tag.

If none of these change, the existing image is reused — no rebuild, no wasted
build time.

## Where to see image builds

Every build attempt is recorded as a **Worker Image Build** row, visible in
the admin panel under the Worker group at `${APP_URL}/admin/worker-image-builds`.
Each row shows:

- The full image **tag**, the **stack** and **agent** it was built for.
- A **status** — `queued`, `building`, `ready`, or `failed`.
- The image **size** and **built at** timestamp.
- The full **build log** (stack build + worker layer + validate step) on the
  detail page — the first place to look when a build fails.
- An **Update available** flag marking builds that have drifted out of date
  (see below).

From this screen you can **Rebuild** a single image, or **Rebuild all
outdated** to refresh every drifted image in one action. Builds are not
created by hand — the list is populated by the on-demand build pipeline.

A build is flagged **outdated** when either:

- **Stack drift** — the stack's Dockerfile has changed since the build was
  made (the build's recorded hash no longer matches the current one), or
- **Agent drift** — the agent has an update available and the build predates
  the last version check.

## Agent version tracking

Agents (the CLI tools, Claude Code and Codex) are released independently of
Argos. To keep you aware of new versions, Argos runs a **daily check** (at
03:00, and on demand via `php artisan argos:check-agent-versions`) that polls
the npm registry for each registered agent's package and compares the latest
published version against the version Argos has pinned.

The result is stored per agent and surfaces as an **Update available** signal
in the panel — on the stack/build views and on the worker-updates dashboard
widget. A pin of `latest` always reports an update whenever upstream moves;
a fixed pin reports an update only when the published version differs.

An update signal is informational — nothing rebuilds automatically. When you
want to adopt the new version, use **Rebuild** / **Rebuild all outdated** on
the image-builds screen, and the next build pulls the current agent during
its install step.

## How a project selects its stack

A task resolves which stack and agent to use in this order, first match wins:

1. **Per-task override** — an explicit stack/agent chosen on the individual
   task.
2. **Project (repo profile) setting** — the stack and agent configured on the
   project.
3. **Configured default** — falls back to the default stack
   (`php-8.4` unless overridden via `ARGOS_DEFAULT_STACK`) and the default
   agent (Claude Code).

You set the project-level choice on the project's settings, under the worker
environment options: a **Worker source** (standard stack vs. BYOI), the
**Worker stack** to use, and the **Worker agent**. When the source is BYOI
the stack picker is hidden, because the environment comes from the repo's own
Dockerfile instead. See [Projects](PROJECTS.md) for where these live, and
[Configuration](CONFIGURATION.md) for the `ARGOS_DEFAULT_STACK` default.

## Reference: stack fields

| Field | Meaning |
| --- | --- |
| `name` (Slug) | Unique identifier, e.g. `php-8.4`. Read-only on built-ins. |
| `label` | Human-readable display name shown in pickers. |
| `base_image` | Reference-only note of the upstream `FROM` image. |
| `dockerfile_body` | The full Dockerfile that becomes the stack image. |
| `capabilities` | Tags an agent checks before it may run (e.g. `php`, `node`). |
| `common_tools` | Documentation-only tags for installed tools. Not validated. |
| `status` | `active`, `deprecated`, or `disabled` (disabled = skipped). |
| `is_builtin` | Whether the stack is shipped + synced by Argos (read-only) or yours. |
| `has_update` | Whether an update is available for the stack/agent. |
| `last_built_at` | When an image for this stack was last built. |
