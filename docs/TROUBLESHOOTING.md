# Troubleshooting

## Auth-Probleme

### `agent concept` schlägt mit Exit 3 ab — `CLAUDE_CODE_OAUTH_TOKEN fehlt`

Token fehlt unter `~/.agent/claude_oauth_token` oder ist leer. Fix:

```bash
claude setup-token   # neuen Token erzeugen
./agent init --update-token
```

### `git push` schlägt mit Auth-Error fehl (Exit 3 in push-Phase)

GitHub PAT ist abgelaufen oder hat zu wenig Rechte. Fine-grained PATs brauchen `Contents: read+write` für das Repo. Neuen Token in GitHub erzeugen, dann:

```bash
./agent abort <task-id>
./agent task new <task-id>
# (mit dem neuen Token)
```

Es gibt aktuell kein dediziertes Token-Renewal-Subcommand pro Task — kommt im Backlog.

### Claude liefert `is_error: true: Not logged in · Please run /login`

`--bare` wurde versehentlich gesetzt oder der Container hat keinen Token gesehen. Prüfen:

```bash
./agent shell <task-id>
# Im Container:
echo "${CLAUDE_CODE_OAUTH_TOKEN:0:10}..."   # sollte den Anfang anzeigen, nicht leer
```

Wenn leer: `~/.agent/claude_oauth_token` auf dem Host prüfen.

## Quality-Gates

### `quality_gate_failed` mit `pint: fail`

Pint findet Style-Verletzungen die Claude nicht automatisch fixen konnte. Logs anschauen:

```bash
./agent logs <task-id> --phase=pint --iteration=N
```

Dann entweder:
- `agent implement <task-id> --continue` — Claude bekommt die Failure-Logs als zusätzlichen Input
- `agent shell <task-id>` und manuell `vendor/bin/pint` laufen lassen

### `quality_gate_failed` mit `pest: fail`

Tests sind rot. `agent logs --phase=pest --iteration=N` zeigt was. Häufige Ursachen:
- DB-Connection: Test-Container hat keine MariaDB. Implement-Prompt sagt Claude soll auf SQLite umschalten — wenn das fehlt, manuell `.env` setzen:
  ```
  DB_CONNECTION=sqlite
  DB_DATABASE=:memory:
  ```
- Pest-Konfiguration im Repo-Root vermisst `Pest.php` oder `tests/TestCase.php`

## Boost / MCP

### `composer install` schlägt mit Post-Install-Hook fehl, der DB-Migrations triggert

Bekanntes v1-Risiko (siehe IMPLEMENTATION.md "Risiken"). Workarounds:

1. Im Test-Repo Post-Install-Hook temporär entfernen
2. `--scripts-no-dev` falls möglich
3. Manuell auf SQLite umstellen via `.env` vor `agent implement`

## Volume / State

### `agent concept` schreibt nicht — `Permission denied` auf `/workspace/.agent/`

Volume gehört einem anderen User als dem `agent` (uid 1000). Tritt nur bei sehr alten Volumes auf, die vor dem Image-Fix angelegt wurden. Fix:

```bash
./agent abort <task-id>
./agent task new <task-id>   # frisches Volume
```

### `state.json` ist nach Container-Crash invalide

Sollte durch atomic-write (worker/lib/state.sh nutzt `.tmp + mv`) nicht passieren. Falls doch:

```bash
./agent shell <task-id>
# Im Container:
jq . /workspace/.agent/state.json   # zeigt JSON-Fehler
```

Manuell reparieren oder Task neu anlegen.

### Lock-File `.lock` blockiert nächste Phase, obwohl nichts läuft

Container-SIGKILL hat den `trap` umgangen. Mit Warnung forciert lösen:

```bash
./agent <phase> <task-id> --force-unlock
```

(Läuft die Phase normal, hat aber den Lock vorher entfernt.)

## Container / Build

### `docker compose build worker` ist langsam beim ersten Mal

Erwartet — apt + PHP-Extensions + Node 20 + Claude Code via npm summieren sich. Beim Re-Build greift Layer-Caching. Wenn das Image >1GB ist: passt, weil PHP+Node+Composer+Claude+gh+vim+nano alle drin sind.

### `agent init` baut das Image neu, obwohl ich nur den Token erneuern will

`--update-token` benutzen:

```bash
./agent init --update-token
```

## Tests

### Bats-Tests scheitern mit "command not found: jq"

Lokales Bats-Image hat kein jq. Wir nutzen `worker/tests/Dockerfile.bats` (bats + jq + check-jsonschema) — das wird automatisch gebaut. Falls das fehlt: `bash worker/tests/run-bats.sh` baut es.

### Integration-Test scheitert mit `git clone failed`

`worker/tests/integration/fixtures/fake-remote-repo/setup.sh` legt das Bare-Repo an. Wenn das Skript schief geht, prüfen:

```bash
ls -la worker/tests/integration/fixtures/fake-remote-repo/fake-remote.git/
```

Sollte HEAD, branches/, refs/ enthalten. Falls nicht: `bash worker/tests/integration/fixtures/fake-remote-repo/setup.sh` manuell.
