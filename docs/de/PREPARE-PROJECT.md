# Ein Repository vorbereiten

Dieser Leitfaden richtet sich an **Entwicklerinnen und Entwickler, die ein
Repository für Argos vorbereiten**: was „Argos-ready“ bedeutet, welche Dateien
Argos aus `.argos/` liest, die **Einrichtungs-Aufgabe**, die sie für dich
schreibt, die Quality-Gates, Backing-Services und Secrets sowie der Vertrag für
die Live-Preview.

Laravel-Repositorys brauchen nichts: Argos wählt den PHP-Stack, bereitet jede
Aufgabe mit den Laravel-Standards vor und zeigt sie mit einem mitgelieferten
Vertrag als Preview. Für alles andere lässt die
[Einrichtungs-Aufgabe](#die-einrichtungs-aufgabe) Claude die Dateien schreiben
und testet sie.

Die Ausgabesprache für Dateien, die du in deinem Repo anlegst, ist
**Englisch** (Code, Kommentare, YAML-Kommentare). Erfinde keine Felder — jeder
Schlüssel unten entspricht einem echten, den Argos liest.

- [Was „Argos-ready“ bedeutet](#was-argos-ready-bedeutet)
- [Was Argos aus deinem Repo liest](#was-argos-aus-deinem-repo-liest)
- [Die Einrichtungs-Aufgabe](#die-einrichtungs-aufgabe)
- [Setup-Befehle (.argos/setup.sh)](#setup-befehle-argossetupsh)
- [Ausführungsumgebung](#ausfuhrungsumgebung)
  - [Stacks und eigenes Image](#stacks-und-eigenes-image)
  - [Die Quality-Gates](#die-quality-gates)
  - [Backing-Services (MySQL / Redis)](#backing-services-mysql-redis)
  - [Private Registries und Secrets](#private-registries-und-secrets)
- [Live-Preview](#live-preview)
  - [Der mitgelieferte Laravel-Vertrag](#der-mitgelieferte-laravel-vertrag)
  - [Eigenen Vertrag mitliefern](#eigenen-vertrag-mitliefern)
- [Abschluss-Checkliste](#abschluss-checkliste)

## Was „Argos-ready“ bedeutet

Ein Repository ist Argos-ready, wenn Argos im Workspace-Container **den
Worktree einer Aufgabe anlegen, ihre Abhängigkeiten installieren, Claude eine
Änderung umsetzen lassen und diese Änderung durch die Quality-Gates bringen**
kann — offline bis auf die Package-Registries, die du konfigurierst. Optional
**startet es auch als Live-Preview**.

In der Praxis:

1. Abhängigkeiten installieren sich mit einem
   [eingebauten Stack](WORKER-STACKS.md) (PHP 8.5, PHP 8.4 — beide mit
   Composer und Node 22 — oder Node 22) oder mit deiner eigenen
   `.argos/worker.dockerfile`.
2. Ein frischer Worktree wird mit den
   [Setup-Befehlen](#setup-befehle-argossetupsh) nutzbar — deiner
   `.argos/setup.sh` oder den Laravel-Standards.
3. Die Tests laufen **offline** und erreichen nur Services, die Argos
   hochfahren kann (MySQL / MariaDB, Redis), oder laufen auf SQLite / in-memory.
4. Für eine Preview: der [Preview-Vertrag](#live-preview) — dein eigener oder
   der mitgelieferte für Laravel.

## Was Argos aus deinem Repo liest

Alle Dateien liegen unter `.argos/` und sind optional. Argos liest sie über die
API des Git-Providers am **Setup-Ref** des Workspaces: dem Default-Branch —
oder, solange eine getestete Einrichtung noch nicht gemergt ist, dem Branch der
Einrichtungs-Aufgabe (siehe [unten](#die-einrichtungs-aufgabe)).

| Datei | Was sie steuert | Standard, wenn sie fehlt |
| --- | --- | --- |
| `.argos/setup.sh` | Schritte, die ein frischer Worktree einer Aufgabe ausführt, bevor Claude startet (Abhängigkeiten, `.env`, Schlüssel, Migrationen). | Das `composer setup`-Skript des Projekts, wenn `composer.json` eines hat, sonst die Laravel-Standards: `composer install`, `APP_KEY`, `php artisan migrate`. |
| `.argos/demo.compose.yml` + `.argos/demo.yml` | Die Live-Preview einer Aufgabe (beide Dateien oder keine). | Mitgelieferter Laravel-Vertrag (`app` php-fpm/nginx + MariaDB `db`). |
| `.argos/worker.dockerfile` | Das Image, in dem der Workspace-Container läuft (statt eines eingebauten Stacks). | Eingebauter Stack, gewählt anhand von `composer.json` / `package.json` (Standard PHP 8.5). |

Secrets gehören nie nach `.argos/` — sie gehören in die Workspace-Einstellungen
(siehe [Private Registries und Secrets](#private-registries-und-secrets)).

## Die Einrichtungs-Aufgabe

Wenn ein Workspace angelegt wird, prüft Argos das Repository:

| Fehlt | Gilt als fehlend, wenn |
| --- | --- |
| ein passender Stack | keine `composer.json`, keine `package.json` und keine `.argos/worker.dockerfile` |
| der Preview-Vertrag | keine Laravel-App (`composer.json` + `artisan`) und keine `.argos/demo.yml` |
| die Setup-Befehle | keine Laravel-App, keine `.argos/setup.sh` und kein `composer setup`-Skript |

Nach dem Anlegen startet Argos eine **Einrichtungs-Aufgabe**, auch wenn nichts
fehlt (eine Laravel-App mit den Standards braucht trotzdem ihren Test) — eine Aufgabe der Art
*Einrichtung*, die der Admin-Person gehört, die den Workspace angelegt hat,
über deren Claude-Abo läuft. Sie arbeitet im Modus *Berechtigungen umgehen*,
wenn diese Person ihn im Workspace nutzen darf, sonst in *Änderungen
annehmen*; im Eingabefeld lässt sich der Modus während der Aufgabe wechseln. Claude
bekommt ein Briefing (was fehlt, das erkannte Projekt, der Stack; der Chat zeigt
eine einzeilige Zusammenfassung), antwortet in der Sprache der Besitzerin oder
des Besitzers und schreibt:

1. `.argos/setup.sh` — idempotente, nicht-interaktive Setup-Befehle,
2. `.argos/demo.compose.yml` und `.argos/demo.yml` — den Preview-Vertrag,
3. `.argos/worker.dockerfile` — nur, wenn kein eingebauter Stack passt.

Claude probiert `.argos/setup.sh` im Worktree der Aufgabe aus. Endet ein
Durchgang mit Änderungen unter `.argos/`, startet Argos **Einrichtung testen**
selbst, höchstens fünfmal in Folge, bis wieder jemand im Chat schreibt; der
Button im Banner der Aufgabe startet den Test von Hand. Secrets bleiben
aus den Dateien heraus; Claude nennt die, die du unter Einstellungen → Umgebung
hinterlegen musst. Kann Claude nicht erkennen, wie das Projekt laufen soll,
fragt es im Chat nach.

### Einrichtung testen

**Einrichtung testen** führt vier Schritte aus und stoppt beim ersten, der
fehlschlägt:

| Schritt | Besteht, wenn |
|---|---|
| Pull Request | Die Dateien sind committet, der Branch ist gepusht und ein Pull Request ist geöffnet (*chore: set up the Argos workspace*). Ein erneuter Test ohne Änderungen nutzt ihn wieder. |
| Image und Tools | Mit einer `.argos/worker.dockerfile`: Das Image baut und hat die nötigen Tools. Mit einem eingebauten Stack: nichts zu bauen. |
| Setup-Befehle in frischer Arbeitskopie | `.argos/setup.sh` des Branches läuft in einem neuen Worktree mit eigener Wegwerf-Datenbank durch. |
| Preview und Health-Check | Die Preview der Einrichtungs-Aufgabe startet und ihr Health-Check ist grün. Übersprungen (gilt als bestanden), wenn Previews für die Installation oder den Workspace aus sind. |

Das Log eines fehlgeschlagenen Schritts geht an Claude in der Aufgabe, mit der
Bitte, es zu beheben; Claudes Korrektur stößt den nächsten Test an. Der Chat und
**Einstellungen → Umgebung → Einrichtung** zeigen das Ergebnis pro Schritt.

**Ein bestandener Test gilt sofort**: Neue Aufgaben führen die Setup-Befehle
und Previews vom Einrichtungs-Branch aus, und der Workspace nutzt das eigene
Image des Repositorys, wenn der Branch eines hat. Die Einstellungen zeigen
*Getestete Einrichtung aus `<branch>` — gilt für neue Aufgaben, bis ihr Pull
Request gemergt ist.* Nach dem Merge kommt alles wieder vom Default-Branch.
Wird der Pull Request ohne Merge geschlossen, entfällt der Branch und der
Status geht zurück auf *Einrichtung nötig*.

### Einrichtungs-Checkliste

Beim Anlegen eines Workspaces schaltet Argos außerdem die Backing Services ein,
die das Repository erwartet: MySQL, wenn `.env.example` oder
`config/database.php` auf `mysql`/`mariadb` stehen, Redis, wenn ein Cache-,
Session- oder Queue-Treiber es nutzt oder das Projekt Horizon oder Predis braucht.

Workspace-Admins sehen auf der Workspace-Seite eine Checkliste, solange ein
Pflichtschritt offen ist — Claude-Abo, Stack, getestete Einrichtung und, bei
eingeschalteter Live-Demo, die Demo-ENVs — und die ganze Liste samt optionaler
Schritte oben in der Seitenleiste. Jeder offene Schritt verlinkt dorthin, wo
er sich erledigen lässt.

### Stand der Einrichtung

| Status | Bedeutung |
|---|---|
| Einrichtung nötig | Etwas fehlt, oder das Repository konnte nicht gelesen werden. |
| Noch nicht getestet | Nichts fehlt (etwa eine Laravel-App mit den Standards), aber noch kein Test hat bestanden — oder die automatischen Tests haben nach fünf Runden aufgehört. |
| Wird eingerichtet | Die Einrichtungs-Aufgabe ist offen. |
| Wird getestet | **Einrichtung testen** läuft. |
| Eingerichtet | Der letzte Test hat bestanden. |
| Test fehlgeschlagen | Der letzte Test ist fehlgeschlagen — sein Log steht in der Einrichtungs-Aufgabe. |
| Braucht ein Claude-Abo | Wer die Einrichtungs-Aufgabe ausführen soll, hat keinen nutzbaren Agenten. |

Quality Gates, Previews und automatische Aufgaben warten auf *Eingerichtet*:
bis dahin nennt die Seitenleiste den Stand und verlinkt hierher, und ein Start
wird abgelehnt. Die Einrichtungs-Aufgabe selbst ist ausgenommen, denn mit ihr
wird die Einrichtung getestet.

**Einstellungen → Umgebung → Einrichtung** (Workspace-Admins) zeigt den
Status, den letzten Test, woher Preview-Vertrag und Setup-Befehle kommen
(*im Repository*, *Laravel-Standard*, *fehlt*), das aktuelle Image mit seinem
Build-Log und **Neu bauen** sowie **Einrichtung überarbeiten** — das öffnet die
Einrichtungs-Aufgabe oder startet eine neue, auch wenn nichts fehlt, über dein
Abo.

## Setup-Befehle (.argos/setup.sh)

Jede neue Aufgabe führt ihre Setup-Befehle in ihrem eigenen Worktree aus, bevor
Claude startet:

1. Argos legt die Datenbank der Aufgabe (`task_<slug>`) auf dem MySQL-Sidecar
   an, wenn MySQL aktiviert ist.
2. Der Daemon schreibt die Werte der Aufgabe in die `.env` (aus
   `.env.example`, wenn es keine `.env` gibt): `DB_CONNECTION=mysql`,
   `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
   `REDIS_HOST`, `REDIS_PORT`, `REDIS_PREFIX` und `CACHE_PREFIX` (`<slug>_`) —
   nur für die Services, die der Workspace aktiviert hat. Dieselben Werte
   überschreiben die Container-Umgebung für alles im Worktree.
3. Er führt `bash .argos/setup.sh` im Wurzelverzeichnis des Worktrees aus —
   oder, ohne die Datei, die Laravel-Standards:

   ```
   composer install --no-interaction --no-progress --prefer-dist   # if composer.json exists
   php artisan key:generate --force --no-interaction               # if .env has an empty APP_KEY
   php artisan migrate --force --no-interaction                    # if artisan + vendor/ exist
   ```

Regeln für deine eigene `.argos/setup.sh`:

- Idempotent und nicht-interaktiv — sie kann in einem wieder aufgenommenen
  Worktree laufen.
- Nur dann mit einem Exit-Code ungleich null beenden, wenn die Umgebung
  wirklich unbrauchbar ist. Der Chat meldet dann, dass die Setup-Befehle
  fehlgeschlagen sind, und verlinkt das Log; die Aufgabe bleibt nutzbar und
  Claude kann sich das ansehen.
- Nutze die Werte aus `.env` / der Umgebung; keine fest eingetragenen
  Datenbanknamen (jede Aufgabe hat ihre eigene).
- Die Ausgabe landet im Setup-Log, das der Chat zeigt.
- Composer- und npm-Caches werden zwischen Aufgaben geteilt, wiederholte
  Installationen sind also schnell.

## Ausführungsumgebung

### Stacks und eigenes Image

Der Workspace-Container läuft auf einem eingebauten Stack — **PHP 8.5**
(Standard), **PHP 8.4** oder **Node 22** — oder auf deiner
`.argos/worker.dockerfile`. Siehe [Stacks und Images](WORKER-STACKS.md) und,
für den Dockerfile-Vertrag, [Eigenes Worker-Image](BYOI.md).

Eine genaue Liste aller Shell-/Docker-Befehle, die Argos im Workspace und für
Previews ausführt, steht in [EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md).

### Die Quality-Gates

Die Gates führen eine feste Abfolge von Prüfungen im Worktree der Aufgabe aus.
Ein Gate, das auf dein Repo zutrifft, muss bestehen; Gates, die nicht
zutreffen, werden übersprungen. Ein fehlgeschlagenes Gate lässt sich Claude als
Fix-Prompt übergeben — eine automatische Aufgabe tut das selbst, bis zu ihrem
Limit an Fix-Runden.

| Gate | Läuft, wenn… | Blockiert bei Fehler? |
| --- | --- | --- |
| **artisan smoke** | `artisan` existiert | ja — `php artisan list` muss die App booten |
| **Pint** (Style) | `vendor/bin/pint` existiert | ja — aber nur über Dateien, die die Aufgabe **geändert** hat |
| **Pest** / **PHPUnit** | `vendor/bin/pest` oder `vendor/bin/phpunit` existiert | ja — aber nur bei Fehlern, die die Aufgabe **neu einführt** |
| **PHPStan** | `phpstan.neon`(`.dist`) **und** `vendor/bin/phpstan` existieren | ja — nur bei neuen Fehlern |
| **Migrations-Syntax** | neue Dateien unter `database/migrations/` | ja — `php -l` auf jeder neuen Migration |
| **Debug-Code** | Nicht-Test-PHP-Dateien geändert | ja — `dd()`, `dump()`, `ray()`, `var_dump()`, `ddd()` werden abgelehnt |
| **Test-Präsenz** | neue Dateien unter `app/` | nein — nur eine Warnung |

- **Baseline pro Basis-Commit.** Argos hält einmal fest, welche Tests und
  PHPStan-Fehler auf dem Basis-Commit bereits scheitern; nur Fehler, die eine
  Aufgabe **einführt**, blockieren. Keine Baseline → jeder Fehler zählt.
- **Infra-Crash-Skip.** Ein Test-/PHPStan-Lauf, der an der Infrastruktur
  stirbt (OOM, kaputte Konfiguration, ein fehlendes Binary), wird übersprungen
  statt als behebbarer Fehler behandelt.

Also: Deine Test-Suite muss im Worktree **laufen** (offline; SQLite/in-memory
oder die Backing-Services), Pint-Altlasten sind in Ordnung, und eine
`phpstan-baseline.neon` beschränkt PHPStan auf neue Probleme.

### Backing-Services (MySQL / Redis)

In **Einstellungen → Umgebung** schaltet *Backing-Services für Tests*:

- **MySQL / MariaDB** (Image `mariadb:11`) — Host `db`, Port `3306`.
- **Redis** (Image `redis:7-alpine`) — Host `redis`, Port `6379`.

Sie laufen als Sidecars des Workspaces in einem privaten Netzwerk; ihre Daten
bleiben erhalten, bis der Workspace gelöscht wird. Jede Aufgabe bekommt ihre
eigene Datenbank und ihr eigenes Redis-Präfix (siehe
[Setup-Befehle](#setup-befehle-argossetupsh)). Die MySQL-Zugangsdaten lassen
sich im Formular überschreiben (Standard `argos` / `argos` / `argos`); den
Datenbanknamen pro Aufgabe setzt Argos.

Für **abweichende** Env-Namen nutzt du die Platzhalter in den Werten deiner
Umgebungsvariablen: `${mysql.host}`, `${mysql.port}`, `${mysql.database}`,
`${mysql.username}`, `${mysql.password}`, `${redis.host}`, `${redis.port}` —
siehe [Workspace-Einstellungen](WORKSPACE-SETTINGS.md#environment-variablen-und-platzhalter).

Du brauchst einen anderen Service (Postgres, …)? Lass ihn in deinem eigenen
Image oder im Preview-Vertrag laufen.

### Private Registries und Secrets

Auth-pflichtige Composer-Registries und jedes andere Secret, das Build oder
Tests brauchen, gehören nach **Einstellungen → Umgebung → Environment &
Secrets**, nie nach `.argos/`:

- **Private Composer-Registries** — Host + Username + Token pro Zeile; Argos
  baut daraus ein `COMPOSER_AUTH` für den Workspace und die Preview.
- **Weitere Environment-Variablen** — Name/Wert-Paare (ein selbst
  geschriebenes `COMPOSER_AUTH` hat Vorrang vor dem generierten).

Argos-eigene Schlüssel (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, `ASSET_URL`,
`SESSION_COOKIE`, `ARGOS_DEMO`, `CLAUDE_CODE_OAUTH_TOKEN`, die
Daemon-Verdrahtung, …) lassen sich nicht überschreiben. Eine private Registry
braucht **kein** eigenes Image.

## Live-Preview

Die Preview einer Aufgabe mountet den Worktree der Aufgabe in einen kleinen
Container-Stack, führt die Boot-Befehle des Vertrags aus und routet ihn unter
einer eigenen Subdomain, geschützt durch das Workspace-Gate (nur Mitglieder).
Sie braucht den Schalter **Live-Demo aktivieren** des Workspaces
(Einstellungen → Umgebung) und `ARGOS_PREVIEW_ENABLED` (standardmäßig an).
Siehe [Previews](LIVE-DEMOS.md).

Argos liest zwei Dateien am Setup-Ref (eine Einrichtungs-Aufgabe liest sie von
ihrem eigenen Branch):

- **`.argos/demo.yml`** — der geroutete Service und Port, wo der Worktree
  gemountet wird, die Boot-Befehle und die Health-Probe.
- **`.argos/demo.compose.yml`** — der Compose-Stack.

Über dein Compose legt Argos ein **Override** (das schreibst du nicht), das
den Worktree mountet, dem Netzwerk `argos_edge` beitritt, `APP_URL` /
`ASSET_URL` / einen Wegwerf-`APP_KEY` / ein `SESSION_COOKIE` pro Preview /
`ARGOS_DEMO=1` injiziert und CPU/Arbeitsspeicher begrenzt.

> **Beide Dateien gehören zusammen.** Nur eine mitzuliefern ist ein harter
> Fehler — der Preview-Build schlägt mit einer klaren Meldung fehl. Liefere
> beide mit oder keine.

### Der mitgelieferte Laravel-Vertrag

Ohne `.argos/demo.*` nutzt Argos einen mitgelieferten Vertrag für eine
Standard-Laravel-App: einen `app`-Container (php-fpm + nginx + Node) und eine
MariaDB `db`, mit diesen Boot-Befehlen:

```
composer install --no-interaction --prefer-dist --no-progress
[ -f .env ] || cp .env.example .env
php artisan migrate --force --seed
[ -f package.json ] && npm ci && npm run build || true
rm -f public/hot
php artisan storage:link || true
chown/chmod storage bootstrap/cache
```

Die Preview-Datenbank nutzt die MySQL-Zugangsdaten der Backing-Services
(`demo` / `demo` / `demo`, wenn keine gesetzt sind); ein Redis-Service kommt
dazu, wenn Redis aktiviert ist. Die Preview hat ihre eigene Datenbank — sie
nutzt nicht die Arbeitsdatenbank der Aufgabe.

Er passt, wenn: die App HTTP auf Port 80 ausliefert, mit `composer install` +
`php artisan migrate --force --seed` (+ `npm ci && npm run build`) bootet,
eine `.env.example` hat, nur eine MySQL-Datenbank braucht (plus optional
Redis) und `GET /` mit 2xx/3xx antwortet.

### Eigenen Vertrag mitliefern

Schreibe **beide**, `.argos/demo.compose.yml` und `.argos/demo.yml`. Regeln
für die Compose-Datei:

- **Keine Host-`ports:`** und **keine Traefik-Labels** — das Routing übernimmt
  Argos.
- Für das mitgelieferte Runtime-Image nutze den Platzhalter
  `__ARGOS_DEMO_IMAGE__`; sonst gib dein Image an.
- Leg deine Services in ein **privates** Netzwerk oder lass `networks:` weg,
  dann teilen sie das implizite Netz `default`. Argos fügt dem Entry-Service
  `argos_edge` hinzu und lässt ihn in `default`.
- Der Entry-Service liefert HTTP auf dem angegebenen `entry.port` aus.
- Cache die Konfiguration **nicht** (`config:cache`) in den Boot-Befehlen —
  Laravel muss die Env-Variablen, die Argos injiziert, weiter lesen.
- Argos startet den Stack auf dem Docker des Hosts und akzeptiert deshalb pro
  Service nur eine sichere Auswahl: `image`, `command`, `entrypoint`,
  `environment`, `working_dir`, `user`, `depends_on`, `healthcheck`,
  `networks`, `volumes`, `tmpfs`, `restart`, `expose`, `stop_grace_period`,
  `stop_signal`, `init`, `hostname`. Volumes müssen **benannte** Volumes
  sein, die unter `volumes:` deklariert sind (keine Host-Pfade, keine
  Treiber-Optionen); Netzwerke außer `default` müssen unter `networks:`
  deklariert sein (nicht extern). `build`, `privileged`, Capabilities, Devices, `network_mode`, `pid`,
  `env_file` und `ports` werden mit klarer Meldung abgelehnt. Jeder Service
  läuft mit `no-new-privileges` und einem Prozesslimit.

`.argos/demo.compose.yml`:

```yaml
# Stack for the task preview. Argos layers an override on top
# (worktree mount, argos_edge alias, APP_URL/APP_KEY, resource limits).
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

# Where the task's worktree is mounted in the entry service.
workspace_mount: /var/www/html

# Run in order inside the entry container after `compose up`. First failure
# fails the deploy. Output is captured in the build log.
commands:
  - composer install --no-interaction --prefer-dist --no-progress
  - "[ -f .env ] || cp .env.example .env"
  - php artisan migrate --force --seed
  - "[ -f package.json ] && npm ci && npm run build || true"
  - rm -f public/hot

# Readiness probe before the preview is marked live.
health:
  path: /
  timeout: 120
```

Zwei optionale Schlüssel helfen der Demo selbst:

```yaml
env:
  required: [STRIPE_SECRET]   # ohne diese startet die App nicht
login:
  path: /login
  email: admin@example.com
  password: secret
```

Schlüssel unter `env.required`, die weder in `.env.example` noch unter
Einstellungen → Umgebung einen Wert haben, sperren die Preview, bis sie gesetzt
sind. Einstellungen → Umgebung → Einrichtung listet sie, dazu jeden anderen
Schlüssel, der in `.env.example` leer ist und nirgends gesetzt wird (ein
Hinweis, keine Sperre). `login` erscheint auf der Preview-Karte. Nach den
Demo-Befehlen zählt Argos die Zeilen in der Datenbank einer Laravel-Demo; eine
Demo ohne Daten außerhalb von `migrations` bekommt eine Warnung im Log. Argos
übernimmt außerdem das `DB_CONNECTION` des Repos, wenn es `mysql` oder
`mariadb` ist.

## Abschluss-Checkliste

1. Laravel-App? Die Standards passen meist; die Einrichtungs-Aufgabe prüft und
   ergänzt sie und testet das Ergebnis.
2. Sonst lass die [Einrichtungs-Aufgabe](#die-einrichtungs-aufgabe) `.argos/`
   schreiben (oder schreib es selbst), lass **Einrichtung testen** laufen, bis alle
   vier Schritte bestehen, und merge ihren Pull Request.
3. Aktiviere die [Backing-Services](#backing-services-mysql-redis), die deine
   Tests brauchen; Registry-Zugänge und Secrets kommen nach Einstellungen →
   Umgebung — nie nach `.argos/`.
4. Schalte **Live-Demo aktivieren** ein, wenn du Previews willst.
5. Sorg dafür, dass die Gates laufen können: Tests offline,
   `phpstan-baseline.neon` für bestehende PHPStan-Probleme.
