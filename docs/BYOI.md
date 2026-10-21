# Custom Worker Image (BYOI)

BYOI ("bring your own image") lets a workspace run on the repository's **own
worker base image**, defined in one file — `.argos/worker.dockerfile`. Argos
builds that file as the stack layer and adds the Claude Code CLI, its gate
helpers and the workspace layer on top. Use it when none of the
[built-in stacks](WORKER-STACKS.md#the-built-in-stacks) (PHP 8.5, PHP 8.4,
Node 22) carries the toolchain the project needs.

You rarely write it by hand: the [setup task](PREPARE-PROJECT.md#the-setup-task)
writes `.argos/worker.dockerfile` when no built-in stack fits, and **Test
setup** builds and checks it. This page is the reference for the file.

- [When to use it](#when-to-use-it)
- [The file](#the-file)
- [Requirements the image must satisfy](#requirements-the-image-must-satisfy)
- [Selecting BYOI](#selecting-byoi)
- [How the image is built](#how-the-image-is-built)
- [Rebuilds](#rebuilds)
- [Troubleshooting](#troubleshooting)

## When to use it

Reach for BYOI only when the built-in stacks don't fit:

- another **runtime** (Python, Go, Ruby, a native toolchain),
- a **system package** or PHP extension the build or tests cannot do without,
- a **pinned base image** the project must match,
- a service the workspace has no sidecar for (e.g. Postgres) that must run
  inside the container.

You do **not** need BYOI for:

- **private Composer registries** or other secrets — Settings → Environment →
  *Environment & secrets* (see
  [Preparing a Repository](PREPARE-PROJECT.md#private-registries-and-secrets)),
- **MySQL/MariaDB or Redis** — the backing services of the workspace.

## The file

`.argos/worker.dockerfile` is read through the Git provider API (GitHub,
GitLab, Bitbucket) at the workspace's **setup ref**: the default branch, or —
while a tested setup is not merged yet — the setup task's branch. It is a
normal Dockerfile that defines **only the base**: no `ENTRYPOINT`/`CMD`, no
agent install.

```dockerfile
# .argos/worker.dockerfile — base image for the Argos worker.
# Argos layers the agent CLI, gate helpers and workspace tools on top; provide only the base.
FROM python:3.12-bookworm

# Tools the worker harness requires, plus Node for the agent CLI.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git jq curl ca-certificates gnupg sed grep gawk \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Your project's toolchain goes here, e.g.:
# RUN pip install --no-cache-dir poetry

# Argos runs the workspace as this user.
RUN useradd --create-home --shell /bin/bash --uid 1000 agent
```

## Requirements the image must satisfy

Right after the build, Argos smoke-tests the image with `command -v` for every
required tool. If one is missing, the build is marked **Failed** and the image
is **untagged**, so a broken image is never reused.

- On `PATH`: `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl`, and the
  agent's CLI `claude` — Argos installs it, but your base **must provide
  Node.js / npm** for that.
- A non-root user **`agent` with UID `1000`**.
- **No `ENTRYPOINT`/`CMD`**, **no agent install**.
- **No `COPY` from the repository** — the build context is not your checkout.
  Everything comes in via `FROM` and `RUN`.
- Whatever your project needs to install dependencies and run its tests.

## Selecting BYOI

- **Automatically:** when the setup task's **Test setup** passes with a
  `.argos/worker.dockerfile` on its branch, the workspace switches to it.
- **By hand:** Settings → Environment → Worker → **Image source** →
  *Own Dockerfile in the repo (BYOI)* (stored as `byoi`; the default is
  `standard`, *Registered stack*). The stack picker is hidden then.

Commit the file to the default branch (or test it through the setup task)
**before** switching by hand — otherwise the next container start fails (see
[Troubleshooting](#troubleshooting)). Backing services work with BYOI exactly
as with a built-in stack.

## How the image is built

When the workspace starts and its image does not exist yet:

1. Argos fetches `.argos/worker.dockerfile` at the setup ref.
2. The file is recorded as the stack `byoi-<workspace-id>` and built into a
   **stack image**, tagged by the content hash of the Dockerfile.
3. The worker image is built on top: the agent's CLI and the gate helpers.
4. The smoke test runs; on success the image is ready, on failure it is
   untagged and the start fails.
5. The workspace image (daemon + editor) is built on top.

**Test setup** runs steps 1–4 for the setup task's branch and reports the
build log in the task.

## Rebuilds

The image tag folds in the Dockerfile's hash, the agent version and a
fingerprint of the gate helpers, so a new image is built on the next start
whenever

- `.argos/worker.dockerfile` changes at the setup ref (commit it — through a
  task's pull request, or the setup task),
- the agent's pinned version changes, or
- Argos ships new gate helpers.

**Rebuild** (Settings → Environment → Setup) builds the image again on demand;
the workspace uses it from its next start.

## Troubleshooting

**"BYOI is enabled for '<workspace>' but '.argos/worker.dockerfile' was not
found on '<branch>'."**
The file is missing or empty at the setup ref. Commit a non-empty file to the
default branch, or switch the image source back to *Registered stack*.

**"Worker image validation failed" listing `MISSING <tool>`.**
Add the missing package with `RUN apt-get install …`. A missing `claude`
usually means **Node.js isn't installed**, so the CLI's npm install could not
run — add Node 22.

**A `COPY` step fails or copies unexpected files.**
The build context is not your checkout. Fetch what you need in a `RUN`.

**Permission errors / the workspace behaves as root.**
Create the user: `RUN useradd --create-home --shell /bin/bash --uid 1000 agent`.

**Changes don't take effect.**
The image is keyed on the file at the setup ref — an uncommitted change or a
commit on another branch is not seen. The running container keeps its image
until it restarts; stop and start the workspace.
