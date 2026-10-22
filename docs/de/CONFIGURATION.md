# Konfigurationsreferenz

Die gesamte Argos-Konfiguration wird über Umgebungsvariablen gesteuert, die an
die Services `app` (sowie `queue` / `scheduler`) im Compose-Stack übergeben
werden — oder über deine `.env`-Datei beim lokalen Betrieb. Diese Seite ist die
Referenz für den Betrieb mit jeder ENV-Variable, die Argos tatsächlich liest.

> [!NOTE]
> Argos läuft lokal ohne jede Konfiguration — SQLite, alle Defaults. Überschreibe
> nur, was du brauchst. Zwei Grenzen sind zu beachten:
>
> - **`APP_KEY`** wird beim ersten Manager-Start automatisch erzeugt und
>   persistiert; du setzt sie nur, wenn du ein Backup wiederherstellst.
> - **OAuth-Apps und die Claude-Agent-Zugangsdaten werden über die UI/DB
>   verwaltet, nicht per ENV.** Siehe [OAuth & Credentials](#oauth-credentials-ui-verwaltet)
>   weiter unten.

## Inhalt

- [Core / App & URL](#core-app-url)
- [Sessions (Reverse Proxy / HTTPS)](#sessions-reverse-proxy-https)
- [Datenbank](#datenbank)
- [Queue, Redis & Horizon](#queue-redis-horizon)
- [Worker-Image](#worker-image)
- [Worker-Hintergrunddienste (Test-Sidecars)](#worker-hintergrunddienste-test-sidecars)
- [Live-Previews](#live-previews)
- [Workspaces](#workspaces)
- [Echtzeit (Reverb)](#echtzeit-reverb)
- [Integrationen & Polling](#integrationen-polling)
- [OAuth & Credentials (UI-verwaltet)](#oauth-credentials-ui-verwaltet)
- [MCP-Server (Argos-API)](#mcp-server-argos-api)
- [Repository-Einstellungen in Aufgaben](#repository-einstellungen-in-aufgaben)
- [Logging](#logging)
- [Compose-/OPS-Level-Schlüssel](#compose-ops-level-schlussel)

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
| `ADMIN_PASSWORD` | `12345` | Passwort für das automatisch erstellte Admin-Konto. **Vor dem Veröffentlichen der Instanz ändern.** |
| `ARGOS_CONFIG_DIR` | `~/.config/argos` (Compose: `/data/config`) | Verzeichnis für persistierte Konfiguration / den Standard-SQLite-Pfad innerhalb des Managers. |
| `ARGOS_SOURCE_URL` | `https://github.com/nodus-it/argos` | In der UI angezeigte Source-Offer-URL gemäß AGPL-3.0 §13. **Forks müssen dies überschreiben** mit ihrer eigenen Source-URL. |
| `ARGOS_VERSION` | – | Überschreibt den eingebackenen App-Versionsstring. CI backt einen `stage-…`-Wert in Stage-Images; für Releases nicht setzen. |

## Sessions (Reverse Proxy / HTTPS)

Beide Werte **leiten sich aus `APP_URL` ab** und müssen selten gesetzt werden:

| Variable | Default | Zweck |
|---|---|---|
| `SESSION_DOMAIN` | abgeleitet: `.<APP_URL host>` für eine echte Domain, nur Host für localhost/IP/`nip.io` | Cookie-Domain. Der führende Punkt lässt die Session sich über Preview-Subdomains spannen (`demo-<workspace>-<task>.<host>`). Nur überschreiben, wenn Demos auf einer anderen Domain als die App liegen. |
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

Hintergrund-Jobs — Workspace-Bereitstellung, Vorbereitung von Aufgaben, Image-Builds, Previews und Issue-Polling — laufen über **Laravel
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
| `ARGOS_QUEUE_TASKS_PROCESSES` | `2` | Horizon-Worker-Prozesse für die `tasks`-Queue (Workspaces starten und stoppen). |

> `ARGOS_REDIS_*` haben Vorrang; die einfachen Laravel-Defaults `REDIS_HOST` /
> `REDIS_PORT` / `REDIS_PASSWORD` werden als Fallback berücksichtigt (und sind
> das, was der Compose-Stack tatsächlich setzt).

## Worker-Image

Das Worker-Image (Stack + Agent + Gate-Helfer) ist die Basis, aus der jedes
Workspace-Image gebaut wird. Die Ressourcenlimits eines laufenden Workspaces
stehen unter [Workspaces](#workspaces).

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_DEFAULT_STACK` | `php-8.5` | Slug des Stacks, der verwendet wird, wenn ein Workspace keinen festlegt: `php-8.5`, `php-8.4` oder `node-22` (die eingebauten Stacks, bei `migrate` in `worker_stacks` gespiegelt). Siehe [Stacks und Images](WORKER-STACKS.md). |

## Worker-Hintergrunddienste (Test-Sidecars)

Argos kann Backing-Service-Sidecars neben einem Workspace hochfahren (ein
privates Netzwerk pro Workspace, die Daten bleiben bis zum Löschen des
Workspaces), damit die Tests eines Projekts mit einem echten MySQL/Redis
sprechen können. Jede Aufgabe bekommt darauf ihre eigene Datenbank und ihren
eigenen Redis-Präfix. Ein Workspace meldet sich pro Service an
(Einstellungen → Umgebung).

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_WORKER_SERVICE_TIMEOUT` | `60` | Sekunden, die auf die Bereitschaft eines Backing-Service gewartet wird, bevor der Start des Workspaces fehlschlägt. |
| `ARGOS_WORKER_MYSQL_IMAGE` | `mariadb:11` | Image für den MySQL-/MariaDB-Sidecar. |
| `ARGOS_WORKER_REDIS_IMAGE` | `redis:7-alpine` | Image für den Redis-Sidecar. |

## Live-Previews

Live-Previews von Aufgaben, geroutet von Traefik unter ihrer eigenen
Subdomain und geschützt durch das Workspace-Gate (nur Mitglieder). Standardmäßig auf Plattformebene aktiviert, aber der Toggle pro
Workspace (*Live-Demo aktivieren* / `live_demo_enabled`, bei neuen Workspaces
an) ist das eigentliche Gate pro Workspace. Siehe [Previews](LIVE-DEMOS.md).
Basis-Domain + Schema **leiten sich aus `APP_URL` ab** —
nur überschreiben für eine Demo-Domain, die sich von der App unterscheidet.
Erfordert Wildcard-DNS `*.<host>`, das auf diesen Host auflöst (sowie die
Traefik- + `argos_edge`-Preview-Infrastruktur).

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_PREVIEW_ENABLED` | `true` (Compose leitet `false` weiter) | Hauptschalter für die Live-Demo-Infrastruktur. Setze `false`, um plattformweit zu deaktivieren (z. B. keine Traefik-/Preview-Infrastruktur). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | abgeleitet vom `APP_URL`-Host (`127.0.0.1.nip.io` für bare/localhost) | Previews liegen unter `demo-<workspace>-<task>.<base_domain>`, Editoren unter `code-<workspace>.<base_domain>`. |
| `ARGOS_PREVIEW_SCHEME` | abgeleitet vom `APP_URL`-Schema | `http` oder `https`, das in der Demo-URL verwendet wird. |
| `ARGOS_PREVIEW_PORT` | fällt zurück auf `ARGOS_PORT`, dann `8080` | Externer Port, unter dem die Demo-URL erreichbar ist (der öffentliche Endpunkt, unabhängig vom Host-Port, an den Traefik bindet). Hinter einem TLS-Proxy auf 443 setze `scheme=https` und `ARGOS_PREVIEW_PORT=443`, damit die URL den Port weglässt. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | Stunden, bevor eine inaktive Demo abgebaut wird. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Obergrenze gleichzeitig laufender Previews über alle Workspaces (`0` = unbegrenzt; bei Überschreitung werden die ältesten Previews anderer Aufgaben verdrängt). Jeder Workspace hat zusätzlich sein eigenes Limit *Parallele Previews*. |
| `ARGOS_PREVIEW_CPU_LIMIT` | `1.0` | CPU-Limit pro Preview (getrennt von den Workspace-Limits). |
| `ARGOS_PREVIEW_MEM_LIMIT` | `1g` | Speicherlimit pro Preview. |
| `ARGOS_PREVIEW_NETWORK` | `argos_edge` | Externes Docker-Netzwerk, das mit Traefik geteilt wird (definiert in `docker-compose.yml`). |
| `ARGOS_PREVIEW_DEFAULT_IMAGE` | `argos-demo` | Eingebaute Standard-Demo-Laufzeitumgebung (php-fpm + nginx + node), verwendet, wenn ein Repo keinen `.argos/demo.*`-Vertrag mitliefert. Ein Content-Hash wird angehängt (`argos-demo:<hash>`). |
| `ARGOS_TRAEFIK_DIR` | `/data/traefik` | Geteiltes Volume, in das der Manager pro Demo eine Traefik-File-Provider-Route schreibt (Traefik mountet es read-only). |

## Workspaces

Interaktive Workspaces (siehe [Workspaces](WORKSPACES.md)): ein langlebiger
Container pro Workspace mit Workspace-Daemon und OpenVSCode Server im internen
Netz `argos_ws`. Der Editor liegt unter `code-<workspace>.<base_domain>`, die
Preview einer Aufgabe unter `demo-<workspace>-<task>.<base_domain>`. Limits pro
Workspace (parallele Sessions und Previews, CPU, Speicher, Aufräumen) legst du
in den Workspace-Einstellungen fest (siehe
[Workspace-Einstellungen](WORKSPACE-SETTINGS.md#ressourcen)).

| Variable | Standard | Zweck |
|---|---|---|
| `ARGOS_WORKSPACE_COLLABORATION` | `true` | Ob Workspaces außer der Person, die sie angelegt hat, weitere Mitglieder und Aufgaben weitere Mitwirkende haben dürfen. Steht hinter dem Gate `workspace-collaboration`. |
| `ARGOS_WORKSPACE_MAX_ACTIVE` | `5` | Gleichzeitig laufende Workspace-Container. Ein Start darüber hinaus wird abgelehnt — laufende Workspaces werden nie verdrängt. `0` hebt das Limit auf. |
| `ARGOS_WORKSPACE_IDLE_MINUTES` | `60` | Einen laufenden Workspace nach so vielen Minuten ohne Aktivität stoppen (nie, solange eine Aufgabe vorbereitet wird, in der Warteschlange steht, arbeitet, auf eine Entscheidung wartet oder automatisch läuft). `0` deaktiviert. |
| `ARGOS_WORKSPACE_CPUS` | `2` | Standard-CPU-Limit pro Workspace-Container (ein Workspace kann ein eigenes setzen). |
| `ARGOS_WORKSPACE_MEMORY` | `4g` | Standard-Speicherlimit pro Workspace-Container (ein Workspace kann ein eigenes setzen). |
| `ARGOS_INTERNAL_URL` | `http://nginx` | Basis-URL, über die der Workspace-Daemon den Manager im Netz `argos_ws` erreicht. |
| `ARGOS_WORKSPACE_NETWORK` | `argos_ws` | Docker-Netz, das Manager und Workspace-Container teilen. |
| `ARGOS_WORKSPACE_GATE_URL` | `http://nginx:80/_argos/workspace-gate` | forwardAuth-Ziel, das Traefik für `code-*`- und `demo-*`-Hosts aufruft (Mitgliedschaftsprüfung). |
| `ARGOS_WORKSPACE_EDITOR_COOKIE_HOURS` | `12` | Lebensdauer des Zugangs-Cookies auf Workspace-Hosts. |
| `ARGOS_WORKSPACE_PR_SYNC_MINUTES` | `3` | Wie oft die Pull Requests offener Aufgaben (Zustand, Checks, Reviews, Konflikte) beim Provider neu gelesen werden (`argos:tasks:sync-prs`). Siehe [Pull Requests](PULL-REQUESTS.md#synchronisierung). |
| `ARGOS_WORKSPACE_UPLOAD_MAX_MB` | `5` | Größenlimit pro Bild, das an eine Nachricht einer Aufgabe angehängt wird (bis zu 5 Bilder pro Nachricht). |
| `ARGOS_OPENVSCODE_VERSION` | `1.109.5` | Release von OpenVSCode Server, das ins Workspace-Image eingebaut wird. |

### Automatische Aufgaben

Standards der Installation für [automatische Aufgaben](TASKS.md#automatische-aufgaben); ein Workspace überschreibt sie unter *Einstellungen → Automatik*, ein einzelner Lauf überschreibt den Workspace.

| Variable | Standard | Zweck |
|---|---|---|
| `ARGOS_AUTO_PLAN` | `auto` | `off` (direkt umsetzen), `auto` (planen, dann weiter) oder `approve` (planen, dann auf Freigabe warten). |
| `ARGOS_AUTO_SHIP` | `pr` | `pr`, `draft_pr` oder `none` (nur committen und pushen). |
| `ARGOS_AUTO_MAX_FIX_ROUNDS` | `3` | Runden, in denen Claude fehlgeschlagene Quality-Gates beheben darf, bevor trotzdem ausgeliefert wird. |
| `ARGOS_AUTO_MAX_TURNS` | `60` | Turn-Budget pro Lauf. |
| `ARGOS_AUTO_MAX_COST_USD` | `0` | Kosten-Budget pro Lauf; `0` = keins (Abos sind pauschal). |
| `ARGOS_AUTO_MAX_MINUTES` | `120` | Laufzeit-Budget pro Lauf. |

## Echtzeit (Reverb)

Live-Updates von Aufgaben laufen über [Laravel Reverb](https://reverb.laravel.com)
(den Dienst `reverb`). Browser verbinden sich über Traefik auf dem Argos-Host
unter `/app/…`; der Manager veröffentlicht intern an `reverb:8080`. Die
Compose-Datei setzt die Verbindung für dich.

| Variable | Standard | Zweck |
|---|---|---|
| `ARGOS_REVERB_APP_SECRET` | vom Installer erzeugt | Signiert Nachrichten zwischen Manager und Reverb. Stabil halten. |
| `ARGOS_REVERB_APP_KEY` | `argos` | Öffentlicher App-Schlüssel, mit dem sich der Browser verbindet. |

## Integrationen & Polling

Issue-Tracker (GitHub, GitLab, Linear) werden vom `scheduler`-Worker nach
einem Zeitplan gepollt — neue Issues von Verknüpfungen im Poll-Modus,
👍-Freigaben auf Plan-Kommentaren und neue Kommentare an den Tickets offener
Aufgaben. Die Provider-Verbindungen selbst werden über die UI/DB verwaltet —
siehe [OAuth & Credentials](#oauth-credentials-ui-verwaltet).

| Variable | Default | Zweck |
|---|---|---|
| `ARGOS_POLL_INTERVAL_MINUTES` | `5` (auf 1–59 begrenzt) | Wie oft der Scheduler Issue-Tracker pollt (`argos:poll-issues`), Reaktionen auf Plan-Kommentare prüft (`argos:check-plan-approvals`) und Ticket-Kommentare liest (`argos:tasks:sync-ticket-comments`). Der Default hält die API-Nutzung bei Skalierung niedrig; setze lokal `1` für schnelles Feedback. |

## OAuth & Credentials (UI-verwaltet)

OAuth-Apps für GitHub / GitLab / Bitbucket / Linear werden **in der UI
verwaltet** (Administration → OAuth-Apps, `/system/oauth-apps`) und in der Datenbank gespeichert — es
gibt **keine** `*_CLIENT_ID`- / `*_CLIENT_SECRET`-Umgebungsvariablen.
Selbst-gehostete GitLab-Instanzen werden pro App über das Feld `instance_url`
konfiguriert.

Der Callback-Pfad ist fest auf `${APP_URL}/auth/<provider>/callback` —
registriere diese URL in der OAuth-App des Providers. Siehe
[OAuth-Überblick](OAUTH.md) und die Einrichtungsanleitungen pro Provider
([GitHub](SETUP-GITHUB.md), [GitLab](SETUP-GITLAB.md),
[Bitbucket](SETUP-BITBUCKET.md), [Linear](SETUP-LINEAR.md)).

Die **Claude-Agent-Zugangsdaten** sind ebenfalls **keine**
Umgebungsvariablen — füge sie im Einrichtungs-Assistenten oder unter **Mein
Konto → Claude-Abos** (`/account/agents`) hinzu, wo sie in der Datenbank
gespeichert werden.

> Die `SEED_*`-Variablen (z. B. `SEED_USER_EMAIL`, `SEED_REPO_URL`,
> `SEED_CLAUDE_OAUTH_TOKEN`) sind **nur für
> Entwicklung gedachte Seeding-Overrides**, die von `.tools/bin/dev-reset.sh`
> gelesen werden. Sie sind keine Konfiguration für den Betrieb und haben außerhalb des
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

## Repository-Einstellungen in Aufgaben

Aufgaben führen Claude Code in ihrem Worktree mit den *Projekt*- und *lokalen*
Einstellungen des Repositorys aus. Projektweite Konfiguration (`CLAUDE.md`,
`.claude/settings.json`, `.mcp.json`) gilt also wie auf einem
Entwicklerrechner. Es gibt kein Flag auf Manager-Seite; das Repository
entscheidet.

## Logging

| Variable | Default | Zweck |
|---|---|---|
| `LOG_LEVEL` | `debug` | Standard-Laravel-Log-Level für die Channels stack/single/stderr. |

## Compose-/OPS-Level-Schlüssel

Diese werden vom Compose-Stack selbst gelesen
(`.tools/docker/docker-compose.yml`) und nicht von der Laravel-App, aber du
setzt sie an derselben Stelle.

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
