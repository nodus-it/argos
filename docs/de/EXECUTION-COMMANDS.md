# Was Argos ausführt — Worker- & Demo-Befehlsreferenz

Eine präzise Auflistung der Shell-/Docker-Befehle, die Argos in Ihrem Auftrag
gegen ein Ziel-Repository ausführt — für Betreiber, die exakt prüfen wollen,
was der Agent tut. Es gibt zwei Ausführungskontexte:

- **Der Worker** — ein kurzlebiger Container pro Task-*Phase* (Klonen,
  Abhängigkeiten installieren, den Agenten ausführen, Quality-Gates ausführen,
  committen & pushen).
- **Der Demo-Deployer** — ein kurzlebiger Vorschau-Stack, der *nach* einem
  erfolgreichen Implement hochgefahren wird, sofern Live-Vorschauen für das
  Projekt aktiviert sind.

> Dies ist eine Momentaufnahme zur Orientierung. Die Befehle leben im Code (siehe
> [Wo das liegt](#where-this-lives) am Ende); im Zweifel ist die Quelle
> maßgeblich. Platzhalter wie `$BASE_BRANCH`, `$REPO_URL`, `$auth_header`,
> `$feature_branch` werden pro Task eingespeist; `<slug>`, `<service>`, `<port>`
> werden aus dem Task-/Demo-Vertrag aufgelöst.

## Inhalt

- [Der Worker](#the-worker)
  - [Wie er gestartet wird](#how-it-is-launched)
  - [Klonen & Branch](#clone--branch)
  - [Abhängigkeitsinstallation](#dependency-install)
  - [Pro Phase](#per-phase)
  - [Die Agenten-Session](#the-agent-session)
  - [Quality-/Test-Gate](#quality--test-gate)
- [Der Demo-Deployer](#the-demo-deployer)
  - [Build des Runtime-Images](#runtime-image-build)
  - [Deploy-Lebenszyklus](#deploy-lifecycle)
  - [Boot-Befehle & Health-Probe](#boot-commands--health-probe)
  - [Der Demo-Vertrag](#the-demo-contract)
  - [Abbau](#teardown)
- [Konfigurierbar vs. festgelegt](#configurable-vs-fixed)
- [Wo das liegt](#where-this-lives)

---

## Der Worker

### Wie er gestartet wird

Der Manager baut pro Phase ein `docker run` und streamt dessen Ausgabe. Der
Worker ist ein **einzelner Container**, läuft als der Nicht-Root-Benutzer `agent`
(uid 1000) und hat **keinen Docker-Socket** — er kann nur auf das Repo zugreifen,
niemals auf den Host-Daemon.

```
docker run --rm \
  -v <task-volume>:/workspace \
  -v composer_cache:/home/agent/.composer/cache \
  -v npm_cache:/home/agent/.npm \
  --memory <argos.docker.memory_limit> --cpus <argos.docker.cpu_limit> \
  -e PHASE=<phase> -e TASK_ID=<task> \
  -e REPO_URL=… -e REPO_TOKEN=… -e REPO_PLATFORM=… -e BASE_BRANCH=… \
  -e AGENT_NAME=… -e TASK_DESCRIPTION=… -e PHASE_FLAGS=<json> \
  -e MAX_TURNS=… -e LOG_LEVEL=info \
  -e CLAUDE_CONFIG_DIR=/workspace/.agent/claude-state -e CLAUDE_MODEL=… \
  -e APP_KEY=<deterministic dummy> \
  [agent credential env] \
  [--network <run-net> + DB_HOST/REDIS_HOST… when backing services are on] \
  [project env: COMPOSER_AUTH + the project's custom secrets] \
  [-e RESUME_SESSION_ID=… on a --continue resume] \
  [-e COMMIT_USER_NAME=… -e COMMIT_USER_EMAIL=… from the task creator] \
  [-e FORCE_UNLOCK=1 when the run was force-unlocked] \
  <worker-image> <phase> <task>
```

Hinweise zur Prüfung:

- `REPO_TOKEN` und die Agenten-Credentials werden als Umgebungsvariablen aus der
  Datenbank übergeben — niemals aus gemounteten Dateien. Das Git-Token landet
  weder in der `origin`-URL noch in `/workspace/.git/config`; es wird pro Befehl
  via `http.extraheader` zugeführt und aus Logs entfernt.
- `APP_KEY` ist ein **fester deterministischer Dummy**, damit die Laravel-
  Boot-Pipeline des Ziel-Repos (`package:discover`, `boost:mcp`) bei
  Encrypted-Cast-Code nicht abstürzt. Der Worker persistiert nichts, daher hat
  der Schlüssel keine sicherheitsrelevante Rolle.
- Backing-Services (MySQL / Redis) werden, sofern das Projekt sie aktiviert hat,
  vom Manager als kurzlebige Sidecars in einem privaten Per-Run-Netzwerk *vor*
  dem `docker run` des Workers hochgefahren und danach wieder abgebaut — **nur
  für die Phasen `implement` und `respond`** (die Phasen, die Tests ausführen).
  Der Worker erreicht sie unter den konventionellen Hosts `db` / `redis`.

Vor einer Phase, die Notizen/Feedback konsumiert, schreibt der Manager diesen
Text via eines Wegwerf-Root-Helfers (`alpine`) in das Volume und chownt zurück
auf uid 1000:

```
docker run --rm -i -v <task-volume>:/workspace alpine \
  sh -c 'mkdir -p /workspace/.agent && cat > /workspace/.agent/<file> && chown -R 1000:1000 /workspace'
```

wobei `<file>` entweder `concept.notes.md` (concept), `implement.notes.md`
(implement) oder `respond.feedback.md` (respond) ist.

### Klonen & Branch

Beim **ersten** Lauf (concept, wenn `/workspace/.git` noch nicht existiert)
initialisiert der Worker das Repo an Ort und Stelle — `git clone` würde das
nicht-leere `/workspace` verweigern (das Verzeichnis `.agent/` mit dem State
existiert bereits):

```
git init --quiet --initial-branch="$BASE_BRANCH"
git remote add origin "$REPO_URL"          # token-less URL
git -c "http.extraheader=$auth_header" fetch --quiet --depth=1 origin "$BASE_BRANCH"
git checkout -B "$feature_branch" "origin/$BASE_BRANCH"
```

`$feature_branch` ist `feat/<task-name-slug>` (deutsche Umlaute werden
transliteriert, danach auf einen git-sicheren Slug reduziert). Das
Branch-Namensschema ist festgelegt. Das State-Verzeichnis `.agent/` wird lokal
als ignoriert markiert, damit ein späteres `git clean` es nie löscht.

### Abhängigkeitsinstallation

Bei `implement` (und, best-effort, `concept`) seedet der Worker zunächst eine
`.env` aus `.env.example`, falls sie fehlt, und legt einen Vite-`public/hot`-Stub
ab, damit artisan / Boost ohne gebaute Assets booten können, dann:

```
composer install --no-interaction --prefer-dist --no-progress   # if composer.json exists
npm ci --no-audit --no-fund                                     # implement only, if package-lock.json exists
```

Bei `concept` ist ein fehlschlagendes `composer install` **nicht fatal** (der
Plan kann trotzdem erstellt werden); bei `implement` bricht es die Phase ab.

### Pro Phase

| Phase | Was der Worker der Reihe nach ausführt |
| --- | --- |
| **concept** | Klonen & Branch (erster Lauf) → `composer install` (best-effort) → ein etwaiges vorheriges Concept archivieren → [Agenten-Session](#the-agent-session), die `concept.md` schreibt. Auf dem Repo bewusst read-only. |
| **implement** | (Standard `--fresh`) `git fetch` + `git reset --hard origin/$BASE_BRANCH` + `git clean -fd` (behält gitignorierte `vendor/`, `node_modules/`) → `composer install` / `npm ci` → **Test-Baseline auf dem sauberen Checkout erfassen** → Agenten-Session → **Quality-Gate**; bei einem blockierenden Gate-Fehler bis zu 3 fokussierte Agenten-Fix-Sessions (`GATE_RETRY_LIMIT`). `--refine` überspringt den Reset und baut auf der vorherigen Iteration auf; `--continue` setzt die pausierte Agenten-Session fort. |
| **diff** | read-only. `git diff [--stat] [-- <file>] origin/$BASE_BRANCH` (Working-Tree vs. Base — implement lässt Änderungen uncommitted) → `git status --short` → `git diff --numstat` + `git ls-files --others --exclude-standard` für die Änderungszähler. |
| **push** | die Sub-Phase **commit-message** aufrufen → Git-Identität setzen (`"<name> via Argos"`) → `git add -A` → `git commit -m "<subject>" [-m "<body>"]` → `git push -u --force-with-lease origin "$feature_branch"` → den PR/MR über die Provider-API öffnen / aktualisieren (siehe unten). Überspringt komplett mit Status `no_changes`, wenn es nichts zu pushen gibt. |
| **commit-message** | kurze Agenten-Session (`--max-turns 8`, JSON-Schema-Ausgabe, Claude auf Haiku gepinnt) über Concept + Diff, die Commit-Subject/Body erzeugt. Wird nur von `push` aufgerufen; ein Fehler fällt zurück auf `chore: apply implementation changes (N files)`. |
| **respond** | Agenten-Session über `respond.feedback.md` (Review-Feedback aus der UI) + Concept → dasselbe Quality-Gate wie implement. Wendet das Feedback auf den bestehenden Feature-Branch an; danach `push` ausführen. |

Die PR-/MR-Erstellung in **push** ist providerspezifisch:

- **GitHub** — `curl POST https://api.github.com/repos/<owner>/<repo>/pulls`
  (Bearer `REPO_TOKEN`). Bei HTTP 422 (PR existiert) wird der PR nachgeschlagen
  und die Beschreibung aktualisiert + ein Iterationskommentar hinzugefügt. Es
  `PATCH`t außerdem das Repo auf squash-only + auto-delete-branch-on-merge
  (best-effort; eine Warnung, wenn dem Token Admin-Rechte fehlen).
- **GitLab** — `curl POST …/api/v4/projects/<id>/merge_requests` (Bearer
  `REPO_TOKEN`), analog zu den GitHub-/Bitbucket-Pfaden. Ein bereits offener MR
  für den Branch wird nachgeschlagen und seine Beschreibung aktualisiert statt
  neu erstellt. Das ersetzt die früheren `-o merge_request.create`-Push-Optionen,
  die keine mehrzeilige Beschreibung tragen konnten.
- **Bitbucket** — `curl POST …/2.0/repositories/<ws>/<slug>/pullrequests`
  (Basic Auth für ein `user:app_password`-Token, sonst Bearer). HTTP 409 →
  schlägt den bestehenden PR nach.

### Die Agenten-Session

Die Agenten-Session ist die Agent-CLI (`claude` für `claude-code`, oder `codex`),
ausgeführt mit:

- einem phasenspezifischen **System-Prompt** (`worker/prompts/*.system.md`),
- der Task-Beschreibung / dem Concept / dem Feedback als User-Prompt,
- einem phasenspezifischen Turn-Budget `--max-turns "$MAX_TURNS"` (aufgelöst
  Task → Projekt → `config/argos.php`; Standardwerte 30 für concept, 200 für
  implement/respond),
- einem Modell `CLAUDE_MODEL` (aufgelöst Task → Projekt → Agenten-Standard),
- `--resume "$RESUME_SESSION_ID"` beim Fortsetzen einer pausierten Session,

streamt `stream-json`, das der Worker in das Phasen-Log tee't und auf das
`result`-Event hin parst (Session-ID, Kosten, Token-Verbrauch). Ein Erreichen des
Max-Turns-Limits pausiert die Phase (fortsetzbar), statt sie fehlschlagen zu
lassen. Von der Session wird erwartet, dass sie den Working-Tree verändert
hinterlässt, aber **niemals committet oder pusht** — das ist Aufgabe der
Push-Phase.

### Quality-/Test-Gate

Wird von **implement** und **respond** nach der Agenten-Session ausgeführt und
danach nach jeder Fix-Session erneut. Jedes Gate wird **übersprungen**, wenn sein
Tool oder seine Trigger-Datei fehlt. Zuerst wird auf dem sauberen Checkout eine
Test-Baseline erfasst, sodass nur **neue** Testfehler (gegenüber den bereits
vorhandenen roten Tests des Repos) blockieren.

| # | Gate | Befehl | Läuft, wenn | Blockiert? |
| --- | --- | --- | --- | --- |
| 1 | artisan smoke | `php artisan list --no-ansi` | `/workspace/artisan` existiert | ja |
| 2 | Pint (Stil) | `vendor/bin/pint --test <changed php files>` | `vendor/bin/pint` existiert + geänderte PHP-Dateien | ja |
| 3 | Tests | `vendor/bin/pest --no-coverage --log-junit <…>.xml` (sonst `vendor/bin/phpunit --log-junit <…>.xml`) | der Runner existiert | ja, nur bei **neuen** Fehlern |
| 4 | PHPStan | `vendor/bin/phpstan analyse --no-progress` | `phpstan.neon`/`.dist` + `vendor/bin/phpstan` existieren | ja |
| 5 | Migrations-Syntax | `php -l <new migration file>` | neue Dateien unter `database/migrations/` | ja |
| 6 | debug-code | `grep -lE '\bdd\(\|\bdump\(\|\bray\(\|\bvar_dump\(\|\bddd\('` über geändertes Nicht-Test-PHP | geändertes Nicht-Test-PHP | ja |
| 7 | test-presence | neue `app/`-Klassen werden auf eine passende `*Test.php` geprüft | neue Dateien unter `app/` | nein (nur Warnung) |

Die Gate-Befehle sind **festgelegt** — ein Projekt konfiguriert sie nicht; ein
Tool ist schlicht "aus", wenn es nicht installiert ist. Ein Gate, das an der
Infrastruktur stirbt (OOM `exit 137`, kaputte Konfiguration), wird
**übersprungen** statt zur Behebung geschickt, damit das Fix-Session-Budget nicht
an einem nicht behebbaren Absturz verbrannt wird. Die Fix-Schleife bricht
außerdem früh ab, wenn ein Fix byte-identische Gate-Ausgabe erzeugt (kein
Fortschritt). Das Worker-Image liefert die Toolchain mit (PHP + Extensions inkl.
`sockets`, Composer, Node, den MySQL-Client, …).

---

## Der Demo-Deployer

Wenn Live-Vorschauen aktiviert sind, löst ein erfolgreiches Implement einen
kurzlebigen Vorschau-Stack aus. Der implementierte Code liegt bereits im
Task-Workspace-Volume, daher **mountet der Deployer dieses Volume** in den
Entry-Service der Demo, statt das Repo erneut auszuchecken. Der Stack wird über
eine Traefik-File-Provider-Route unter einer eigenen Subdomain veröffentlicht.
Nur manager-seitig (es braucht den Docker-Socket).

### Build des Runtime-Images

Wenn ein Repo **keinen** Demo-Vertrag mitliefert, verwendet Argos ein
eingebautes Laravel-Runtime-Image, das einmal gebaut und content-hash-gecacht
wird (nur neu gebaut, wenn sich das Rezept ändert). Wird beim Boot durch
`argos:warm-demo-image` vorgewärmt, andernfalls bei Bedarf gebaut:

```
docker build -t argos-demo:<8-hex-hash> -f .tools/docker/demo/Dockerfile <repo root>
```

Ein Repo, das ein eigenes `.argos/demo.compose.yml` mitliefert, stellt sein
eigenes Image bereit und dieser Build entfällt.

### Deploy-Lebenszyklus

Für jedes Deploy (der Compose-Projektname ist der Demo-`<slug>`, abgeleitet aus
dem Task-Namen):

```
# 1. tear down any previous demo of this task (idempotent replace)
docker compose -p <slug> down -v --remove-orphans

# 2. evict the oldest running demos if over argos.preview.max_concurrent

# 3. bring the stack up — repo (or default) compose + the Argos override
docker compose -p <slug> \
  -f <workdir>/demo.compose.yml -f <workdir>/override.yml \
  up -d --remove-orphans

# 4. run each boot command inside the entry service (see below)

# 5. health probe until ready (see below)

# 6. write the Traefik route → the demo is reachable under its subdomain
```

Das **Override** (`override.yml`, pro Task vom `DemoComposeBuilder` generiert)
trägt keine Host-`ports:` und keine Traefik-Labels — das Routing läuft über den
File-Provider. Es mountet das Task-Workspace-Volume am `workspace_mount` des
Vertrags, tritt dem geteilten `argos_edge`-Netzwerk unter dem Demo-Alias bei,
pinnt `APP_URL`/`ASSET_URL` auf die öffentliche URL, injiziert einen
Wegwerf-`APP_KEY` und ein Per-Demo-`SESSION_COOKIE`, setzt `ARGOS_DEMO=1` und
deckelt CPU/Memory aus `argos.preview.*`.

### Boot-Befehle & Health-Probe

Jeder konfigurierte Boot-Befehl läuft der Reihe nach im Entry-Service; der erste
Fehler lässt das Deploy fehlschlagen. Die Health-Probe pollt dann, bis es bereit
ist:

```
# boot command (per entry in the contract's `commands:` list)
docker compose -p <slug> exec -T <service> sh -c '<command>'

# health probe — retried every 3s until health.timeout, from inside the
# container, using whichever of curl/wget exists (skipped if neither does)
docker compose -p <slug> exec -T <service> \
  sh -c 'curl -fsS "http://localhost:<port><health.path>" >/dev/null
         || wget -qO- "http://localhost:<port><health.path>" >/dev/null'
```

Wenn das Repo keinen Vertrag mitliefert, laufen die gebündelten
Standard-Laravel-`commands:` in dieser Reihenfolge (aus
`resources/stubs/demo/laravel/demo.yml`):

```
1. composer install --no-interaction --prefer-dist --no-progress
2. [ -f .env ] || cp .env.example .env
3. php artisan migrate --force --seed
4. [ -f package.json ] && npm ci && npm run build || true
5. rm -f public/hot
6. php artisan storage:link || true
7. chown/chmod storage bootstrap/cache (best-effort)
```

Der Standard-Entry-Service ist `app` (nginx auf Port 80), der Workspace mountet
an `/var/www/html`, und der Health-Pfad ist `/` mit einem 120s-Timeout.

### Der Demo-Vertrag

Ein Projekt steuert seine Demo, indem es **zwei** Dateien am Default-Branch
mitliefert — beide werden zusammen benötigt; ein halb geschriebener Vertrag ist
ein Fehler, kein stiller Rückfall auf die Standardwerte:

| Datei | Zweck |
| --- | --- |
| `.argos/demo.compose.yml` | die Compose-Services. Kann die eingebaute Runtime über den Platzhalter `__ARGOS_DEMO_IMAGE__` referenzieren oder ein eigenes Image mitliefern. |
| `.argos/demo.yml` | Einstellungen: `entry.service`, `entry.port`, `workspace_mount`, die geordnete `commands:`-Boot-Liste sowie `health.path` / `health.timeout`. |

Die `commands:` und `health` des Projekts **ersetzen** die Standardwerte
vollständig. Für den gebündelten Standard-Vertrag folgen die
Demo-DB-Credentials und ein optionaler Redis-Service der Backing-Service-Konfig
des Projekts (dieselben Credentials, die der Worker-Sidecar verwendet).

### Abbau

Eine Demo wird (bei Replace, Eviction über das Concurrency-Limit oder
TTL-Ablauf — `argos.preview.ttl_hours`) per Slug abgebaut, sodass es auch noch
funktioniert, nachdem die Task-Zeile weg ist:

```
docker compose -p <slug> down -v --remove-orphans   # containers + volumes
# + remove the Traefik route file
```

---

## Konfigurierbar vs. festgelegt

| Vom Projekt / Task konfigurierbar | Von Argos festgelegt |
| --- | --- |
| Test-Runner & dessen Ergebnis (welcher von pest/phpunit/pint/phpstan installiert ist) | Die **Befehlszeilen** der Gates und deren Reihenfolge |
| Backing-Services (MySQL / Redis an/aus, Credentials) | Die Menge der Phasen, die Sidecars erhalten (`implement`, `respond`) |
| Agent, Modell pro Phase und `max_turns` pro Phase | Der Phasenfluss concept → implement → push, Branch-Naming, Worker ohne Docker-Socket |
| Projekt-Secrets / `COMPOSER_AUTH`, in Worker & Demo injiziert | Reservierte Argos-Env-Keys (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, …) lassen sich nicht überschreiben |
| Der vollständige Demo-Vertrag (`commands`, `health`, Services, Image, Entry) | Der Compose-Lebenszyklus (down → up → exec → probe → route) und die vom Override injizierte Env |

---

## Wo das liegt

| Bereich | Quelle |
| --- | --- |
| `docker run`-Zusammenbau für eine Phase | `app/Services/Workflow/PhaseCommandBuilder.php` |
| Phasen-Ausführung, Notiz-/Feedback-Schreibvorgänge, Log-Streaming | `app/Services/Workflow/PhaseRunner.php` |
| Backing-Service-Sidecars | `app/Services/Workflow/WorkerSidecarManager.php` |
| Container-Entrypoint (Credentials materialisieren, Dispatch) | `.tools/docker/worker/worker-entrypoint.sh` |
| Per-Phase-Skripte | `worker/phases/{concept,implement,diff,push,commit-message,respond}.sh` |
| Quality-Gate | `worker/lib/quality.sh` |
| Worker-Image (Toolchain) | `.tools/docker/worker/Dockerfile.compose` + `.tools/docker/worker/stacks/Dockerfile.php-8.{3,4}` |
| Demo-Deploy (Compose-Lebenszyklus, Boot, Health, Abbau) | `app/Services/Demo/DemoDeployer.php` |
| Demo-Override (Volume-Mount, Edge-Netzwerk, Env) | `app/Services/Demo/DemoComposeBuilder.php` |
| Build des Demo-Runtime-Images | `app/Services/Demo/DemoImageBuilder.php` + `.tools/docker/demo/Dockerfile` |
| Demo-Vertrag Standard / Erkennung | `app/Services/Demo/DemoContractBuilder.php`, `DemoConfigLocator.php`, `resources/stubs/demo/laravel/demo.{yml,compose.yml}` |
