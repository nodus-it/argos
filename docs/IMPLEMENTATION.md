# Implementation Spec

Konkrete Entscheidungen für die Implementierung. Konzept (was) in `docs/WORKER-CONCEPT.md`, hier steht das Wie.

## Inhalt

1. Claude-Code-Aufrufe (im Worker)
2. State-Schema und Lifecycle
3. Branch-Naming
4. Worker-Entrypoint-Logik
5. Phase-Konventionen (Bash)
6. Locking
7. Result-JSON-Format
8. PHP-seitige Steuerung (Queue-Jobs, PhaseRunner)
9. Build-Mechanik (zwei Images)
10. System-Prompt-Komposition

---

## 1. Claude-Code-Aufrufe

### 1.1 Concept-Phase

```bash
claude -p \
  --append-system-prompt "$(cat /workspace/.agent/runtime/concept.system.merged.md)" \
  --output-format json \
  --max-turns 15 \
  --permission-mode bypassPermissions \
  < /workspace/.agent/runtime/concept.user-prompt.md
```

- `--output-format json`: liefert `{result, session_id, total_cost_usd, ...}`. Das Phase-Skript greift mit `jq -r '.result'` an den Konzept-Text.
- `max-turns 15`: Claude darf Repo-Struktur lesen bevor Output erscheint.
- `bypassPermissions`: Container ist die Sandbox.
- `concept.md` wird **vom Phase-Skript** geschrieben, nicht von Claude direkt — deterministisch und parsebar.

### 1.2 Implement-Phase

```bash
claude -p \
  --append-system-prompt "$(cat /workspace/.agent/runtime/implement.system.merged.md)" \
  --output-format stream-json \
  --verbose \
  --include-partial-messages \
  --permission-mode bypassPermissions \
  --max-turns "${MAX_TURNS:-50}" \
  < /workspace/.agent/runtime/implement.user-prompt.md \
  | tee "/workspace/.agent/logs/implement.${ITERATION}.stream.log" \
  | jq -c 'select(.type == "result")' \
  > "/workspace/.agent/logs/implement.${ITERATION}.result.json"
```

- `stream-json` + `tee`: vollständige Logs und Live-Fortschritt.
- max-turns 50 default, override via `MAX_TURNS` Env-Variable.

### 1.3 Commit-Message-Phase

```bash
claude -p \
  --append-system-prompt "$(cat /workspace/.agent/runtime/commit-message.system.merged.md)" \
  --output-format json \
  --json-schema "$(cat /workspace/.agent/runtime/commit-message.schema.json)" \
  --max-turns 3 \
  --permission-mode bypassPermissions \
  < /workspace/.agent/runtime/commit-message.user-prompt.md
```

Schema in `worker/schemas/commit-message.schema.json`:
```json
{
  "type": "object",
  "properties": {
    "subject": {"type": "string", "minLength": 10, "maxLength": 72},
    "body":    {"type": "string", "minLength": 0,  "maxLength": 1000}
  },
  "required": ["subject", "body"]
}
```

Phase-Skript parst defensiv: erst `.structured_output`, fallback `.result | fromjson`.

### 1.4 Auth

`CLAUDE_CODE_OAUTH_TOKEN` wird von PHP als `-e` Flag an `docker run` übergeben. Der Token kommt aus der verschlüsselten DB-Spalte des jeweiligen Repo-Profils (oder aus einer globalen Einstellung). Er wird im Worker-Container nie auf Disk geschrieben.

Bei Auth-Fehler antwortet Claude mit `is_error: true` → Phase exit-code 3, PHP markiert Phase als `failed` mit Hinweis auf Token-Erneuerung.

### 1.5 Tools

Keine `--allowedTools`-Beschränkung. Claude hat im Container vollen Zugriff auf alle Default-Tools. Der Container ohne Docker-Socket ist die Sandbox.

---

## 2. State-Schema und Lifecycle

### 2.1 Schema

Datei im Volume: `/workspace/.agent/state.json`. Schema: `worker/schemas/state.schema.json`.

```json
{
  "task_id": "task-001",
  "schema_version": 1,
  "created_at": "2026-04-30T10:00:00Z",
  "updated_at": "2026-04-30T10:34:12Z",
  "repo": {
    "url": "https://github.com/example/proj.git",
    "base_branch": "main",
    "feature_branch": "ai/task-001-1714506000"
  },
  "phases": {
    "concept": {
      "iterations": [
        {
          "n": 1,
          "started_at": "2026-04-30T10:01:00Z",
          "finished_at": "2026-04-30T10:02:30Z",
          "status": "completed",
          "exit_code": 0,
          "flags": {"fresh": false}
        }
      ],
      "current_status": "completed"
    }
  }
}
```

### 2.2 Status-Werte

- `pending` — noch nie gelaufen
- `running` — gerade aktiv (Lock gesetzt)
- `completed` — letzter Lauf erfolgreich
- `quality_gate_failed` — bei `implement`
- `failed` — sonstiger Fehler
- `no_changes` — bei `implement`/`push`

### 2.3 Atomare Writes

`worker/lib/state.sh` schreibt nie direkt: lesen → modifizieren → nach `.tmp` schreiben → `mv .tmp state.json`. Atomar auf POSIX-FS, kein korrupter Stand bei Crash.

### 2.4 State-Sync PHP ↔ Worker

Nach Container-Exit liest PHP den Worker-State aus dem Volume:

```php
// PhaseRunner::readStateFromVolume()
$process = new Process([
    'docker', 'run', '--rm',
    '-v', "task_ws_{$taskName}:/workspace",
    'alpine',
    'sh', '-c', 'cat /workspace/.agent/state.json',
]);
$process->mustRun();
$state = json_decode($process->getOutput(), true);
```

PHP schreibt dann die relevanten Felder (current_status, iterations) in die DB. Die DB ist primäre Quelle für das Web-UI, `state.json` ist Worker-intern.

---

## 3. Branch-Naming

Format: `ai/<task-id>-<timestamp>`

- `<task-id>`: validiert gegen `^[a-z0-9][a-z0-9-]*[a-z0-9]$`, max 40 Zeichen
- `<timestamp>`: Unix-Sekunden vom ersten `concept`-Run, gespeichert in `state.json.repo.feature_branch`

Bei `concept --fresh`: Branch bleibt unverändert — Re-Tries landen auf derselben Remote-Adresse.

---

## 4. Worker-Entrypoint-Logik

`/usr/local/bin/worker-entrypoint.sh`:

```
1.  Argumente parsen: $1=phase, $2=task_id
2.  Env validieren: REPO_URL, REPO_TOKEN, BASE_BRANCH, ggf. CLAUDE_CODE_OAUTH_TOKEN
3.  Volume-Mount /workspace prüfen
4.  State initialisieren (state.json anlegen falls fehlend)
5.  Lock acquiren — bei Konflikt Exit 6
6.  Phase-Skript laden via worker/phases/registry.sh
7.  Vorbedingungen prüfen: phase_<name>_preconditions
8.  Trap setzen (Lock-Release + State=failed bei Crash)
9.  State auf "running" setzen, neue Iteration anlegen
10. phase_<name>_run aufrufen
11. State final updaten (status, finished_at, exit_code)
12. Result-JSON auf stdout
13. Lock release, exit mit Phase-Exit-Code
```

---

## 5. Phase-Konventionen (Bash)

Jede Phase liefert in `worker/phases/<name>.sh`:

```bash
#!/usr/bin/env bash
# Wird gesourced vom worker-entrypoint, nicht direkt ausgeführt.

phase_concept_help() {
    echo "Konzept-Phase: Aufgabe analysieren und Plan formulieren."
}

phase_concept_preconditions() {
    # Exit 0 = OK; sonst: Fehlermeldung nach stderr, return exit-code
    return 0
}

phase_concept_run() {
    # Zugriff auf: alle env vars, lib/-Funktionen (state_*, log_*, result_*, lock_*)
    # Schreibt Result-JSON via result_emit() am Ende
    ...
}
```

`worker/phases/registry.sh`:
```bash
PHASE_NAMES=(concept implement diff push commit-message respond)
PHASE_ORDER_IN_LIFECYCLE=(concept implement diff push)
```

---

## 6. Locking

Lock-Datei: `/workspace/.agent/.lock`

```json
{"pid": 12345, "phase": "implement", "started_at": "2026-04-30T10:00:00Z"}
```

`worker/lib/lock.sh`: atomic write via `.lock.tmp → mv`. Stale-Lock (>4h alt): PHP-UI zeigt Warnung + Force-Unlock-Option.

---

## 7. Result-JSON-Format

Letzte stdout-Zeile des Workers nach jeder Phase:

```json
{
  "phase": "concept",
  "task_id": "task-001",
  "iteration": 1,
  "status": "completed",
  "started_at": "ISO8601",
  "finished_at": "ISO8601",
  "duration_ms": 90000,
  "exit_code": 0
}
```

Phase-spezifische Felder:
- **concept**: `concept_path`, `claude_session_id`, `claude_total_cost_usd`
- **implement**: `changed_files`, `quality_gates: {pint, pest, phpstan}`, `claude_session_id`, `claude_total_cost_usd`
- **diff**: `files_changed`, `insertions`, `deletions`
- **push**: `branch`, `commit_sha`, `remote_url`, `commit_subject`, `pr_url`
- **commit-message**: `subject`, `body`

---

## 8. PHP-seitige Steuerung

### 8.1 Queue-Job

Jede Phase wird als `RunPhaseJob` dispatcht:

```php
// app/Jobs/RunPhaseJob.php
class RunPhaseJob implements ShouldQueue
{
    public function __construct(
        public readonly int $taskId,
        public readonly string $phase,
        public readonly array $flags = [],
    ) {}

    public function handle(PhaseRunner $runner): void
    {
        $task = Task::findOrFail($this->taskId);
        $runner->run($task, $this->phase, $this->flags);
    }
}
```

Dispatch aus Controller/Livewire-Action:
```php
RunPhaseJob::dispatch($task->id, 'concept')->onQueue('default');
```

Queue-Treiber: `database` (SQLite, kein Redis nötig).

### 8.2 PhaseRunner

```php
// app/Domain/Phase/PhaseRunner.php
class PhaseRunner
{
    public function run(Task $task, string $phase, array $flags = []): void
    {
        $process = new Process([
            'docker', 'run', '--rm',
            '-v', "task_ws_{$task->name}:/workspace",
            '-v', "composer_cache:/home/agent/.composer/cache",
            '-v', "npm_cache:/home/agent/.npm",
            '-e', 'PHASE',
            '-e', 'TASK_ID',
            '-e', 'REPO_URL',
            '-e', 'REPO_TOKEN',
            '-e', 'BASE_BRANCH',
            '-e', 'CLAUDE_CODE_OAUTH_TOKEN',
            '-e', 'PHASE_FLAGS',
            config('argos.worker_image', 'ghcr.io/nodus-it/argos-worker:latest'),
        ]);

        $process->setEnv([
            'PHASE'                  => $phase,
            'TASK_ID'                => $task->name,
            'REPO_URL'               => $task->repo_url,
            'REPO_TOKEN'             => $task->repo_token,    // entschlüsselt aus DB
            'BASE_BRANCH'            => $task->base_branch,
            'CLAUDE_CODE_OAUTH_TOKEN'=> config('argos.claude_token'),
            'PHASE_FLAGS'            => json_encode($flags),
        ]);

        $process->setTimeout(null);
        $process->mustRun();
    }
}
```

Credentials kommen aus der DB, nie aus dem Filesystem.

### 8.3 Artisan-Commands (CLI-Einstieg)

```bash
docker exec -it argos php artisan agent:concept task-001
docker exec -it argos php artisan agent:implement task-001 --fresh
docker exec -it argos php artisan agent:diff task-001
docker exec -it argos php artisan agent:push task-001
```

Commands dispatchen synchron (kein Job-Queue), damit der User Output direkt sieht. Die Queue-Jobs werden für asynchrone Ausführung aus der Web-UI genutzt.

---

## 9. Build-Mechanik (zwei Images)

### Dockerfiles

| Image | Dockerfile | Build-Context |
|---|---|---|
| `argos` (Manager) | `docker/Dockerfile` | Repo-Root |
| `argos-worker` (Worker) | `worker/docker/Dockerfile` | `worker/` |

### GitHub Actions

`.github/workflows/build.yml` (noch zu erstellen):
```yaml
- name: Build manager image
  run: docker build -t ghcr.io/nodus-it/argos:latest -f docker/Dockerfile .

- name: Build worker image
  run: docker build -t ghcr.io/nodus-it/argos-worker:latest worker/
```

Beide Images werden bei jedem Push auf `main` gebaut und auf GHCR gepusht.

### Lokale Entwicklung

`docker-compose.yml` hat zwei Services: `manager` und `worker`. `worker` ist als Build-Target definiert (kein Start-Service, wird per `docker run` von PHP gestartet).

---

## 10. System-Prompt-Komposition

`worker/lib/prompts.sh::build_system_prompt()` baut den finalen System-Prompt:

```
1. <phase>.system.md (aus /usr/local/share/agent/prompts/)
2. user.global.system.md (optional, User-globale Konventionen)
3. Dynamische Marker (Task-ID, Base-Branch, Iteration)
→ /workspace/.agent/runtime/<phase>.system.merged.md
```

Prompts sind beim Image-Build nach `/usr/local/share/agent/prompts/` kopiert. Änderungen an Prompts erfordern einen Worker-Image-Rebuild.

---

## 11. Verifikations-Phase nach Implement

Nach der Claude-Session läuft im Worker-Entrypoint noch einmal:
- `vendor/bin/pint --test` → `pass|fail|skip`
- `vendor/bin/pest --no-coverage` (oder phpunit) → `pass|fail|skip`
- `vendor/bin/phpstan analyse` (optional) → `pass|advisory_fail|skip`

Bei `pint: fail` oder `pest: fail`: Phase-Exit-Code 4 (`quality_gate_failed`). Workspace bleibt unangetastet für `--continue`-Lauf.

---

## 12. Bekannte Grenzen

- **Composer post-install mit DB-Anforderung**: Workaround SQLite-Umschaltung via `.env`.
- **Große Repos**: Clone-Zeit kann mehrere Minuten dauern. Kein Timeout.
- **Concurrent Tasks**: Lock nur pro Task, mehrere Tasks laufen parallel (je ein Worker-Container).
- **Token-Rotation**: Klare Fehlermeldungen bei abgelaufenen Tokens, kein Auto-Renewal.
