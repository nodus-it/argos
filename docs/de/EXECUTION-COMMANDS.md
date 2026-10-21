# Was Argos ausführt — Workspace- & Demo-Befehlsreferenz

Eine präzise Auflistung der Shell-/Docker-Befehle, die Argos in deinem Auftrag
gegen ein Ziel-Repository ausführt — für Betreiber, die exakt prüfen wollen,
was der Agent tut. Es gibt zwei Ausführungskontexte:

- **Der Workspace** — ein langlebiger Container pro Workspace (Klonen, die
  Sessions des Agenten, Quality-Gates, committen & pushen).
- **Der Demo-Deployer** — ein kurzlebiger Vorschau-Stack eines Workspaces,
  sofern Live-Vorschauen für das Projekt aktiviert sind.

> Dies ist eine Momentaufnahme zur Orientierung. Die Befehle leben im Code (siehe
> [Wo das liegt](#where-this-lives) am Ende); im Zweifel ist die Quelle
> maßgeblich. Platzhalter wie `<slug>`, `<branch>`, `<base>` werden pro
> Workspace aufgelöst; `<service>`, `<port>` stammen aus dem Demo-Vertrag.

## Inhalt

- [Der Workspace](#the-workspace)
  - [Wie er gestartet wird](#how-it-is-launched)
  - [Klonen & Branch](#clone--branch)
  - [Die Agenten-Session](#the-agent-session)
  - [Quality-/Test-Gate](#quality--test-gate)
  - [Ship: Commit, Push, Pull Request](#ship-commit-push-pull-request)
- [Der Demo-Deployer](#the-demo-deployer)
  - [Build des Runtime-Images](#runtime-image-build)
  - [Deploy-Lebenszyklus](#deploy-lifecycle)
  - [Boot-Befehle & Health-Probe](#boot-commands--health-probe)
  - [Der Demo-Vertrag](#the-demo-contract)
  - [Abbau](#teardown)
- [Konfigurierbar vs. festgelegt](#configurable-vs-fixed)
- [Wo das liegt](#where-this-lives)

---

## Der Workspace

### Wie er gestartet wird

Der Manager legt zwei Volumes an (Checkout und Agent-Zustand) und startet einen
Container aus dem Workspace-Image — dem Worker-Image (Stack + Agent +
Gate-Helfer) plus Workspace-Daemon und OpenVSCode Server. Der Container läuft
als Nicht-Root-Benutzer `agent` (uid 1000) und hat **keinen Docker-Socket**.

```
docker run -d --name argos-ws-<slug> --network argos_ws --restart unless-stopped \
  --label argos.workspace=<slug> \
  --cpus <argos.workspaces.cpus> --memory <argos.workspaces.memory> \
  -v ws_<slug>:/workspace \
  -v ws_<slug>_agents:/home/agent/.claude-agents \
  -v composer_cache:/home/agent/.composer/cache \
  -v npm_cache:/home/agent/.npm \
  -e ARGOS_DAEMON_TOKEN=… -e ARGOS_EVENTS_URL=… -e ARGOS_REPO_CREDENTIAL_URL=… \
  -e ARGOS_DAEMON_PORT=7777 -e ARGOS_EDITOR_PORT=3000 \
  -e APP_KEY=<fixed dummy> \
  [DB_HOST/REDIS_HOST… when backing services are on] \
  [project env: COMPOSER_AUTH + the project's custom secrets] \
  <workspace-image>
```

Hinweise für die Prüfung:

- Weder Repository-Token noch Claude-Token stehen in der Container-Umgebung.
  Git fragt den Argos-Credential-Helper, der den Token bei Bedarf vom Manager
  holt; der Claude-Token erreicht den Daemon nur im Speicher, wenn eine
  Thread-Session startet.
- `APP_KEY` ist ein **fester Dummy**, damit das Laravel des Projekts für
  artisan und Tests bootet. Das Workspace-Volume enthält nichts, was dieser
  Schlüssel schützt.
- Backing-Services (MySQL / Redis) laufen, wenn das Projekt sie aktiviert hat,
  als Sidecars in einem privaten Netzwerk pro Workspace (`ws_<slug>_svc`), dem
  der Container beitritt. Ihre Daten-Volumes bleiben, bis der Workspace
  gelöscht wird.

### Klonen & Branch

Beim ersten Start klont der Daemon in das leere Volume und checkt den Branch
des Workspaces aus:

```
git clone --no-checkout <repo-url> /workspace     # token via the credential helper
git checkout -B <branch> origin/<branch>          # branch exists on the remote
git checkout -b <branch> origin/<base>            # otherwise: new branch off the base
```

Der Branch heißt `ws/<slug>` (bzw. `ws/<issue-nummer>-<slug>` bei einem
Issue-Trigger). `.agent/` wird lokal ignoriert. Bei späteren Starts bleibt der
vorhandene Checkout unangetastet.

### Die Agenten-Session

Jeder Thread ist eine Claude-Code-Session, die der Daemon über das Claude Agent
SDK hostet — mit den Projekt- und lokalen Einstellungen des Repositorys.
Modell, Berechtigungsmodus und — bei automatischen Läufen — das Turn-Budget
kommen vom Thread. Der Agent ändert den Arbeitsbaum; er pusht **nicht**.

### Quality-/Test-Gate

Gates laufen auf Anforderung (das *Gates*-Panel, die API oder ein automatischer
Lauf nach der Umsetzung). Der Daemon ruft `run-gates.sh` auf, das die
Gate-Helfer (`worker/lib/quality.sh`) einbindet. Auf dem frischen Checkout wird
eine Baseline erfasst (`composer install`, falls `vendor/` fehlt, danach die
Test- und PHPStan-Baselines), sodass nur **neue** Fehler blockieren. Jedes Gate
wird **übersprungen**, wenn sein Tool oder seine Trigger-Datei fehlt.

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
Tool ist schlicht „aus“, wenn es nicht installiert ist. Ein fehlgeschlagenes
Gate lässt sich als Fix-Prompt an den Agenten schicken; ein automatischer Lauf
tut das selbst, bis zu seinem Limit an Fix-Runden.

### Ship: Commit, Push, Pull Request

Beim Shippen entwirft eine kurze One-Shot-Session (Haiku, JSON-Ausgabe) die
Commit-Nachricht aus dem Diff, danach:

```
git add -A
git -c user.name=<person> -c user.email=<email> commit --no-verify -m "<subject>\n\n<body>"
git push --set-upstream origin HEAD:refs/heads/<branch>
```

Den Pull- bzw. Merge-Request öffnet oder aktualisiert der **Manager** über die
Provider-API (GitHub, GitLab, Bitbucket), nicht der Container. Automatische
Läufe mit Ship-Modus `none` enden nach dem Push.

---

## Der Demo-Deployer

Wenn Live-Vorschauen aktiviert sind, kann ein Workspace einen kurzlebigen
Vorschau-Stack starten. Der Code liegt bereits im Workspace-Volume, daher
**mountet der Deployer dieses Volume** in den
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

Für jedes Deploy (der Compose-Projektname ist der Demo-Schlüssel
`demo-ws-<slug>`):

```
# 1. tear down any previous demo of this workspace (idempotent replace)
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

Das **Override** (`override.yml`, pro Demo vom `DemoComposeBuilder` generiert)
trägt keine Host-`ports:` und keine Traefik-Labels — das Routing läuft über den
File-Provider. Es mountet das Workspace-Volume am `workspace_mount` des
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
des Projekts (dieselben Credentials, die die Workspace-Sidecars verwenden).

### Abbau

Eine Demo wird (bei Replace, Eviction über das Concurrency-Limit oder
TTL-Ablauf — `argos.preview.ttl_hours`) per Slug abgebaut, sodass das auch
noch funktioniert, nachdem die Workspace-Zeile weg ist. Die Route ist immer
durch das Workspace-Gate geschützt (nur Mitglieder):

```
docker compose -p <slug> down -v --remove-orphans   # containers + volumes
# + remove the Traefik route file
```

---

## Konfigurierbar vs. festgelegt

| Vom Projekt / Lauf konfigurierbar | Von Argos festgelegt |
| --- | --- |
| Test-Runner & dessen Ergebnis (welcher von pest/phpunit/pint/phpstan installiert ist) | Die **Befehlszeilen** der Gates und deren Reihenfolge |
| Backing-Services (MySQL / Redis an/aus, Credentials) | Sidecars in einem privaten Netzwerk pro Workspace |
| Modell, Plan-/Ship-Modus und Budgets eines automatischen Laufs | Branch-Naming, Workspace ohne Docker-Socket, Tokens nie in der Container-Umgebung |
| Projekt-Secrets / `COMPOSER_AUTH`, injiziert in Workspace & Demo | Reservierte Argos-Env-Keys (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, …) lassen sich nicht überschreiben |
| Der vollständige Demo-Vertrag (`commands`, `health`, Services, Image, Entry) | Der Compose-Lebenszyklus (down → up → exec → probe → route) und die vom Override injizierte Env |

---

## Wo das liegt

| Bereich | Quelle |
| --- | --- |
| Volumes + `docker run` eines Workspaces | `app/Services/Workspace/WorkspaceContainerManager.php` |
| Bereitstellungsreihenfolge (Image → Volumes → Sidecars → Container → Klonen) | `app/Services/Workspace/WorkspaceProvisioner.php` |
| Backing-Service-Sidecars | `app/Services/Worker/WorkerSidecarManager.php` |
| Klonen, Git-Operationen, Agenten-Sessions, Gates | `daemon/src/{workspaceInit,gitOps,threadSession,gates}.ts` |
| Gate-Skript + Helfer | `.tools/docker/worker/workspace/run-gates.sh`, `worker/lib/quality.sh` |
| Ship (Commit-Nachricht, Push, Pull Request) | `app/Services/Workspace/WorkspaceShipService.php` |
| Images (Toolchain, Daemon, Editor) | `.tools/docker/worker/Dockerfile.compose`, `.tools/docker/worker/stacks/Dockerfile.php-8.{3,4}`, `.tools/docker/worker/workspace/Dockerfile` |
| Demo-Deploy (Compose-Lebenszyklus, Boot, Health, Abbau) | `app/Services/Demo/DemoDeployer.php` |
| Demo-Override (Volume-Mount, Edge-Netzwerk, Env) | `app/Services/Demo/DemoComposeBuilder.php` |
| Build des Demo-Runtime-Images | `app/Services/Demo/DemoImageBuilder.php` + `.tools/docker/demo/Dockerfile` |
| Demo-Vertrag Standard / Erkennung | `app/Services/Demo/DemoContractBuilder.php`, `DemoConfigLocator.php`, `resources/stubs/demo/laravel/demo.{yml,compose.yml}` |
