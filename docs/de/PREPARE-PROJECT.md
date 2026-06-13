# Ein Projekt für Argos vorbereiten

Dieser Leitfaden richtet sich an **Entwickler, die ihr eigenes Repository
vorbereiten**, damit Argos daran arbeiten kann: was "Argos-ready" bedeutet, die
optionalen `.argos/`-Vertragsdateien, die projektspezifische Worker-Umgebung und
Secrets, die Backing-Services für Tests und Demos sowie die Grundlagen der
Live-Demo.

Die Ausgabesprache für jede Datei, die du in deinem Repo erstellst, ist
**Englisch** (Code, Kommentare, YAML-Kommentare). Erfinde keine Felder — jeder
Schlüssel unten entspricht einem echten Schlüssel, den Argos liest.

- [Was "Argos-ready" bedeutet](#what-argos-ready-means)
- [Was Argos aus deinem Repo liest](#what-argos-reads-from-your-repo)
- [Teil A — Ausführungsumgebung](#part-a--execution-environment)
  - [Wie Argos sie nutzt](#how-argos-uses-it)
  - [Das Quality Gate, das dein Projekt bestehen muss](#the-quality-gate-your-project-must-pass)
  - [Ist mein Projekt direkt einsatzbereit?](#is-my-project-ready-as-is)
  - [Backing-Services (MySQL / Redis)](#backing-services-mysql--redis)
  - [Private Registries & Secrets](#private-registries--secrets)
  - [Bring your own image (BYOI)](#bring-your-own-image-byoi)
  - [Stattdessen das Projekt anpassen](#adjust-the-project-instead)
- [Teil B — Live-Demo](#part-b--live-demo)
  - [Wie Argos sie nutzt](#how-argos-uses-it-1)
  - [Ist mein Projekt direkt einsatzbereit?](#is-my-project-ready-as-is-1)
  - [Eigenen Vertrag ausliefern](#ship-your-own-contract)
  - [Stattdessen das Projekt anpassen](#adjust-the-project-instead-1)
- [Abschluss-Checkliste](#final-checklist)

## Was "Argos-ready" bedeutet

Ein Repository ist "Argos-ready", wenn Argos es für jede beliebige Aufgabe
**klonen, dessen Abhängigkeiten installieren, den Agenten eine Änderung
umsetzen lassen und diese Änderung das Quality Gate bestehen lassen** kann —
alles innerhalb eines einzigen ephemeren Worker-Containers, vollständig offline
bis auf die Package-Registries, die du konfigurierst.

In der Praxis bedeutet das:

1. Abhängigkeiten installieren sich mit der Toolchain, die Argos bereitstellt
   (PHP 8.3/8.4, Composer, Node 22), oder mit einer, die du über ein Custom
   Image lieferst.
2. Die Test-Suite läuft **offline** — keine echten externen APIs — und erreicht
   nur Services, die Argos für dich hochfahren kann (MySQL/MariaDB, Redis), oder
   läuft auf SQLite / in-memory.
3. Das [Quality Gate](#the-quality-gate-your-project-must-pass) kann laufen und
   verschlechtert sich nicht: Eine Phase ist nur erfolgreich, wenn die Gates,
   die das Projekt unterstützt, bestehen.

Optional, wenn du nach jedem Implement eine klickbare Vorschau möchtest, muss das
Repo zusätzlich den [Live-Demo-Vertrag](#part-b--live-demo) erfüllen.

## Was Argos aus deinem Repo liest

Argos integriert sich mit einem Ziel-Repository über **zwei unabhängige
Verträge**, beide optional, beide unter `.argos/` auf dem **Default-Branch** des
Repositories (die Live-Demo) oder dem **Base-Branch der Aufgabe** (das
Worker-Image). Sie werden über die API des Git-Providers gelesen — Argos klont
nicht, um sie zu erkennen.

| Vertrag | Dateien | Was er steuert | Default bei Abwesenheit |
| --- | --- | --- | --- |
| **A — Ausführungsumgebung** | `.argos/worker.dockerfile` | Das Base-Image des Containers, *in dem* der Agent arbeitet (klonen, Deps installieren, Quality Gate ausführen). | Eingebauter Stack `php-8.4` (PHP 8.4 CLI + Composer + Node 22). |
| **B — Live-Demo** | `.argos/demo.compose.yml` + `.argos/demo.yml` | Ein ephemeres Vorschau-Deployment, das *nach* einem erfolgreichen Implement hochgefahren und unter einer eigenen Subdomain geroutet wird. | Mitgelieferter Laravel-Vertrag (`app` php-fpm/nginx + `mariadb` `db`). |

Sie sind **orthogonal**: Ein Repo kann die Standard-Ausführungsumgebung
beibehalten, aber eine Custom-Demo ausliefern, oder umgekehrt. Die
Entscheidungsstruktur ist für beide gleich:

```
Does the built-in default already fit this repo?
├─ yes → nothing to add. (For the demo, just enable it.)
└─ no  → choose ONE:
         ├─ Option 1 — bring your own .argos/ contract
         └─ Option 2 — adjust the project so the default fits
```

Wenn du bei einem "no" landest, wäge beide Optionen ab — der richtige Weg hängt
davon ab, wie weit das Projekt vom Default abweicht und ob du `.argos/`-Dateien
in deiner History haben möchtest.

Secrets werden **pro Projekt im Argos-UI** konfiguriert, niemals unter `.argos/`
committet. Siehe [Private Registries & Secrets](#private-registries--secrets).

---

## Teil A — Ausführungsumgebung

### Wie Argos sie nutzt

Für jede Aufgabe baut Argos (bei Bedarf) ein Worker-Image und führt einen
einzelnen Container aus. Darin führt der Agent folgende Schritte aus:

1. Klont das Repo und erstellt den Feature-Branch.
2. Installiert die erkannten Abhängigkeiten: `composer install`, wenn
   `composer.json` existiert, `npm ci`, wenn ein Lockfile existiert.
3. Führt den Agenten (Claude Code / Codex) aus, um die Aufgabe umzusetzen.
4. Führt das **Quality Gate** auf dem Ergebnis aus (siehe unten).

Das Standard-Image ist der eingebaute **`php-8.4`**-Stack: PHP 8.4 CLI mit den
üblichen Extensions (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `intl`, `zip`,
`bcmath`, `gd`, `pcntl`, `sockets`, `redis`, …), Composer, Git, `gh`, `jq` und
Node 22. Ein `php-8.3`-Stack existiert ebenfalls.

Für eine präzise, audit-taugliche Liste jedes Shell-/Docker-Befehls, den Argos
im Worker (und im Demo-Deployer) ausführt, siehe
[EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md).

### Das Quality Gate, das dein Projekt bestehen muss

Nachdem der Agent die Umsetzung abgeschlossen hat, führt der Worker eine Abfolge
von Gates aus. Ein Gate, das auf dein Repo zutrifft, muss bestehen, damit die
Phase erfolgreich ist; Gates, die nicht zutreffen, werden übersprungen. Der
Agent erhält pro fehlschlagendem Gate einige wenige fokussierte Fix-Versuche.

| Gate | Läuft, wenn… | Blockiert die Phase bei Fehler? |
| --- | --- | --- |
| **artisan smoke** | `artisan` existiert | ja — `php artisan list` muss die App booten |
| **Pint** (Style) | `vendor/bin/pint` existiert | ja — aber nur über Dateien, die der Agent **geändert** hat |
| **Pest** / **PHPUnit** | `vendor/bin/pest` oder `vendor/bin/phpunit` existiert | ja — aber nur bei Fehlern, die der Agent **neu einführt** (siehe Baseline unten) |
| **PHPStan** | `phpstan.neon`(`.dist`) **und** `vendor/bin/phpstan` existieren | ja |
| **Migrations-Syntax** | neue Dateien unter `database/migrations/` | ja — `php -l` auf jeder neuen Migration |
| **Debug-Code** | Nicht-Test-PHP-Dateien geändert | ja — `dd()`, `dump()`, `ray()`, `var_dump()`, `ddd()` werden abgelehnt |
| **Test-Präsenz** | neue Dateien unter `app/` | nein — nur eine Warnung |

Zwei Dinge machen dies nachsichtig, sodass das Gate auf realen Repos erreichbar
ist:

- **Pest/PHPUnit-Baseline.** Bevor der Agent irgendetwas anfasst, erfasst Argos,
  welche Tests auf dem sauberen Checkout *bereits* rot sind. Nur Fehler, die der
  Agent **neu einführt**, blockieren die Phase — bereits vorhandene rote Tests
  werden gemeldet, blockieren aber nie. (Keine Baseline erfasst → striktes
  Gating, jeder Fehler zählt.)
- **Infra-Crash-Skip.** Wenn ein Test-/PHPStan-Lauf an der Infrastruktur stirbt
  (OOM, kaputte Konfiguration, ein fehlendes Binary) statt an einem echten
  Befund, wird dieses Gate übersprungen statt als behebbarer Fehler behandelt.

Was das für "Argos-ready" bedeutet:

- Deine Test-Suite muss im Worker **laufen** (offline; SQLite/in-memory oder die
  MySQL/Redis-Backing-Services). Eine Suite, die nicht einmal starten kann,
  scheitert am Gate.
- Pint läuft nur über geänderte Dateien, daher sind bereits vorhandene
  Style-Schulden in Ordnung.
- Wenn du eine `phpstan.neon` ausliefern, halte eine `phpstan-baseline.neon` für
  bereits vorhandene Probleme bereit — dem Agenten wird gesagt, die
  baselinierten Einträge unangetastet zu lassen.

### Ist mein Projekt direkt einsatzbereit?

Wenn **alle** dieser Punkte mit ja beantwortet werden, funktioniert der
eingebaute Stack — tu nichts:

- [ ] Es ist ein PHP-Projekt (8.3/8.4) **oder** ein Node-Projekt — die Toolchain,
      die es braucht, ist PHP, Composer und/oder Node 22, sonst nichts.
- [ ] `composer install` (und `npm ci`, falls ein Lockfile vorhanden ist)
      gelingt ohne System-Packages über das Set des Stacks hinaus.
- [ ] Die Test-Suite läuft **offline** und verwendet entweder `sqlite` /
      `:memory:` **oder** spricht nur mit MySQL/Redis — was du als
      [Backing-Services](#backing-services-mysql--redis) aktivieren kannst.
- [ ] Keine exotische Runtime (Python, Go, Ruby, eine native Toolchain). Eine
      **private Composer-Registry** allein ist in Ordnung — sie braucht kein
      Custom Image; siehe
      [Private Registries & Secrets](#private-registries--secrets).

Jedes "no" → entweder ein [Custom Image (BYOI)](#bring-your-own-image-byoi) oder
[das Projekt anpassen](#adjust-the-project-instead).

### Backing-Services (MySQL / Redis)

Der Worker-Container besteht aus einem einzigen Image, aber Argos kann für den
Test-Lauf **Backing-Services** daneben hochfahren. Im **Worker**-Tab des Projekts
lässt dich das Feld **Backing services for tests** (`worker_services`) Folgendes
umschalten:

- **MySQL / MariaDB** (Image `mariadb:11`) — erreichbar unter Host `db`, Port
  `3306`.
- **Redis** (Image `redis:7-alpine`) — erreichbar unter Host `redis`, Port
  `6379`.

Jeder aktivierte Service kommt in einem **privaten Netzwerk pro Lauf** hoch und
wird danach wieder abgebaut. Argos injiziert die Standard-Laravel-Connection-Env
automatisch:

- MySQL → `DB_HOST=db`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`,
  `DB_PASSWORD` (Defaults `argos`/`argos`/`argos`, im Formular überschreibbar).
- Redis → `REDIS_HOST=redis`, `REDIS_PORT=6379`.

Ein Projekt, das die **Standard**-Env-Namen über `env()` liest, braucht also
keine zusätzliche Konfiguration. Wenn dein Projekt **nicht-standardisierte**
Env-Namen verwendet, verdrahte sie mit den Platzhaltern, die Argos im
Env-Secrets-Bereich bereitstellt — `${mysql.host}`, `${mysql.port}`,
`${mysql.database}`, `${mysql.username}`, `${mysql.password}`, `${redis.host}`,
`${redis.port}` — verwendbar innerhalb der Werte deiner eigenen
Env-Variablen (siehe
[Private Registries & Secrets](#private-registries--secrets)).

Genau diese Services und Credentials werden für die [Live-Demo](#part-b--live-demo)
wiederverwendet, wenn du keine eigene `.argos/demo.compose.yml` ausliefern.

Brauchst du einen Service, den Argos nicht anbietet (Postgres usw.) → führe ihn
innerhalb eines [Custom Worker Image (BYOI)](#bring-your-own-image-byoi) aus.

### Private Registries & Secrets

Auth-geschützte Composer-Registries (Private Packagist, Satis, Flux Pro,
Filament-Plugins, Scramble Pro, …) und jedes andere Secret, das der Build oder
die Tests benötigen, gehören **nicht** in `.argos/` — sie kommen in den
**Worker**-Tab des Projekts, in den Abschnitt **Environment & secrets**. Argos
speichert sie verschlüsselt und injiziert sie in **sowohl** den Worker als auch
die Live-Demo.

- **Private Composer-Registries** (`composer_registries`) — Host + Username +
  Token pro Zeile. Argos baut daraus einen einzigen `COMPOSER_AUTH`-http-basic-Blob,
  sodass `composer install` sie sowohl im Worker als auch in der Demo erreicht.
  (Username ist standardmäßig `token`, wenn leer gelassen.)
- **Zusätzliche Umgebungsvariablen** (`worker_env`) — beliebige `NAME` /
  Wert-Paare (Credentials, API-Keys oder ein handgeschriebener `COMPOSER_AUTH`,
  der dann Vorrang vor dem generierten hat). Werte dürfen die oben beschriebenen
  Backing-Service-Platzhalter verwenden.

Argos-eigene Keys können nicht durch ein Projekt-Secret überschrieben werden —
sie werden vor der Injektion entfernt. Dazu gehören `PHASE`, `TASK_ID`,
`REPO_URL`, `REPO_TOKEN`, `REPO_PLATFORM`, `BASE_BRANCH`, `AGENT_NAME`,
`APP_KEY`, `APP_URL`, `ASSET_URL`, `SESSION_COOKIE`, `ARGOS_DEMO`,
`CLAUDE_CODE_OAUTH_TOKEN`, `CODEX_AUTH_JSON_CONTENT` und die
Commit-Identity-Variablen.

Ein Projekt mit privaten Abhängigkeiten braucht daher **kein** Custom
Worker-Image nur für die Registry-Authentifizierung.

### Bring your own image (BYOI)

Wenn die eingebauten Stacks wirklich nicht passen — eine exotische Runtime, ein
System-Package, ein gepinntes Base-Image — liefere eine
`.argos/worker.dockerfile` aus. Sie ersetzt **nur das Base-Image**; Argos legt
weiterhin die Agent-CLI und seine eigenen Worker-Skripte obendrauf, also füge
**keinen** `ENTRYPOINT`/`CMD` hinzu und installiere den Agenten nicht selbst, und
das Base-Image muss Node.js, einen Nicht-Root-`agent`-User (UID `1000`) und die
Tools `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl` bereitstellen.

Die vollständige Referenz — das Datei-Template, der
Image-Validierungs-Vertrag, wie man es im Projektformular auswählt (**Image
source → Own Dockerfile in the repo (BYOI)**) und wie Rebuilds ausgelöst werden
— findet sich in [BYOI.md](BYOI.md). Starte dort für den Custom-Image-Weg.

### Stattdessen das Projekt anpassen

Oft günstiger als ein Custom Image — mache das Projekt zum Default-Stack
passend:

- Lass die Test-Suite auf SQLite / in-memory laufen (eine `sqlite`-`:memory:`-Connection
  in `phpunit.xml` / `.env.testing`), oder stütze dich auf die
  MySQL/Redis-Backing-Services.
- Füge eine `.env.example` hinzu, damit die Konfiguration im Worker auflöst.
- Entferne harte Abhängigkeiten von Services, die Argos nicht hochfahren kann
  (mocke sie, oder gate die betroffenen Tests).
- Wenn nur ein einziges zusätzliches System-Package fehlt, bevorzuge ein
  dreizeiliges BYOI-Dockerfile gegenüber dem Verbiegen des Projekts.

---

## Teil B — Live-Demo

### Wie Argos sie nutzt

Nachdem die Implement-Phase einer Aufgabe erfolgreich war, kann Argos eine
**ephemere Vorschau** des Arbeits-Branches deployen: Es mountet den
Task-Workspace in einen Container, führt deine Boot-Befehle aus und routet ihn
unter `demo-<task>.<base-domain>` via Traefik. Der Nutzer klickt eine URL an und
sieht die Änderung laufen.

Aktiviere sie pro Projekt mit dem Toggle **Enable live demo**
(`live_demo_enabled`) im **Worker**-Tab → Abschnitt **Live demo**. (Vorschauen
sind plattformseitig standardmäßig aktiviert; ein Operator kann sie global mit
`ARGOS_PREVIEW_ENABLED=false` deaktivieren.)

Argos liest zwei Dateien vom **Default-Branch**:

- **`.argos/demo.yml`** — Einstellungen: der geroutete Service, sein Port, wo der
  Workspace gemountet wird, die Boot-Befehle und ein Health-Probe.
- **`.argos/demo.compose.yml`** — der Compose-Stack (deine App plus alle Services,
  die sie braucht).

Auf deine Compose-Datei legt Argos ein **Override** (das schreibst du nicht), das
den Task-Workspace mountet, dem `argos_edge`-Netzwerk beitritt, `APP_URL` /
`ASSET_URL` / einen Wegwerf-`APP_KEY` / einen Demo-spezifischen `SESSION_COOKIE`
/ `ARGOS_DEMO=1` injiziert und CPU/Speicher begrenzt.

> **Beide Dateien sind zusammen erforderlich.** Bei aktiviertem Toggle ist das
> Ausliefern von nur einer ein harter Fehler — der Demo-Build schlägt mit einer
> klaren Meldung fehl, statt still auf den Default zurückzufallen. Liefere beide
> aus, oder keine.

Wenn das Repo **keine** der beiden ausliefert (aber der Toggle an ist),
verwendet Argos einen mitgelieferten Default für eine Standard-Laravel-App:
einen `app`-Container (php-fpm + nginx + Node) plus eine `mariadb` `db`, mit
diesen Boot-Befehlen:

```
composer install --no-interaction --prefer-dist --no-progress
[ -f .env ] || cp .env.example .env
php artisan migrate --force --seed
[ -f package.json ] && npm ci && npm run build || true
rm -f public/hot
php artisan storage:link || true
chown/chmod storage bootstrap/cache
```

Der mitgelieferte Default ist **mit den Backing-Services vereinheitlicht**
(Teil A): Die Demo-DB verwendet dieselben MySQL-Credentials, die du dort
konfigurierst (Default `demo`/`demo`/`demo`, falls du keine setzt), und ein
**Redis**-Service wird der Demo hinzugefügt, wenn du Redis aktivierst. Eine
Custom-`.argos/demo.compose.yml` wird nie automatisch modifiziert — dort hast du
die volle Kontrolle.

Für den genauen Deploy-Lebenszyklus, die Ausführung der Boot-Befehle und das
Health-Probing siehe
[EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md#the-demo-deployer).

### Ist mein Projekt direkt einsatzbereit?

Wenn **alle** Punkte mit ja beantwortet werden, funktioniert der mitgelieferte
Default — du musst die Demo nur **aktivieren**. Füge keine `.argos/demo.*`-Dateien
hinzu.

- [ ] Es ist eine Laravel-App, die über HTTP auf Port 80 ausgeliefert wird.
- [ ] Sie bootet mit `composer install` + `php artisan migrate --force --seed`
      und (falls es ein Frontend gibt) `npm ci && npm run build`.
- [ ] Eine `.env.example` existiert und die App bootet von ihr aus (ein
      Wegwerf-`APP_KEY` wird für dich injiziert — kein `key:generate` nötig).
- [ ] Der einzige Backing-Service, den sie braucht, ist **eine**
      MySQL/MariaDB-Datenbank (plus optional Redis, das der Default hinzufügt,
      wenn du es aktivierst).
- [ ] `GET /` liefert nach dem Booten 2xx/3xx zurück (wird als Health-Probe
      verwendet).

Jedes "no" → entweder [eigenen Vertrag ausliefern](#ship-your-own-contract) oder
[das Projekt anpassen](#adjust-the-project-instead-1).

### Eigenen Vertrag ausliefern

Schreibe **beide** Dateien, `.argos/demo.compose.yml` und `.argos/demo.yml`.
Harte Regeln für die Compose-Datei:

- **Keine Host-`ports:`** und **keine Traefik-Labels** — das Routing erledigt
  Argos über Traefiks File-Provider, nicht du.
- Für das Runtime-Image des mitgelieferten Defaults verwende den literalen
  Platzhalter `__ARGOS_DEMO_IMAGE__` (Argos ersetzt ihn durch einen
  content-gehashten Tag). Wenn du dein eigenes Image mitbringst (ein öffentliches
  oder ein Build-Target), setze es direkt.
- Lege deine App und ihre Services auf ein **privates** Netzwerk; Argos fügt dem
  Entry-Service additiv `argos_edge` hinzu.
- Der Entry-Service muss HTTP auf dem `entry.port` ausliefern, den du deklarierst.
- Cache die Konfiguration **nicht** (`config:cache`) in deinen Boot-Befehlen —
  Laravel muss die Env-Variablen, die Argos via `env()` injiziert, weiter lesen.

`.argos/demo.compose.yml`:

```yaml
# Stack for the post-implement preview. Argos layers an override on top
# (workspace mount, argos_edge alias, APP_URL/APP_KEY, resource limits).
# Do NOT add host ports: or Traefik labels.
services:
  app:
    image: __ARGOS_DEMO_IMAGE__   # or your own image
    working_dir: /var/www/html
    environment:
      APP_ENV: local
      APP_DEBUG: "true"
      LOG_CHANNEL: stderr
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: "3306"
      DB_DATABASE: demo
      DB_USERNAME: demo
      DB_PASSWORD: demo
      CACHE_STORE: file
      SESSION_DRIVER: file
      QUEUE_CONNECTION: sync
    networks: [demo-internal]
    depends_on:
      db:
        condition: service_healthy

  # Example extra service — drop or replace as needed.
  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: demo
      MARIADB_USER: demo
      MARIADB_PASSWORD: demo
      MARIADB_ROOT_PASSWORD: demo
    networks: [demo-internal]
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 30

networks:
  demo-internal:
```

`.argos/demo.yml`:

```yaml
# Which service Argos routes, and how the preview boots.
entry:
  service: app    # service name from demo.compose.yml
  port: 80        # internal HTTP port of that service

# Where the task's checked-out workspace is mounted in the entry service.
workspace_mount: /var/www/html

# Run in order inside the entry container after `compose up`. First failure
# fails the deploy. Output is captured in the demo build log.
commands:
  - composer install --no-interaction --prefer-dist --no-progress
  - "[ -f .env ] || cp .env.example .env"
  - php artisan migrate --force --seed
  - "[ -f package.json ] && npm ci && npm run build || true"
  - rm -f public/hot

# Best-effort readiness probe before the demo is marked live.
health:
  path: /
  timeout: 120
```

### Stattdessen das Projekt anpassen

Wenn das Projekt *fast* eine Standard-Laravel-App ist, behebe stattdessen die
Lücken:

- Füge eine `.env.example` hinzu, von der die App bootet.
- Sorge dafür, dass `php artisan migrate --force --seed` ohne manuelle Schritte
  funktioniert (saubere Migrations; Seeder, die nicht von externen Daten
  abhängen).
- Stelle sicher, dass `GET /` mit 2xx/3xx antwortet (eine Landing-Route oder ein
  Redirect zum Login).
- Wenn du nur einen anderen Boot-Befehl brauchst (z. B. einen Build-Schritt),
  brauchst du trotzdem **beide** Dateien — liefere `.argos/demo.yml` und eine
  minimale `.argos/demo.compose.yml` aus, die `__ARGOS_DEMO_IMAGE__`
  wiederverwendet.

---

## Abschluss-Checkliste

1. **Teil A** — entscheide: Eingebauter Stack ok? Falls nicht, nutze
   [BYOI](BYOI.md) oder passe das Projekt an, und stelle sicher, dass das
   [Quality Gate](#the-quality-gate-your-project-must-pass) laufen kann.
2. **Teil A** — aktiviere alle [Backing-Services](#backing-services-mysql--redis),
   die deine Tests brauchen, und lege Private-Registry-Auth / zusätzliche
   Secrets unter **Worker → Environment & secrets** ab — niemals in `.argos/`.
3. **Teil B** — entscheide: Mitgelieferte Demo ok? Falls nicht, liefere den
   Vertrag aus oder passe das Projekt an. Schalte **Enable live demo** ein, wenn
   du Vorschauen möchtest.
4. Wenn du `.argos/`-Dateien erstellt hast, stelle sicher, dass sie auf dem
   Branch committet sind, den Argos liest — der **Default-Branch** für den
   Demo-Vertrag, der **Base-Branch der Aufgabe** für `worker.dockerfile`.
5. Lege niemals Secrets in `.argos/`-Dateien ab — Tokens kommen aus den
   Argos-Credentials, und projektspezifische Secrets gehören unter
   **Worker → Environment & secrets**.
