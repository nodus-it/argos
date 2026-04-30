#!/usr/bin/env bash
# lib/help.sh — Help-Texte fuer die agent-CLI.
#
# Convention: pro Top-Level-Command eine help_<command>-Funktion, die einen
# kurzen Block auf stdout ausgibt. help_main() listet die Übersicht.
# help_show <command> dispatcht zur passenden Funktion oder gibt main aus.

# shellcheck shell=bash

help_main() {
    cat <<'HELP'
agent — Claude Worker CLI

USAGE
    agent <command> [task-id] [flags]

SETUP
    init                         Image bauen, Token einrichten, optional Symlink

TASK-LIFECYCLE
    task new <task-id>           Volume + credentials.env anlegen, Description abfragen
    task list                    Alle Tasks mit Status
    task show <task-id>          Konfiguration eines Tasks (ohne Token)
    task delete <task-id>        identisch zu `agent abort`

PHASEN
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
    shell          <task-id>     interaktive Bash im Worker, Volume gemountet
    status         [<task-id>]   Zeigt Phase-Iterationen und current_status

CLEANUP
    abort          <task-id>     Volume und ~/.agent/tasks/<id> entfernen
    prune                        verwaiste Volumes finden + cleanen

HILFE
    help                         diese Übersicht
    help <command>               detaillierter Hilfe-Block fuer einen Command

Mehr Details: README.md, docs/EXAMPLE.md, docs/EXTENDING.md
HELP
}

help_init() {
    cat <<'HELP'
agent init — Setup von 0 auf einsatzbereit.

Was passiert:
  1. Worker-Image bauen (`docker compose build worker`).
  2. Persistente Caches anlegen (`composer_cache`, `npm_cache`).
  3. Claude-OAuth-Token einlesen (versteckte Eingabe), nach
     ~/.agent/claude_oauth_token speichern (mode 600).
  4. Optional: Symlink ~/.local/bin/agent -> ./agent.

Flags:
  --update-token       nur den Token erneuern, kein Rebuild.
HELP
}

help_task() {
    cat <<'HELP'
agent task <subcommand> [task-id]

Subcommands:
  new <task-id>      Interaktive Eingaben (REPO_URL, REPO_TOKEN versteckt,
                     BASE_BRANCH, Task-Description in $EDITOR), legt Volume
                     task_ws_<task-id> an und schreibt
                     ~/.agent/tasks/<task-id>/credentials.env (mode 600).
  list               Alle bekannten Tasks mit current_status pro Phase.
  show <task-id>     Zeigt REPO_URL, BASE_BRANCH, feature_branch und Phase-Status
                     (REPO_TOKEN wird nie ausgegeben).
  delete <task-id>   Identisch zu `agent abort`.
HELP
}

help_concept() {
    cat <<'HELP'
agent concept <task-id> [--fresh]

Konzept-Phase: Aufgabe analysieren, Plan formulieren.
Output: /workspace/.agent/concept.md (im Volume).

Default: inkrementelle Verfeinerung — vorheriges Konzept und
concept.notes.md werden mit-eingelesen.
--fresh: ignoriert vorhandenes Konzept; vorherige Version wandert in
         concept.history/.
HELP
}

help_implement() {
    cat <<'HELP'
agent implement <task-id> [--fresh|--continue] [--max-turns=N]

Implement-Phase: Code-Änderungen umsetzen.
Default --fresh: git reset --hard origin/<base-branch>, git clean -fd,
                Toolchain-Setup (composer install, npm ci falls vorhanden),
                dann Claude-Session.
--continue:     kein Reset; baut auf bestehenden uncommitted Änderungen auf.
--max-turns=N:  override fuer Claude max-turns (default 50).

Quality-Gates: Pint und Pest/PHPUnit fuehrt Claude selbst aus.
Worker prueft danach nochmal — bei rotem Status: status=quality_gate_failed.
HELP
}

help_diff() {
    cat <<'HELP'
agent diff <task-id> [--stat] [--file=<path>]

Read-only: zeigt git diff origin/<base-branch>...HEAD aus dem Workspace.
--stat:           Kurzfassung (files, insertions, deletions).
--file=<path>:    nur ein File.
HELP
}

help_push() {
    cat <<'HELP'
agent push <task-id> [--auto-cleanup|--keep]

Generiert Commit-Message via Claude-Sub-Phase, committed und pusht den
Feature-Branch zur Remote. Fragt danach nach Cleanup (default Nein).
--auto-cleanup:  Volume + Host-State direkt loeschen, ohne Frage.
--keep:          Nichts loeschen, ohne Frage.
HELP
}

help_show_concept() { echo "agent show-concept <task-id> — gibt /workspace/.agent/concept.md aus (mit Pager wenn TTY)."; }
help_edit_concept() { echo "agent edit-concept <task-id> — oeffnet concept.md in \$EDITOR (Copy-In/Out)."; }
help_show_notes()   { echo "agent show-notes <task-id> — gibt concept.notes.md aus."; }
help_edit_notes()   { echo "agent edit-notes <task-id> — oeffnet concept.notes.md in \$EDITOR."; }
help_logs()         { echo "agent logs <task-id> [--phase=<phase>] [--iteration=N] — gibt Phase-Logs aus."; }
help_shell()        { echo "agent shell <task-id> — interaktive Bash im Worker, Volume gemountet."; }
help_status()       { echo "agent status [<task-id>] — Phase-Iterationen und current_status."; }
help_abort()        { echo "agent abort <task-id> — Volume + ~/.agent/tasks/<id> komplett entfernen."; }
help_prune()        { echo "agent prune — verwaiste Volumes (ohne Host-Side) interaktiv entfernen."; }

# help_show: Dispatch auf den richtigen Help-Block.
# Args: $1=command (leer fuer main)
# Returns: 0 immer.
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
