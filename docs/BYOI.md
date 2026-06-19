# Bring Your Own Image (BYOI)

BYOI lets a project supply its **own worker base image** through a single file
in the repository — `.argos/worker.dockerfile`. Argos reads that file, builds it
into the worker, and layers the agent CLI and its own worker scripts on top.
Use it when the built-in worker stacks don't carry the toolchain your project
needs.

This page is for operators / project owners configuring a repo profile. For the
full project-preparation walkthrough (execution environment *and* live demo),
see [PREPARE-PROJECT.md](PREPARE-PROJECT.md#part-a--execution-environment) — this
page is the focused reference for the worker-image side.

- [What BYOI is](#what-byoi-is)
- [When to use it (vs. a registered stack)](#when-to-use-it-vs-a-registered-stack)
- [The file you add to the repo](#the-file-you-add-to-the-repo)
- [Requirements the image must satisfy](#requirements-the-image-must-satisfy)
- [Selecting BYOI in the project form](#selecting-byoi-in-the-project-form)
- [How the image is built and layered](#how-the-image-is-built-and-layered)
- [How rebuilds are triggered](#how-rebuilds-are-triggered)
- [Troubleshooting](#troubleshooting)

## What BYOI is

For every task, Argos runs the agent inside a single ephemeral worker container.
That container's image is built in layers:

1. **Stack base** — the OS + language toolchain (PHP, Node, system packages).
2. **Agent layer** — the agent CLI (Claude Code / Codex), installed via npm.
3. **Worker code** — Argos's own phase scripts, libs, prompts, and schemas.

BYOI replaces **only the stack base (layer 1)**. The agent layer and the worker
code are still added by Argos automatically. The repo supplies the base recipe
as `.argos/worker.dockerfile`; everything else is unchanged.

Argos reads the file through the Git provider API (GitHub, GitLab, Bitbucket) at
the task's base branch — it does **not** need to clone the repo to detect or
read it. The file content is then built into a stack image and tagged by its
content hash, so it flows through the same on-demand build / caching pipeline as
the built-in stacks.

## When to use it (vs. a registered stack)

The default worker stack (`php-8.4`) is PHP 8.4 CLI with the common extensions,
Composer, Git, `gh`, `jq`, and Node 22. A `php-8.3` stack also exists. These are
the **registered stacks** you pick under *Image source → Registered stack*.

Reach for BYOI only when the registered stacks genuinely don't fit:

- An **exotic runtime** the stacks don't carry (Python, Go, Ruby, a native
  toolchain).
- A **system package** your build or test suite needs that isn't in the stack.
- A **pinned base image** your project must match.

You do **not** need BYOI for:

- **Private Composer registries** or other secrets — configure those on the repo
  profile under *Worker → Environment & secrets*. See
  [PREPARE-PROJECT.md](PREPARE-PROJECT.md#private-registries--secrets-no-contract-file).
- **MySQL/MariaDB or Redis** during tests — toggle them as backing services in
  the Worker tab; they come up alongside the worker on a private network.

If a single extra package is all that's missing, a three-line BYOI Dockerfile is
often still cheaper than contorting the project — but consider Option 2 (adjust
the project to fit the default) first; see
[PREPARE-PROJECT.md](PREPARE-PROJECT.md#option-2--adjust-the-project).

## The file you add to the repo

Add **`.argos/worker.dockerfile`** at the repository's base branch (the project's
default branch, or the branch a task starts from). Argos reads it from that ref
via the provider API.

It is a normal Dockerfile, but it defines **only the base** — do not add an
`ENTRYPOINT`/`CMD`, and do not install the agent CLI yourself. Argos appends
those layers.

```dockerfile
# .argos/worker.dockerfile — base image for the Argos worker.
# Argos layers the agent CLI + worker scripts on top; provide only the base.
FROM python:3.12-bookworm

# Tools the worker harness requires, plus Node for the agent CLI.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git jq curl ca-certificates gnupg sed grep gawk \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Your project's toolchain goes here, e.g.:
# RUN pip install --no-cache-dir poetry

# Argos runs the worker as this user.
RUN useradd --create-home --shell /bin/bash --uid 1000 agent
```

## Requirements the image must satisfy

Right after the build, Argos smoke-tests the finished worker image: it runs
`command -v` for every required tool inside the container. If any is missing, the
build is marked **Failed** and the image is **untagged** (so a broken image is
never silently reused on the next run).

Required on `PATH`:

- `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl`
- the agent's CLI binary (for Claude Code this is `claude`) — this comes from the
  agent layer Argos installs, so you don't install it, but your base **must
  provide Node.js / npm** for that install to succeed.

Further expectations:

- A non-root user **`agent` with UID `1000`** — the worker runs as this user.
- **No `ENTRYPOINT`/`CMD`** and **no agent install** in your file — Argos owns
  those layers.
- **No `COPY` from the repo.** The build context is not your repository
  checkout, so a `COPY ./something` will fail or pull the wrong tree. Everything
  your base needs must come in via `FROM` and `RUN` (package installs, downloads).
- Whatever **your** project needs to install dependencies and run its tests
  (e.g. the language runtime, a DB client).

## Selecting BYOI in the project form

In the project's edit form, open the **Worker** tab → the **Worker (Stack &
Agent)** section. The relevant field is **Image source** (`worker_source`):

| Option (label) | Meaning |
| --- | --- |
| **Registered stack** | Use a built-in / registered worker stack (default). |
| **Own Dockerfile in the repo (BYOI)** | Read `.argos/worker.dockerfile` from the repo. |

When you choose the BYOI option:

- An info callout appears — *"Repo defines its own image"* — restating that
  Argos reads `.argos/worker.dockerfile` from the base branch and layers the
  agent + worker code on top.
- The **Worker stack** dropdown (`worker_stack_id`) is **hidden** — the repo
  defines the base, so there is nothing to pick.

The **Worker agent** (`worker_agent_name`) and backing-service toggles still
apply with BYOI exactly as with a registered stack.

Make sure `.argos/worker.dockerfile` is committed to the branch Argos reads
(the base branch) **before** starting a task — otherwise the task fails (see
Troubleshooting).

## How the image is built and layered

On the first phase run of a BYOI task:

1. Argos fetches `.argos/worker.dockerfile` from the repo via the provider API,
   at the task's base branch (falling back to the project's default branch).
2. The file body is recorded as a worker stack named `byoi-<profile-id>` and
   built into a **stack image**, tagged by the content hash of the Dockerfile.
3. Argos builds the final worker image from that stack base via
   `Dockerfile.compose`, which:
   - installs the selected agent's CLI on top (its own cache layer), and
   - copies in the worker scripts (`worker/lib`, `worker/phases`,
     `worker/prompts`, `worker/schemas`) and the entrypoint.
4. The smoke test (above) runs; on success the image is tagged ready, on failure
   it is untagged and the task fails.

The final worker image tag is content-addressed: it folds in the BYOI
Dockerfile's hash, the agent name + pinned version, and a fingerprint of Argos's
own worker code. Identical inputs reuse the cached image; any change produces a
new tag and a fresh build.

## How rebuilds are triggered

There is no manual "rebuild" button — rebuilds are driven by the content-hashed
tags. A new worker image is built automatically whenever:

- **You change `.argos/worker.dockerfile`** in the repo (the stack hash changes
  → new tag → rebuild on the next phase run). Commit the change to the branch the
  task runs against.
- **You switch the agent** (or its pinned version changes) — the agent layer is
  part of the tag.
- **Argos ships new worker code** — the worker-code fingerprint is part of the
  tag.

Because the tag is keyed on the **content** of `.argos/worker.dockerfile`, the
trigger is the commit, not a UI action: push the updated file to the base branch
and the next task picks it up.

## Troubleshooting

**"BYOI is enabled for '<project>' but '.argos/worker.dockerfile' was not found
on '<branch>'."**
The file is missing (or empty) on the branch Argos read — the task's base branch,
or the project default branch if the task didn't set one. Commit a non-empty
`.argos/worker.dockerfile` to that branch and retry.

**Build fails with "Worker image validation failed" listing `MISSING <tool>`.**
Your base image is missing one of the required tools (`bash`, `sh`, `jq`, `git`,
`sed`, `grep`, `awk`, `curl`, or the agent's `claude` binary). Add the missing
package via `RUN apt-get install ...` (or the equivalent for your base). A
missing `claude` usually means **Node.js isn't installed** in the base, so the
agent's npm install couldn't run — add Node 22. The broken image is untagged
automatically; fixing the Dockerfile and retrying triggers a clean rebuild.

**`COPY` step fails or copies unexpected files.**
The build context is not your repo checkout. Remove any `COPY` from the repo and
fetch what you need over the network in a `RUN` instead.

**The worker behaves as if running as root / permission errors.**
Ensure the `agent` user exists with UID `1000`
(`RUN useradd --create-home --shell /bin/bash --uid 1000 agent`). Argos runs the
worker as that user.

**Changes to `.argos/worker.dockerfile` don't seem to take effect.**
Confirm the change is committed to the branch the task actually runs against
(the base branch). The image is keyed on the file's content at that ref — an
uncommitted local change, or a commit on a different branch, won't be seen.
