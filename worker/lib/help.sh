#!/usr/bin/env bash
# lib/help.sh — help texts for the agent CLI.
#
# Convention: one help_<command> function per top-level command, each
# printing a short block to stdout. help_main prints the overview and
# help_show <command> dispatches to the right function.

# shellcheck shell=bash

help_main() {
    cat <<'HELPDOC'
agent — Claude Worker CLI

USAGE
    agent <command> [task-id] [flags]

SETUP
    init                         build image, set up token, optional symlink

TASK LIFECYCLE
    task new <task-id>           create volume + credentials.env, prompt for description
    task list                    list all tasks with status
    task show <task-id>          show task config (without token)
    task delete <task-id>        alias for `agent abort`

PHASES
    concept   <task-id> [--fresh]
    implement <task-id> [--fresh|--continue] [--max-turns=N]
    diff      <task-id> [--stat] [--file=<path>]
    push      <task-id> [--auto-cleanup|--keep]

INSPECTION & EDITING
    show-concept   <task-id>
    edit-concept   <task-id>
    show-notes     <task-id>
    edit-notes     <task-id>
    logs           <task-id> [--phase=<phase>] [--iteration=N]
    shell          <task-id>     interactive bash in the worker, volume mounted
    status         [<task-id>]   show phase iterations and current_status

CLEANUP
    abort          <task-id>     remove volume and ~/.agent/tasks/<id>
    prune                        find and clean orphaned volumes

HELP
    help                         this overview
    help <command>               detailed help block for one command

More: README.md, docs/EXAMPLE.md, docs/EXTENDING.md
HELPDOC
}

help_init() {
    cat <<'HELPDOC'
agent init — set up from zero to ready.

What it does:
  1. Build the worker image (`docker compose build worker`).
  2. Create the persistent caches (`composer_cache`, `npm_cache`).
  3. Read the Claude OAuth token (hidden input) and store it under
     ~/.agent/claude_oauth_token (mode 600).
  4. Optional: symlink ~/.local/bin/agent -> ./agent.

Flags:
  --update-token       refresh only the token, no rebuild.
HELPDOC
}

help_task() {
    cat <<'HELPDOC'
agent task <subcommand> [task-id]

Subcommands:
  new <task-id>      Interactive prompts (REPO_URL, REPO_TOKEN hidden,
                     BASE_BRANCH, task description in $EDITOR). Creates the
                     volume task_ws_<task-id> and writes
                     ~/.agent/tasks/<task-id>/credentials.env (mode 600).
  list               All known tasks with current_status per phase.
  show <task-id>     REPO_URL, BASE_BRANCH, feature_branch and phase status
                     (REPO_TOKEN is never printed).
  delete <task-id>   Alias for `agent abort`.
HELPDOC
}

help_concept() {
    cat <<'HELPDOC'
agent concept <task-id> [--fresh]

Concept phase: analyse the task, draft a plan.
Output: /workspace/.agent/concept.md (in the volume).

Default: incremental refinement — the previous concept and concept.notes.md
are read in.
--fresh: ignore existing concept; the prior version is moved into
         concept.history/.
HELPDOC
}

help_implement() {
    cat <<'HELPDOC'
agent implement <task-id> [--fresh|--continue] [--max-turns=N]

Implement phase: apply the code changes.
Default --fresh: git reset --hard origin/<base-branch>, git clean -fd,
                 toolchain setup (composer install, npm ci if present),
                 then the Claude session.
--continue:      no reset; builds on existing uncommitted changes.
--max-turns=N:   override Claude max-turns (default 50).

Quality gates: Claude runs Pint and Pest/PHPUnit itself.
The worker re-checks afterwards — on failure: status=quality_gate_failed.
HELPDOC
}

help_diff() {
    cat <<'HELPDOC'
agent diff <task-id> [--stat] [--file=<path>]

Read-only: prints `git diff origin/<base-branch>...HEAD` from the workspace.
--stat:           summary (files, insertions, deletions).
--file=<path>:    just one file.
HELPDOC
}

help_push() {
    cat <<'HELPDOC'
agent push <task-id> [--auto-cleanup|--keep]

Generates the commit message via a Claude sub-phase, commits, and pushes the
feature branch. Then prompts for cleanup (default: no).
--auto-cleanup:  delete volume + host state without asking.
--keep:          keep everything without asking.
HELPDOC
}

help_show_concept() { echo "agent show-concept <task-id> — print /workspace/.agent/concept.md (paged on a TTY)."; }
help_edit_concept() { echo "agent edit-concept <task-id> — open concept.md in \$EDITOR (copy in/out)."; }
help_show_notes()   { echo "agent show-notes <task-id> — print concept.notes.md."; }
help_edit_notes()   { echo "agent edit-notes <task-id> — open concept.notes.md in \$EDITOR."; }
help_logs()         { echo "agent logs <task-id> [--phase=<phase>] [--iteration=N] — print phase logs."; }
help_shell()        { echo "agent shell <task-id> — interactive bash in the worker, volume mounted."; }
help_status()       { echo "agent status [<task-id>] — phase iterations and current_status."; }
help_abort()        { echo "agent abort <task-id> — remove the volume and ~/.agent/tasks/<id>."; }
help_prune()        { echo "agent prune — interactively remove orphaned volumes (no host side)."; }

# help_show: dispatch to the matching help block.
# Args: $1=command (empty for main)
help_show() {
    local cmd="${1:-}"
    case "$cmd" in
        ""|help|--help|-h)    help_main ;;
        init)                 help_init ;;
        task)                 help_task ;;
        concept)              help_concept ;;
        implement)            help_implement ;;
        diff)                 help_diff ;;
        push)                 help_push ;;
        show-concept)         help_show_concept ;;
        edit-concept)         help_edit_concept ;;
        show-notes)           help_show_notes ;;
        edit-notes)           help_edit_notes ;;
        logs)                 help_logs ;;
        shell)                help_shell ;;
        status)               help_status ;;
        abort)                help_abort ;;
        prune)                help_prune ;;
        *)
            echo "Kein Help-Eintrag fuer '$cmd'." >&2
            help_main
            ;;
    esac
}
