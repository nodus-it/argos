# Stacks and Images

Every workspace runs in one Docker container. Its image is assembled on
demand from a **stack** — the operating system plus the toolchain the project
needs — the Claude Code CLI, the Argos gate helpers and the workspace layer
(daemon and VS Code). A workspace uses either one of the **three built-in
stacks** or the repository's own **`.argos/worker.dockerfile`**. There is no
stack editor: anything the built-ins don't cover goes into the repository's
Dockerfile, which the [setup task](PREPARE-PROJECT.md#the-setup-task) can write
for you.

## Contents

- [The built-in stacks](#the-built-in-stacks)
- [The repository's own image](#the-repositorys-own-image)
- [How a workspace gets its stack](#how-a-workspace-gets-its-stack)
- [The layers of a workspace image](#the-layers-of-a-workspace-image)
- [How images build](#how-images-build)
- [What triggers a rebuild](#what-triggers-a-rebuild)
- [Where to see the image](#where-to-see-the-image)

## The built-in stacks

| Stack | Slug | Base image | Toolchain |
|---|---|---|---|
| **PHP 8.5** (default) | `php-8.5` | `php:8.5-cli-bookworm` | PHP 8.5 CLI, Composer, Node 22 |
| **PHP 8.4** | `php-8.4` | `php:8.4-cli-bookworm` | PHP 8.4 CLI, Composer, Node 22 |
| **Node 22** | `node-22` | `node:22-bookworm` | Node 22 with Corepack (npm, yarn, pnpm) |

The PHP stacks carry the common extensions (`intl`, `pdo_mysql`,
`pdo_sqlite`, `pdo_pgsql`, `zip`, `bcmath`, `gd`, `pcntl`, `sockets`, `exif`,
`redis`) and no CLI memory limit. All stacks bring `git`, `gh`, `jq`, `curl`,
`unzip`, a MySQL client and the non-root user `agent` (UID 1000).

The stacks ship with Argos (`.tools/docker/worker/built-ins.php`) and are
synced into the database after every migration; a stack that is removed from
the manifest is marked deprecated rather than deleted. The default stack is
pre-built when the app boots (`argos:warm-builtin-images`).

## The repository's own image

When no built-in stack fits — another language, a PHP extension or system
package the project cannot work without, a pinned base image — the
repository ships `.argos/worker.dockerfile`. It replaces the **stack layer
only**; Argos still adds the Claude Code CLI, the gate helpers and the
workspace layer on top. The image must provide Node.js (for the CLI), the
user `agent` with UID 1000 and the tools `bash`, `sh`, `jq`, `git`, `sed`,
`grep`, `awk`, `curl`. The full contract is in
[Custom Worker Image (BYOI)](BYOI.md).

## How a workspace gets its stack

1. **On creation** Argos inspects the repository: `composer.json` → PHP 8.5,
   or PHP 8.4 when the PHP constraint pins 8.4 without allowing 8.5;
   `package.json` without `composer.json` → Node 22; a
   `.argos/worker.dockerfile` → the repository's image. With none of them, the
   [setup task](PREPARE-PROJECT.md#the-setup-task) starts.
2. **In the settings** (Settings → Environment → Worker) a workspace admin can
   switch the **Image source** (*Registered stack* or *Own Dockerfile in the
   repo (BYOI)*) and the **Worker Stack**.
3. **A passed setup test** that built a `.argos/worker.dockerfile` switches the
   image source to BYOI.
4. Without a stack set, the installation default applies
   (`ARGOS_DEFAULT_STACK`, default `php-8.5`).

The repository's Dockerfile is read at the workspace's setup ref — the default
branch, or a tested setup branch that is not merged yet.

## The layers of a workspace image

1. **Stack layer** — the built-in stack's Dockerfile, or the repository's
   `.argos/worker.dockerfile`. Tagged `argos-stack:<name>-<hash>`.
2. **Agent layer** — the Claude Code CLI (`@anthropic-ai/claude-code`),
   installed on top. See [Agents](AGENTS.md).
3. **Gate helpers** — the Argos quality-gate scripts (`worker/lib`).

Together these form the **worker image**, tagged
`argos-worker:<stack>-<stackHash>-<libHash>-<agent>-<version>`. On top sits
the **workspace layer** — the daemon and OpenVSCode Server. You never build
these by hand.

## How images build

Images are built lazily when a workspace starts and its image is missing:

1. Argos resolves the stack and computes the deterministic tag.
2. If an image with that tag exists, the workspace uses it.
3. Otherwise it builds the stack image (skipped when its hash is cached), the
   worker image and the workspace image.
4. A smoke test checks that `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`,
   `curl` and the `claude` CLI are present. If anything is missing, the build
   is marked **failed** and the image is untagged, so it is never reused.

All tasks of a workspace share its container and therefore its image. The
first start of a new image takes a few minutes; later starts reuse it.

## What triggers a rebuild

The tag is a fingerprint of everything in the image. A new image is built on
the next workspace start when

- the **stack** changes — a new built-in stack Dockerfile shipped with Argos,
  or a changed `.argos/worker.dockerfile` at the setup ref,
- the **gate helpers** change (a new Argos version), or
- the **agent version** pin changes.

**Rebuild** in Settings → Environment → Setup builds the current stack image
again on demand (e.g. to pick up newer upstream packages); the workspace uses
it from its next start.

## Where to see the image

Settings → Environment → **Setup** shows the current image build of the
workspace (state and age), its **Build log** (stack build, worker layer and
smoke test) and **Rebuild**. A failed **Test setup** shows the build log of
the repository's image in the setup task. There is no global image-build list
in the admin panel.
