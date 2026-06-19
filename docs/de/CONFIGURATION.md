# Konfigurationsreferenz

Die gesamte Argos-Konfiguration wird über Umgebungsvariablen gesteuert, die an
die Services `app` (sowie `queue` / `scheduler`) im Compose-Stack übergeben
werden — oder über deine `.env`-Datei beim lokalen Betrieb. Diese Seite ist die
Betreiberreferenz für jede ENV-Variable, die Argos tatsächlich liest.

> [!NOTE]
> Argos läuft lokal ohne jede Konfiguration — SQLite, alle Defaults. Überschreibe
> nur, was du brauchst. Zwei Grenzen sind zu beachten:
>
> - **`APP_KEY`** wird beim ersten Manager-Start automatisch erzeugt und
>   persistiert; du setzt sie nur, wenn du ein Backup wiederherstellst.
> - **OAuth-Apps und die Claude-/Codex-Credentials werden über die UI/DB
>   verwaltet, nicht per ENV.** Siehe [OAuth & Credentials](#oauth--credentials-ui-managed)
>   weiter unten.

## Inhalt

- [Core / App & URL](#core--app--url)
- [Sessions (Reverse Proxy / HTTPS)](#sessions-reverse-proxy--https)
- [Datenbank](#database)
- [Queue, Redis & Horizon](#queue-redis--horizon)
- [Worker (Phasen-Container)](#worker-phase-containers)
- [Worker-Hintergrunddienste (Test-Sidecars)](#worker-backing-services-test-sidecars)
- [Live-Demos (optional)](#live-demos-optional)
- [Integrationen & Polling](#integrations--polling)
- [OAuth & Credentials (UI-verwaltet)](#oauth--credentials-ui-managed)
- [MCP-Server (Argos-API)](#mcp-server-argos-api)
- [Zielprojekt-MCP (Laravel Boost)](#target-project-mcp-laravel-boost)
- [Media Library (optional)](#media-library-optional)
- [Logging](#logging)
- [Compose-/OPS-Level-Schlüssel](#compose--ops-level-keys)

## Core / App & URL

| Variable | Default | Zweck |
|---|---|---|
| `APP_NAME` | `Argos` | Anzeigename, der im Browser-Titel und in Headern verwendet wird. Bildet außerdem den Horizon-Redis-Präfix. |
| `APP_ENV` | `production` | `local`, `staging` oder `production`. Schaltet Dev-only-Werkzeuge um (z. B. ist der One-Click-Entwickler-Login nur unter `local` verfügbar). |
| `APP_KEY` | beim ersten Start automatisch erzeugt | Laravel-Verschlüsselungsschlüssel. Nur beim Wiederherstellen von Backups fest setzen. |
| `APP_PREVIOUS_KEYS` | – | Kommagetrennte Liste früherer `APP_KEY`-Werte, die für das Entschlüsseln alter Daten nach einer Schlüsselrotation verfügbar bleiben. |
| `APP_DEBUG` | `false` | Aktiviert detaillierte Fehlerseiten. **Niemals in der Produktion aktivieren.** |
| `APP_URL` | `http://localhost` | Basis-URL der Argos-Instanz und die **einzige Quelle der Wahrheit** für Host + Schema. OAuth-Callbacks, die Domain des Session-Cookies und die Live-Demo-Subdomains leiten sich alle daraus ab. **Muss mit der öffentlichen URL übereinstimmen.** |
| `APP_LOCALE` | `en` | Standard-UI-Sprache (`en` oder `de`). |
| `ADMIN_PASSWORD` | `12345` | Passwort für den automatisch erstellten Admin-Benutzer. **Vor dem Veröffentlichen der Instanz ändern.** |
| `ARGOS_CONFIG_DIR` | `~/.config/argos` (Compose: `/data/config`) | Verzeichnis für persistierte Konfiguration / den Standard-SQLite-Pfad innerhalb des Managers. |
| `ARGOS_SOURCE_URL` | `https://github.com/nodus-it/argos` | In der UI angezeigte Source-Offer-URL gemäß AGPL-3.0 §13. **Forks müssen dies überschreiben** mit ihrer eigenen Source-URL. |
| `ARGOS_VERSION` | – | Überschreibt den eingebackenen App-Versionsstring. CI backt einen `stage-…`-Wert in Stage-Images; für Releases nicht setzen. |

## Sessions (Reverse Proxy / HTTPS)

Beide Werte **leiten sich aus `APP_URL` ab** und müssen selten gesetzt werden:

| Variable | Default | Zweck |
|---|---|---|
| `SESSION_DOMAIN` | abgeleitet: `.<APP_URL host>` für eine echte Domain, nur Host für localhost/IP/`nip.io` | Cookie-Domain. Der führende Punkt lässt die Session sich über Demo-Subdomains spannen (`demo-<task>.<host>`). Nur überschreiben, wenn Demos auf einer anderen Domain als die App liegen. |
| `SESSION_SECURE_COOKIE` | abgeleitet: `true`, wenn `APP_URL` `https://` ist | Erzwingt `Secure` auf dem Session-Cookie. Nur explizit setzen, wenn TLS an einem Proxy terminiert wird, der das Schema umschreibt. |
| `SESSION_COOKIE` | `argos_session` | Name des Session-Cookies. Wird selten geändert. |

## Datenbank

`DB_CONNECTION` wählt den Treiber. SQLite ist der Default und benötigt keine
weitere Konfiguration. Setze es auf `mariadb`, um den MariaDB-Sidecar (Compose)
oder einen externen Server zu verwenden. Der Compose-Stack setzt `mariadb`.

| Variable | Default | Zweck |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | `sqlite` oder `mariadb`. |
| `DB_DATABASE` | `~/.config/argos/argos.db` | SQLite-Dateipfad. Bei `mariadb` ignoriert. |
| `ARGOS_DB_HOST` | `127.0.0.1` | MariaDB-Host. Der Compose-Stack setzt `db`. |
| `ARGOS_DB_PORT` | `3306` | MariaDB-Port. |
| `ARGOS_DB_DATABASE` | `argos` | Datenbankname. |
| `ARGOS_DB_USERNAME` | `argos` | Datenbankbenutzer. |
| `ARGOS_DB_PASSWORD` | – (leer) | Datenbankpasswort. |
| `ARGOS_DB_SSL_CA` | – | Optionaler Pfad zu einem TLS-CA-Bundle für die MariaDB-Verbindung. |
| `ARGOS_DB_URL` | – | Vollständiger DSN — überschreibt die einzelnen Felder oben. |

## Queue, Redis & Horizon

Hintergrund-Jobs — Task-Phasenläufe und Issue-Polling — laufen über **Laravel
Horizon**, gestützt auf **Redis**. Im Compose-Stack werden der `redis`-Service
sowie die `queue`-(Horizon-) + `scheduler`-Worker automatisch verdrahtet; du
stimmst normalerweise nur die Prozessanzahlen ab.

| Variable | Default | Zweck |
|---|---|---|
| `QUEUE_CONNECTION` | `database` (Compose: `redis`) | Queue-Treiber. Der Compose-Stack setzt `redis`, damit Horizon Jobs verarbeitet; reine `artisan serve`-Entwicklung und die Test-Suite fallen auf die Datenbank-Queue zurück. |
| `ARGOS_REDIS_HOST` | fällt zurück auf `REDIS_HOST`, dann `redis` | Redis-Host. Der Compose-Stack setzt `REDIS_HOST=redis`. |
| `ARGOS_REDIS_PORT` | fällt zurück auf `REDIS_PORT`, dann `6379` | Redis-Port. |
| `ARGOS_REDIS_PASSWORD` | fällt zurück auf `REDIS_PASSWORD` | Redis-Passwort (falls dein Redis Authentifizierung verlangt). |
| `ARGOS_QUEUE_DEFAULT_PROCESSES` | `5` | Horizon-Worker-Prozesse für die `default`-Queue (`minProcesses` = `maxProcesses`). |
| `ARGOS_QUEUE_TASKS_PROCESSES` | `2` | Horizon-Worker-Prozesse für die `tasks`-Queue (Phasenläufe). |

> `ARGOS_REDIS_*` haben Vorrang; die einfachen Laravel-Defaults `REDIS_HOST` /
> `REDIS_PORT` / `REDIS_PASSWORD` werden als Fallback berücksichtigt (und sind
> das, was der Compose-Stack tatsächlich setzt).

## Worker (Phasen-Container)

Jede Task-Phase läuft in einem ephemeren Worker-Container. Diese steuern die
Image-Auswahl und die Ressourcenlimits pro Container.

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_DEFAULT_STACK` | `php-8.4` | Slug des Worker-Stacks, der verwendet wird, wenn weder der Task noch das Projekt einen festlegt. Muss zu einer Zeile in `worker_stacks` passen (Built-ins werden bei `migrate` gespiegelt). |
| `ARGOS_MEM_LIMIT` | `4g` | Speicherlimit pro Worker-Container. |
| `ARGOS_CPU_LIMIT` | `2` | CPU-Limit pro Worker-Container. |
| `ARGOS_CONCEPT_MAX_TURNS_DEFAULT` | `50` | Standard-max-turns für die Concept-Phase (pro Task überschreibbar). max-turns ist eine Obergrenze, kein Budget — gut umrissene Tasks sind unabhängig davon früh fertig; große Repos brauchen den Spielraum, um zu erkunden *und* zu schreiben. |
| `ARGOS_MAX_TURNS_DEFAULT` | `200` | Standard-max-turns für die Implement-Phase (pro Task überschreibbar). |

## Worker-Hintergrunddienste (Test-Sidecars)

Argos kann ephemere Backing-Service-Sidecars hochfahren (ein privates Netzwerk
pro Phasenlauf, anschließend wieder abgebaut), damit die Tests eines Projekts
mit einem echten MySQL/Redis sprechen können. Ein Repo-Profil meldet sich pro
Service an; nur die testlaufenden Phasen starten sie.

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_WORKER_SERVICE_TIMEOUT` | `60` | Sekunden, die auf die Bereitschaft eines Backing-Service gewartet wird, bevor die Phase fehlschlägt. |
| `ARGOS_WORKER_MYSQL_IMAGE` | `mariadb:11` | Image für den MySQL-/MariaDB-Sidecar. |
| `ARGOS_WORKER_REDIS_IMAGE` | `redis:7-alpine` | Image für den Redis-Sidecar. |

## Live-Demos (optional)

Ephemere Demo-Deployments pro Task, geroutet von Traefik unter ihrer eigenen
Subdomain. Standardmäßig auf Plattformebene aktiviert, aber ein Toggle pro
Projekt (*Live-Demo* / `live_demo_enabled`) ist das eigentliche Gate, das jedes
Projekt freischaltet. Basis-Domain + Schema **leiten sich aus `APP_URL` ab** —
nur überschreiben für eine Demo-Domain, die sich von der App unterscheidet.
Erfordert Wildcard-DNS `*.<host>`, das auf diesen Host auflöst (sowie die
Traefik- + `argos_edge`-Preview-Infrastruktur).

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_PREVIEW_ENABLED` | `true` (Compose leitet `false` weiter) | Hauptschalter für die Live-Demo-Infrastruktur. Setze `false`, um plattformweit zu deaktivieren (z. B. keine Traefik-/Preview-Infrastruktur). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | abgeleitet vom `APP_URL`-Host (`127.0.0.1.nip.io` für bare/localhost) | Demos liegen unter `demo-<task>.<base_domain>`. |
| `ARGOS_PREVIEW_SCHEME` | abgeleitet vom `APP_URL`-Schema | `http` oder `https`, das in der Demo-URL verwendet wird. |
| `ARGOS_PREVIEW_PORT` | fällt zurück auf `ARGOS_PORT`, dann `8080` | Externer Port, unter dem die Demo-URL erreichbar ist (der öffentliche Endpunkt, unabhängig vom Host-Port, an den Traefik bindet). Hinter einem TLS-Proxy auf 443 setze `scheme=https` und `ARGOS_PREVIEW_PORT=443`, damit die URL den Port weglässt. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | Stunden, bevor eine inaktive Demo abgebaut wird. |
| `ARGOS_PREVIEW_AUTH` | `none` | Stack-weiter Standard-Zugriffsschutz für Demos, die auf *inherit* gesetzt sind (`none` / `session` / `basic`); Überschreibungen pro Task gewinnen. |
| `ARGOS_PREVIEW_BASIC_USER` | `demo` | HTTP-Basic-Benutzername für `basic`-geschützte Demos. |
| `ARGOS_PREVIEW_BASIC_PASSWORD` | – | Globaler HTTP-Basic-Passwort-Fallback für Tasks, die lediglich den `basic`-Default erben (Passwörter pro Task werden erzeugt, wenn ein Task auf `basic` umgeschaltet wird). |
| `ARGOS_PREVIEW_AUTH_GATE_URL` | `http://nginx:80/_argos/demo-gate` | Interne URL, die Traefiks forwardAuth-Middleware aufruft, um die Argos-Session für `session`-geschützte Demos zu validieren. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Obergrenze gleichzeitig laufender Demos (`0` = unbegrenzt; bei Überschreitung werden die ältesten Demos anderer Tasks verdrängt). |
| `ARGOS_PREVIEW_CPU_LIMIT` | `1.0` | CPU-Limit pro Demo (getrennt von den Worker-Limits). |
| `ARGOS_PREVIEW_MEM_LIMIT` | `1g` | Speicherlimit pro Demo. |
| `ARGOS_PREVIEW_NETWORK` | `argos_edge` | Externes Docker-Netzwerk, das mit Traefik geteilt wird (definiert in `docker-compose.yml`). |
| `ARGOS_PREVIEW_DEFAULT_IMAGE` | `argos-demo` | Eingebaute Standard-Demo-Laufzeitumgebung (php-fpm + nginx + node), verwendet, wenn ein Repo keinen `.argos/demo.*`-Vertrag mitliefert. Ein Content-Hash wird angehängt (`argos-demo:<hash>`). |
| `ARGOS_TRAEFIK_DIR` | `/data/traefik` | Geteiltes Volume, in das der Manager pro Demo eine Traefik-File-Provider-Route schreibt (Traefik mountet es read-only). |

## Integrationen & Polling

Issue-/Task-Provider (GitHub, GitLab, Bitbucket, Linear) werden vom
`scheduler`-Worker nach einem Zeitplan gepollt. Die Provider-Verbindungen selbst
werden über die UI/DB verwaltet — siehe [OAuth & Credentials](#oauth--credentials-ui-managed).

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_POLL_INTERVAL_MINUTES` | `5` (auf 1–59 begrenzt) | Wie oft der Scheduler Issue-Provider pollt und Reaktionen auf Concept-Kommentare prüft. Der Default hält die API-Nutzung bei Skalierung niedrig; setze lokal `1` für schnelles Feedback. |

## OAuth & Credentials (UI-verwaltet)

OAuth-Apps für GitHub / GitLab / Bitbucket / Linear werden **in der UI
verwaltet** (Konfiguration → OAuth-Apps) und in der Datenbank gespeichert — es
gibt **keine** `*_CLIENT_ID`- / `*_CLIENT_SECRET`-Umgebungsvariablen.
Selbst-gehostete GitLab-Instanzen werden pro App über das Feld `instance_url`
konfiguriert.

Der Callback-Pfad ist fest auf `${APP_URL}/auth/<provider>/callback` —
registriere diese URL in der OAuth-App des Providers. Siehe
[OAuth-Überblick](OAUTH.md) und die Einrichtungsanleitungen pro Provider
([GitHub](SETUP-GITHUB.md), [GitLab](SETUP-GITLAB.md),
[Bitbucket](SETUP-BITBUCKET.md), [Linear](SETUP-LINEAR.md)).

Die **Claude-/Codex-Agent-Credentials** sind ebenfalls **keine**
Umgebungsvariablen — füge sie im Onboarding-Wizard / in der Credentials-UI hinzu,
wo sie pro Agent in der Datenbank gespeichert werden.

> Die `SEED_*`-Variablen (z. B. `SEED_USER_EMAIL`, `SEED_REPO_URL`,
> `SEED_CLAUDE_OAUTH_TOKEN`, `SEED_CODEX_AUTH_JSON_B64`) sind **nur für
> Entwicklung gedachte Seeding-Overrides**, die von `.tools/bin/dev-reset.sh`
> gelesen werden. Sie sind keine Betreiberkonfiguration und haben außerhalb des
> Seedings keine Wirkung.

## MCP-Server (Argos-API)

Argos stellt einen eingebauten [MCP-Server](SETUP-MCP.md) unter `${APP_URL}/mcp`
bereit, damit ein externer Client wie Claude Code ihn steuern kann. Die
Authentifizierung erfolgt über OAuth 2.1 via Laravel Passport (Scope `mcp:use`).

| Variable | Default | Zweck |
|---|---|---|
| `APP_URL` | `http://localhost` | Dient zugleich als OAuth-**Issuer**. Muss die öffentliche URL sein, die der MCP-Client erreichen kann, sonst schlagen Client-Registrierung/Login fehl. |
| `PASSPORT_KEYS_PATH` | – (nicht gesetzt) | Optionales Verzeichnis, aus dem die Passport-Signaturschlüssel geladen werden. Ist es nicht gesetzt, verwendet Passport seine Standard-Schlüsselauflösung. Setze dies auf einen persistenten Pfad, wenn ausgestellte Tokens Image-Rebuilds überdauern sollen. |

Siehe den [MCP-Server-Leitfaden](SETUP-MCP.md) für den Connect-Ablauf und die
verfügbaren Tools.

## Zielprojekt-MCP (Laravel Boost)

Der Worker prüft die `boost.json` des geklonten Zielprojekts auf `"mcp": true`.
Ist sie vorhanden, hängt der aktive Agent-Runner vor jeder Session den
MCP-Server des Projekts als lokalen `stdio`-Subprozess an
(`php artisan boost:mcp`) — kein Netzwerkzugriff, keine Argos-Datenbankverbindung.
Das Ziel-Repo entscheidet; kein Flag auf Manager-Seite.

**Voraussetzungen am Zielprojekt:**
- `laravel/boost ^2.4` in `composer.json`
- `boost.json` mit `"mcp": true`
- `composer install` wurde ausgeführt (Vendor-Verzeichnis vorhanden)

**Verdrahtung pro Agent (automatisch erledigt):**
- Claude Code: `claude --mcp-config <file>` mit einer generierten Config-Datei.
- Codex: `-c mcp_servers.laravel-boost.{command,args}=…`-Overrides, die in den
  `codex exec`-Aufruf eingefügt werden.

Der MCP-Server läuft vollständig innerhalb des Worker-Containers. Er gibt dem
Agent Zugriff auf Boost-Tools (z. B. `search-docs`, `database-schema`), die auf
das Zielprojekt eingeschränkt sind. Zum Deaktivieren setze `"mcp": false` in
`boost.json` oder entferne die Datei.

## Media Library (optional)

An Modelle angehängte Datei- und Bild-Uploads laufen über
[spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary).

| Variable | Default | Zweck |
|---|---|---|
| `MEDIA_DISK` | `public` | Filesystem-Disk (aus `config/filesystems.php`), auf der Uploads gespeichert werden. Zeige auf `s3` o. Ä. für Speicherung außerhalb des Hosts. |

Siehe [Media-Library-Einrichtung](SETUP-MEDIA-LIBRARY.md).

## Logging

| Variable | Default | Zweck |
|---|---|---|
| `LOG_LEVEL` | `debug` | Standard-Laravel-Log-Level für die Channels stack/single/stderr. |

## Compose-/OPS-Level-Schlüssel

Diese werden vom Compose-Stack selbst gelesen
(`.tools/docker/docker-compose.yml`) und nicht von der Laravel-App, aber
Betreiber setzen sie an derselben Stelle.

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_PORT` | `8080` | Host-Port, an den Traefik bindet (der einzige Port-80-Eingang des Stacks). `APP_URL` und `ARGOS_PREVIEW_PORT` setzen ihn als Default. |
| `ARGOS_APP_IMAGE` | `argos-app:local` | Image für die Services `app` / `queue` / `scheduler`. Self-Host-Installationen pinnen dies auf einen veröffentlichten Tag (z. B. `ghcr.io/nodus-it/argos-app:latest`). |

---

Für interaktive Einrichtungs-Walkthroughs siehe den [Setup-Leitfaden](SETUP.md)
und die providerspezifischen Anleitungen:

- [GitHub-Einrichtung](SETUP-GITHUB.md)
- [GitLab-Einrichtung](SETUP-GITLAB.md)
- [Bitbucket-Einrichtung](SETUP-BITBUCKET.md)
- [Linear-Einrichtung](SETUP-LINEAR.md)
