# Was Argos ausführt — Befehlsreferenz für Workspace & Preview

Eine genaue Liste der Shell-/Docker-Befehle, die Argos in deinem Auftrag gegen
ein Ziel-Repository ausführt — für Betreibende, die genau prüfen wollen, was
der Agent tut. Es gibt zwei Ausführungskontexte:

- **Der Workspace** — ein langlebiger Container pro Workspace (Haupt-Klon, ein
  Worktree pro Aufgabe, die Sessions des Agenten, Setup-Befehle,
  Quality-Gates, Commit & Push).
- **Der Preview-Deployer** — ein kurzlebiger Preview-Stack einer Aufgabe, wenn
  Live-Previews für den Workspace aktiviert sind.

> Dies ist eine Momentaufnahme zur Orientierung. Die Befehle stehen im Code
> (siehe [Wo das liegt](#wo-das-liegt) am Ende); im Zweifel ist der Quellcode
> maßgeblich. Platzhalter wie `<slug>` (Workspace), `<task>` (Slug der
> Aufgabe), `<branch>`, `<base>` werden pro Workspace und Aufgabe aufgelöst;
> `<service>`, `<port>` stammen aus dem Preview-Vertrag.

## Inhalt

- [Der Workspace](#der-workspace)
  - [Wie er gestartet wird](#wie-er-gestartet-wird)
  - [Haupt-Klon](#haupt-klon)
  - [Eine Aufgabe vorbereiten](#eine-aufgabe-vorbereiten)
  - [Die Agent-Session](#die-agent-session)
  - [Quality-/Test-Gate](#quality-test-gate)
  - [Ship: Commit, Push, Pull Request](#ship-commit-push-pull-request)
- [Der Preview-Deployer](#der-preview-deployer)
  - [Build des Runtime-Images](#build-des-runtime-images)
  - [Deploy-Lebenszyklus](#deploy-lebenszyklus)
  - [Boot-Befehle & Health-Probe](#boot-befehle-health-probe)
  - [Der Preview-Vertrag](#der-preview-vertrag)
  - [Abbau](#abbau)
- [Konfigurierbar vs. festgelegt](#konfigurierbar-vs-festgelegt)
- [Wo das liegt](#wo-das-liegt)

---

## Der Workspace

### Wie er gestartet wird

Der Manager legt drei Volumes an (den Checkout, den Agent-Zustand und die
Paket-Caches) und startet einen Container aus dem Workspace-Image — dem Worker-Image (Stack +
Agent + Gate-Helfer) plus Workspace-Daemon und OpenVSCode Server. Der Container
läuft als Nicht-Root-Benutzer `agent` (uid 1000) und hat **keinen
Docker-Socket**.

```
docker run -d --name argos-ws-<slug> --hostname argos-ws-<slug> --network argos_ws \
  --restart unless-stopped --label argos.workspace=<slug> \
  --cpus <workspace cpu_limit | argos.workspaces.cpus> \
  --memory <workspace memory_limit_mb | argos.workspaces.memory> \
  --cap-drop ALL --security-opt no-new-privileges \
  --pids-limit <ARGOS_WORKSPACE_PIDS_LIMIT, default 4096> \
  -v ws_<slug>:/workspace \
  -v ws_<slug>_agents:/home/agent/.claude-agents \
  --mount type=volume,source=ws_<slug>_cache,target=/home/agent/.composer/cache,volume-subpath=composer \
  --mount type=volume,source=ws_<slug>_cache,target=/home/agent/.npm,volume-subpath=npm \
  -e ARGOS_DAEMON_TOKEN=… -e ARGOS_EVENTS_URL=… -e ARGOS_REPO_CREDENTIAL_URL=… \
  -e ARGOS_DAEMON_PORT=7777 -e ARGOS_EDITOR_PORT=3000 \
  -e APP_KEY=<fixed dummy> \
  [DB_HOST/REDIS_HOST… when backing services are on] \
  [workspace env: COMPOSER_AUTH + the workspace's custom secrets] \
  <workspace-image>
```

Hinweise für die Prüfung:

- Der Container hat keine Capabilities, kann über setuid-Programme keine
  Rechte gewinnen und hat ein Prozesslimit. Die Paket-Caches gehören je einem
  Workspace, ein in einem Cache platziertes Paket erreicht also nie die
  Installation eines anderen. Der Editor lauscht nur auf Loopback; das
  Editor-Gate des Daemons lässt nur Anfragen durch, für die Traefiks
  Code-Route bürgt.
- Alles darin läuft als `agent`: Wer den Editor nutzen darf (Workspace-Admins
  und Mitglieder), kann den Daemon-Token lesen und die Repository-Zugangsdaten
  dieses Workspaces verwenden – gib Workspaces Tokens, die auf das Repository
  beschränkt sind.

- Weder Repository-Token noch Claude-Token stehen in der Container-Umgebung.
  Git fragt den Argos-Credential-Helper, der den Token bei Bedarf vom Manager
  holt; der Claude-Token der Person, der die Aufgabe gehört, wird dem Daemon im
  Speicher übergeben, wenn die Session einer Aufgabe startet.
- `APP_KEY` ist ein **fester Dummy**, damit das Laravel des Projekts für
  artisan und Tests bootet (die Laravel-Standard-Setup-Befehle erzeugen
  außerdem einen Schlüssel in der `.env` einer Aufgabe, wenn deren `APP_KEY`
  leer ist).
- Backing-Services (MySQL / Redis) laufen, wenn der Workspace sie aktiviert
  hat, als Sidecars in einem privaten Netzwerk pro Workspace (`ws_<slug>_svc`),
  dem der Container beitritt. Ihre Daten-Volumes bleiben, bis der Workspace
  gelöscht wird.

### Haupt-Klon

Beim ersten Start klont der Daemon nach `/workspace/repo` und lässt den Klon
detached auf dem Default-Branch stehen — er ist nur die Basis für die
Worktrees der Aufgaben:

```
git ls-remote --heads <repo-url> <default-branch>   # token via the credential helper
git clone --no-checkout <repo-url> /workspace/repo
git checkout --detach origin/<default-branch>
```

`.agent/` wird lokal als ignoriert markiert. Bei späteren Starts bleibt der
vorhandene Klon unangetastet.

### Eine Aufgabe vorbereiten

Eine neue Aufgabe bekommt ihre Datenbank, ihren Worktree und ihre
Setup-Befehle, bevor Claude startet:

```
# manager: the task database on the MySQL sidecar (when MySQL is enabled)
CREATE DATABASE task_<task>        # on the sidecar, granted to the workspace user

# daemon, in /workspace/repo
git fetch --prune origin
git worktree add -B <branch> /workspace/tasks/<task> origin/<branch>    # branch exists on the remote
git worktree add --no-track -B <branch> /workspace/tasks/<task> <base commit of origin/<base>>   # otherwise

# daemon: .env from .env.example (if missing) + the task's DB_*, REDIS_*, REDIS_PREFIX, CACHE_PREFIX
#         the same values in .agent/task-env.json (they override the container env in the worktree)

# daemon, in the worktree: task-setup.sh
bash .argos/setup.sh                                   # if the repo has it (or from the unmerged setup branch)
composer install --no-interaction --no-progress --prefer-dist   # otherwise the Laravel defaults …
php artisan key:generate --force --no-interaction      # … when .env has an empty APP_KEY
php artisan migrate --force --no-interaction
```

Das Entfernen einer Aufgabe (Aufräumen oder Löschen) führt
`git worktree remove --force` und `git worktree prune` aus, löscht den lokalen
Branch und entfernt die Datenbank der Aufgabe.

### Die Agent-Session

Jede Aufgabe ist eine Claude-Code-Session, die der Daemon über das Claude
Agent SDK hostet, mit dem Worktree als Arbeitsverzeichnis und den Projekt- und
lokalen Einstellungen des Repositorys. Modell, Berechtigungsmodus und — bei
automatischen Aufgaben — das Turn-Budget kommen aus der Aufgabe. Ein
`PreToolUse`-Hook fragt nach (bei automatischen Aufgaben: lehnt ab), bevor
Datei-Tools außerhalb des Worktrees und `/tmp` laufen. Der Agent bearbeitet den
Worktree; er pusht **nicht**.

### Quality-/Test-Gate

Gates laufen auf Anforderung (die Seitenleiste, die API oder eine automatische
Aufgabe nach der Umsetzung) im Worktree der Aufgabe. Der Daemon ruft
`run-gates.sh` auf, das die Gate-Helfer (`worker/lib/quality.sh`) einbindet.
Die **Baselines** für Tests und PHPStan werden einmal pro Basis-Commit im
Haupt-Klon erfasst, unter `/workspace/.argos/baselines/<commit>/` abgelegt und
in jeden Worktree auf diesem Commit kopiert, sodass nur **neue** Fehler
blockieren. Jedes Gate wird **übersprungen**, wenn sein Tool oder seine
Auslöser-Datei fehlt.

| # | Gate | Befehl | Läuft, wenn | Blockiert? |
| --- | --- | --- | --- | --- |
| 1 | artisan smoke | `php artisan list --no-ansi` | `artisan` existiert | ja |
| 2 | Pint (Style) | `vendor/bin/pint --test <changed php files>` | `vendor/bin/pint` existiert + geänderte PHP-Dateien | ja |
| 3 | Tests | `vendor/bin/pest --no-coverage --log-junit <…>.xml` (sonst `vendor/bin/phpunit --log-junit <…>.xml`) | der Runner existiert | ja, nur bei **neuen** Fehlern |
| 4 | PHPStan | `vendor/bin/phpstan analyse --no-progress` | `phpstan.neon`/`.dist` + `vendor/bin/phpstan` existieren | ja, nur bei **neuen** Fehlern |
| 5 | Migrations-Syntax | `php -l <new migration file>` | neue Dateien unter `database/migrations/` | ja |
| 6 | Debug-Code | `grep -lE '\bdd\(\|\bdump\(\|\bray\(\|\bvar_dump\(\|\bddd\('` über geänderte Nicht-Test-PHP-Dateien | geänderte Nicht-Test-PHP-Dateien | ja |
| 7 | Test-Präsenz | neue `app/`-Klassen werden auf eine passende `*Test.php` geprüft | neue Dateien unter `app/` | nein (nur Warnung) |

Die Gate-Befehle sind **fest** — ein Projekt konfiguriert sie nicht; ein Tool
ist einfach „aus“, wenn es nicht installiert ist. Ein fehlgeschlagenes Gate
lässt sich dem Agenten als Fix-Prompt schicken; eine automatische Aufgabe tut
das selbst, bis zu ihrem Limit an Fix-Runden.

### Ship: Commit, Push, Pull Request

Beim Ausliefern entwirft eine kurze One-Shot-Session (Haiku, JSON-Ausgabe, über
das Abo der ausliefernden Person) aus dem Diff eine Commit-Message, danach im
Worktree der Aufgabe:

```
git add -A
git -c user.name=<person> -c user.email=<email> commit --no-verify -m "<subject>\n\n<body>"
git push --set-upstream origin HEAD:refs/heads/<branch>
```

Den Pull / Merge Request öffnet oder aktualisiert der **Manager** über die API
des Providers (GitHub, GitLab, Bitbucket) mit dem Repository-Zugang des
Workspaces, nicht aus dem Container heraus. Automatische Aufgaben mit
Ship-Modus `none` stoppen nach dem Push. Danach liest der Manager Zustand,
Checks und Reviews des Pull Requests über dieselbe API (siehe
[Pull Requests](PULL-REQUESTS.md)).

---

## Der Preview-Deployer

Sind Live-Previews aktiviert, kann eine Aufgabe einen kurzlebigen
Preview-Stack starten. Der Code liegt bereits im Workspace-Volume, daher
**mountet** der Deployer **den Worktree der Aufgabe** (einen Volume-Subpfad) in
den Entry-Service, statt das Repo erneut auszuchecken. Der Stack wird über eine
Traefik-File-Provider-Route unter seiner eigenen Subdomain veröffentlicht. Nur
auf Manager-Seite (er braucht den Docker-Socket).

### Build des Runtime-Images

Liefert ein Repo **keinen** Demo-Vertrag mit, nutzt Argos ein eingebautes
Laravel-Runtime-Image, das einmal pro PHP-Version gebaut und per Inhalts-Hash
gecacht wird (neu gebaut nur, wenn sich das Rezept ändert). Die PHP-Version
folgt dem eingebauten Stack des Workspaces (PHP 8.5 oder 8.4; eigene Images und
Node erhalten die Version des Standard-Stacks). Die Standard-Version wird beim
Booten von `argos:warm-demo-image` vorgewärmt, andere werden bei Bedarf gebaut:

```
docker build -t argos-demo:<php>-<8-hex-hash> --build-arg PHP_VERSION=<php> -f .tools/docker/demo/Dockerfile <repo root>
```

Ein Repo, das seine eigene `.argos/demo.compose.yml` mitliefert, bringt sein
eigenes Image mit, und dieser Build entfällt.

### Deploy-Lebenszyklus

Für jedes Deploy (der Compose-Projektname ist der Preview-Schlüssel
`demo-<slug>-<task>`, gekürzt auf 63 Zeichen):

```
# 1. tear down any previous preview of this task (idempotent replace)
docker compose -p <slug> down -v --remove-orphans

# 2. evict the oldest running previews if over the workspace's max_previews
#    or argos.preview.max_concurrent

# 3. bring the stack up — repo (or default) compose + the Argos override
docker compose -p <slug> \
  -f <workdir>/demo.compose.yml -f <workdir>/override.yml \
  up -d --remove-orphans

# 4. run each boot command inside the entry service (see below)

# 5. health probe until ready (see below)

# 6. write the Traefik route → the demo is reachable under its subdomain
```

Das **Override** (`override.yml`, pro Demo von `DemoComposeBuilder` erzeugt)
enthält keine Host-`ports:` und keine Traefik-Labels — das Routing läuft über
den File-Provider. Es mountet den Worktree der Aufgabe am `workspace_mount` des
Vertrags, tritt dem gemeinsamen Netzwerk `argos_edge` unter dem Demo-Alias bei,
setzt `APP_URL`/`ASSET_URL` fest auf die öffentliche URL, injiziert einen
Wegwerf-`APP_KEY` und ein `SESSION_COOKIE` pro Demo, setzt `ARGOS_DEMO=1` und
begrenzt CPU/Arbeitsspeicher anhand von `argos.preview.*`.

### Boot-Befehle & Health-Probe

Jeder konfigurierte Boot-Befehl läuft der Reihe nach im Entry-Service; der
erste Fehler lässt das Deploy scheitern. Danach fragt die Health-Probe ab, bis
die Preview bereit ist:

```
# boot command (per entry in the contract's `commands:` list)
docker compose -p <slug> exec -T <service> sh -c '<command>'

# health probe — retried every 3s until health.timeout, from inside the
# container, using whichever of curl/wget exists (skipped if neither does)
docker compose -p <slug> exec -T <service> \
  sh -c 'curl -fsS "http://localhost:<port><health.path>" >/dev/null
         || wget -qO- "http://localhost:<port><health.path>" >/dev/null'
```

Liefert das Repo keinen Vertrag mit, laufen die mitgelieferten
Laravel-Standard-`commands:` in dieser Reihenfolge (aus
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

Der Standard-Entry-Service ist `app` (nginx auf Port 80), der Workspace wird
unter `/var/www/html` gemountet, und der Health-Pfad ist `/` mit 120 s
Timeout.

### Der Preview-Vertrag

Ein Repository steuert seine Preview, indem es **zwei** Dateien am Setup-Ref
mitliefert (dem Default-Branch oder einem getesteten, noch nicht gemergten
Einrichtungs-Branch; eine Einrichtungs-Aufgabe nutzt ihren eigenen Branch) —
beide gehören zusammen; ein halb geschriebener Vertrag ist ein Fehler, kein
stiller Rückfall auf den Standard:

| Datei | Zweck |
| --- | --- |
| `.argos/demo.compose.yml` | die Compose-Services. Kann die eingebaute Runtime über den Platzhalter `__ARGOS_DEMO_IMAGE__` referenzieren oder ein eigenes Image mitbringen. |
| `.argos/demo.yml` | Einstellungen: `entry.service`, `entry.port`, `workspace_mount`, die geordnete Boot-Liste `commands:` und `health.path` / `health.timeout`. |

`commands:` und `health` des Repositorys **ersetzen** die Standards
vollständig. Beim mitgelieferten Standard-Vertrag folgen die
DB-Zugangsdaten der Preview und ein optionaler Redis-Service der
Backing-Service-Konfiguration des Workspaces (dieselben Zugangsdaten, die die
Sidecars des Workspaces nutzen).

### Abbau

Eine Preview wird (beim Ersetzen, beim Verdrängen über eine Obergrenze, beim
Ablauf der TTL — `argos.preview.ttl_hours` —, beim Stoppen des Workspaces
oder beim Aufräumen der Aufgabe) anhand ihres Slugs abgebaut, sodass das auch
noch funktioniert, wenn der Datensatz der Aufgabe weg ist. Die Route ist immer
durch das Workspace-Gate geschützt (nur Mitglieder):

```
docker compose -p <slug> down -v --remove-orphans   # containers + volumes
# + remove the Traefik route file
```

---

## Konfigurierbar vs. festgelegt

| Konfigurierbar durch Repository / Workspace / Lauf | Von Argos festgelegt |
| --- | --- |
| Test-Runner & sein Ergebnis (welches von pest/phpunit/pint/phpstan installiert ist) | Die **Befehlszeilen** der Gates und ihre Reihenfolge |
| Backing-Services (MySQL / Redis an/aus, Zugangsdaten) | Sidecars in einem privaten Netzwerk pro Workspace |
| Setup-Befehle (`.argos/setup.sh`), Branch-Muster, Modell, Plan-/Ship-Modus und Budgets einer automatischen Aufgabe | Worktree-Layout, Datenbank pro Aufgabe, Workspace ohne Docker-Socket, Tokens nie in der Container-Umgebung |
| Workspace-Secrets / `COMPOSER_AUTH`, injiziert in Workspace & Preview | Reservierte Argos-Env-Schlüssel (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, …) lassen sich nicht überschreiben |
| Der vollständige Preview-Vertrag (`commands`, `health`, Services, Image, Entry) | Der Compose-Lebenszyklus (down → up → exec → probe → route) und die per Override injizierte Umgebung |

---

## Wo das liegt

| Bereich | Quelle |
| --- | --- |
| Volumes + `docker run` eines Workspaces | `app/Services/Workspace/WorkspaceContainerManager.php` |
| Reihenfolge der Bereitstellung (Image → Volumes → Sidecars → Container → Haupt-Klon) | `app/Services/Workspace/WorkspaceProvisioner.php` |
| Vorbereitung einer Aufgabe (Datenbank, Worktree, `.env`, Setup) | `app/Services/Workspace/TaskPreparer.php`, `daemon/src/worktrees.ts`, `.tools/docker/worker/workspace/task-setup.sh` |
| Sidecars der Backing-Services | `app/Services/Worker/WorkerSidecarManager.php` |
| Klon, Git-Operationen, Agent-Sessions, Datei-Schutz, Gates | `daemon/src/{workspaceInit,gitOps,taskSession,guard,gates}.ts` |
| Gate-Skript + Helfer | `.tools/docker/worker/workspace/run-gates.sh`, `worker/lib/quality.sh` |
| Ausliefern (Commit-Message, Push, Pull Request) | `app/Services/Workspace/WorkspaceShipService.php` |
| Images (Toolchain, Daemon, Editor) | `.tools/docker/worker/Dockerfile.compose`, `.tools/docker/worker/stacks/Dockerfile.{php-8.5,php-8.4,node-22}`, `.tools/docker/worker/workspace/Dockerfile` |
| Preview-Deploy (Compose-Lebenszyklus, Boot, Health, Abbau) | `app/Services/Demo/DemoDeployer.php` |
| Preview-Override (Worktree-Mount, Edge-Netzwerk, Umgebung) | `app/Services/Demo/DemoComposeBuilder.php` |
| Build des Preview-Runtime-Images | `app/Services/Demo/DemoImageBuilder.php` + `.tools/docker/demo/Dockerfile` |
| Standard / Erkennung des Preview-Vertrags | `app/Services/Demo/DemoContractBuilder.php`, `DemoConfigLocator.php`, `resources/stubs/demo/laravel/demo.{yml,compose.yml}` |
