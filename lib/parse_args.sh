#!/usr/bin/env bash
# lib/parse_args.sh — Argument-Parser fuer die agent-CLI.
#
# Setzt nach Aufruf folgende globale Variablen (siehe IMPLEMENTATION.md 8.1):
#   ARG_COMMAND          erstes positional, z.B. "concept", "task", "init"
#   ARG_SUBCOMMAND       zweites positional fuer Subcommand-Familien
#                        (aktuell nur "task" hat Subcommands: new|list|show|delete)
#   ARG_TASK_ID          das erste positional nach Command/Subcommand
#   ARG_FLAG_<NAME>      gesetzt fuer jedes long-Flag, "true" fuer Boolean
#                        oder der Wert fuer --flag=value bzw. --flag value
#   ARG_REMAINING        Array der noch nicht zugeordneten positionals
#
# Konvention: Flag-Namen werden zu UPPER_SNAKE umgewandelt:
#   --fresh         -> ARG_FLAG_FRESH=true
#   --max-turns=50  -> ARG_FLAG_MAX_TURNS=50
#   --auto-cleanup  -> ARG_FLAG_AUTO_CLEANUP=true

# shellcheck shell=bash
# shellcheck disable=SC2034
# (ARG_* Variables werden vom Caller gelesen, nicht von dieser Datei.)

# Subcommand-Familien: Commands, deren erstes positional ein Subcommand ist.
PARSE_ARGS_SUBCOMMAND_FAMILIES=(task)

# Long-Flags die einen separaten Wert erwarten (--name value).
PARSE_ARGS_VALUED_FLAGS=(max-turns file phase iteration task-description)

# parse_args: Parsed CLI-Argumente und setzt globale Variablen.
# Args: $@ = vollstaendige Kommandozeile (ohne $0)
# Returns: 0 immer.
parse_args() {
    ARG_COMMAND=""
    ARG_SUBCOMMAND=""
    ARG_TASK_ID=""
    ARG_REMAINING=()

    local v
    for v in $(compgen -v ARG_FLAG_ 2>/dev/null || true); do
        unset "$v"
    done

    local positionals=()
    local arg name value

    while [[ $# -gt 0 ]]; do
        arg="$1"
        case "$arg" in
            --)
                shift
                while [[ $# -gt 0 ]]; do
                    positionals+=("$1")
                    shift
                done
                ;;
            --*=*)
                name="${arg%%=*}"
                value="${arg#*=}"
                _parse_args_set_flag "${name#--}" "$value"
                shift
                ;;
            --*)
                name="${arg#--}"
                if _parse_args_is_valued_flag "$name" && [[ $# -ge 2 && "$2" != --* ]]; then
                    _parse_args_set_flag "$name" "$2"
                    shift 2
                else
                    _parse_args_set_flag "$name" "true"
                    shift
                fi
                ;;
            -*)
                echo "parse_args: unknown short flag '$arg' (use --long-form)" >&2
                shift
                ;;
            *)
                positionals+=("$arg")
                shift
                ;;
        esac
    done

    if [[ ${#positionals[@]} -gt 0 ]]; then
        ARG_COMMAND="${positionals[0]}"
        positionals=("${positionals[@]:1}")
    fi

    if _parse_args_is_subcommand_family "$ARG_COMMAND" && [[ ${#positionals[@]} -gt 0 ]]; then
        ARG_SUBCOMMAND="${positionals[0]}"
        positionals=("${positionals[@]:1}")
    fi

    if [[ ${#positionals[@]} -gt 0 ]]; then
        ARG_TASK_ID="${positionals[0]}"
        positionals=("${positionals[@]:1}")
    fi

    ARG_REMAINING=("${positionals[@]}")
}

# _parse_args_set_flag: Setzt ARG_FLAG_<UPPER_SNAKE> = value.
# Args: $1=name (long-flag, ohne --), $2=value
_parse_args_set_flag() {
    local raw="$1"
    local val="$2"
    local upper
    upper="$(echo "$raw" | tr 'a-z-' 'A-Z_')"
    printf -v "ARG_FLAG_$upper" '%s' "$val"
}

# _parse_args_is_subcommand_family: Prueft ob $1 in PARSE_ARGS_SUBCOMMAND_FAMILIES ist.
_parse_args_is_subcommand_family() {
    local needle="$1"
    local item
    for item in "${PARSE_ARGS_SUBCOMMAND_FAMILIES[@]}"; do
        [[ "$item" == "$needle" ]] && return 0
    done
    return 1
}

# _parse_args_is_valued_flag: Prueft ob $1 in PARSE_ARGS_VALUED_FLAGS ist.
_parse_args_is_valued_flag() {
    local needle="$1"
    local item
    for item in "${PARSE_ARGS_VALUED_FLAGS[@]}"; do
        [[ "$item" == "$needle" ]] && return 0
    done
    return 1
}
