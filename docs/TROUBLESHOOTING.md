# Troubleshooting

## Auth-Probleme

### Claude-Phase schlägt mit Exit 3 ab — Auth-Fehler

`CLAUDE_CODE_OAUTH_TOKEN` fehlt oder ist abgelaufen. Token über die Web-UI unter Einstellungen erneuern, oder:

```bash
docker exec -it argos php artisan agent:update-token
```

Anzeichen im Log: `is_error: true: Not logged in · Please run /login`

### `git push` schlägt mit 401/403 fehl (Exit 3 in push-Phase)

GitHub PAT abgelaufen oder zu wenig Rechte. Fine-grained PATs brauchen `Contents: read+write`. Neuen Token in GitHub erzeugen, dann im Task-Edit-Dialog in der Web-UI aktualisieren.

## Quality-Gates

### `quality_gate_failed` mit `pint: fail`

Pint findet Style-Verletzungen. Logs in der Web-UI einsehen (Task → Logs → implement, iteration N). Dann:
- Über Web-UI `implement --continue` starten — Claude bekommt Failure-Logs als Input
- Oder in den Workspace einsteigen: `docker exec -it argos php artisan agent:shell task-001`

### `quality_gate_failed` mit `pest: fail`

Tests sind rot. Häufige Ursachen:

- **DB-Connection**: Worker hat keinen DB-Sidecar. Implement-Prompt weist Claude an, auf SQLite umzuschalten. Falls nicht passiert:
  ```
  DB_CONNECTION=sqlite
  DB_DATABASE=:memory:
  ```
  via `agent:shell` manuell in `.env` setzen, dann `--continue`.

- **Pest-Konfiguration fehlt**: `Pest.php` oder `tests/TestCase.php` nicht im Repo.

## Boost / MCP

### `composer install` schlägt mit Post-Install-Hook fehl

Bekanntes Risiko wenn Hooks DB-Migrations triggern (siehe `docs/IMPLEMENTATION.md`). Workarounds:

1. Post-Install-Hook im Repo temporär entfernen
2. `--no-scripts` an composer übergeben (via Implement-Prompt-Anpassung)
3. Manuell SQLite in `.env` setzen, dann `implement --continue`

## Volume / State

### Phase schlägt mit Permission-Fehler auf `/workspace/.agent/` fehl

Volume gehört anderem User als `agent` (uid 1000). Tritt bei alten Volumes auf. Fix:

```bash
# In Web-UI: Task löschen (entfernt Volume), Task neu anlegen
```

### `state.json` nach Crash invalide

`worker/lib/state.sh` schreibt atomar (`.tmp + mv`) — sollte nicht passieren. Falls doch:

```bash
docker exec -it argos php artisan agent:shell task-001
# Im Worker-Container:
jq . /workspace/.agent/state.json
```

Task neu anlegen wenn nicht reparierbar.

### Lock blockiert nächste Phase, obwohl nichts läuft

Container-SIGKILL hat `trap` umgangen. In der Web-UI (Task-Detailseite): Force-Unlock-Button. Oder:

```bash
docker exec -it argos php artisan agent:force-unlock task-001
```

## Container / Build

### Worker-Image fehlt beim ersten Start

```bash
docker pull ghcr.io/nodus-it/argos-worker:latest
# Oder lokal bauen:
docker build -t argos-worker:latest worker/
```

### Manager-Container reagiert nicht auf Port 8080

```bash
docker logs argos           # Supervisor-Log prüfen
docker exec argos supervisorctl status
```

## Tests

### Bats-Tests scheitern mit "command not found: jq"

```bash
bash worker/tests/run-bats.sh   # baut Bats-Image mit jq + check-jsonschema
```

### Integration-Test scheitert mit `git clone failed`

```bash
bash worker/tests/integration/fixtures/fake-remote-repo/setup.sh
ls -la worker/tests/integration/fixtures/fake-remote-repo/fake-remote.git/
# Sollte HEAD, branches/, refs/ enthalten
```
