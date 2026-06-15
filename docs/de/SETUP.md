# Einrichtungsleitfaden

Dies ist der Installations- und Einrichtungsleitfaden für Betreiber, die eine
Argos-Instanz betreiben. Er behandelt Voraussetzungen, den Ein-Befehl-Installer
und seine Release-Kanäle, was der Stack ausführt, den ersten Start und die erste
Anmeldung, den Hinweis zu Reverse-Proxy / `APP_URL`, das Aktualisieren und wie es
weitergeht (Onboarding, OAuth, Worker-Stacks).

Die vollständige Referenz der Umgebungsvariablen findest du in
[CONFIGURATION.md](CONFIGURATION.md). Für einen schnellen Probelauf ist der
[Quick Start im README](../README.md#quick-start) derselbe Einzeiler mit weniger
Details.

## Inhalt

- [Voraussetzungen](#prerequisites)
- [Ein-Befehl-Installation](#one-command-install)
- [Release-Kanäle](#release-channels)
- [Was der Stack ausführt](#what-the-stack-runs)
- [Erster Start](#first-boot)
- [Erste Anmeldung und Onboarding](#first-login-and-onboarding)
- [Reverse-Proxy und APP_URL](#reverse-proxy-and-app_url)
- [OAuth (optional)](#oauth-optional)
- [Auswahl von Worker-Stack und Agent](#choosing-a-worker-stack-and-agent)
- [Vorbelegen des Claude-Tokens](#pre-seeding-the-claude-token)
- [Produktionsnahe Installation](#production-style-install)
- [Aktualisieren](#updating)
- [Zurücksetzen und Sicherung](#reset-and-backup)
- [Umgebungsvariablen](#environment-variables)

## Voraussetzungen

Du benötigst einen Linux-Host mit:

- **Docker Engine 20.10+** mit dem **Compose-v2-Plugin** (`docker compose`,
  nicht das veraltete `docker-compose`). Der Installer prüft dies und
  verweigert andernfalls die Ausführung.
- Einem Benutzer, der mit dem Docker-Daemon kommunizieren kann (in der
  `docker`-Gruppe, oder den Installer als root ausführen). Die Container `app`
  und `queue` binden den Docker-Socket des Hosts ein, um kurzlebige
  Worker-Container zu starten.
- `curl`, `openssl` und `sha256sum` auf dem Host (der Installer nutzt sie, um
  Manifeste herunterzuladen und Secrets zu generieren).

Optional, aber empfohlen für alles, was über einen lokalen Test hinausgeht:

- **Eine Domain und ein TLS-terminierender Reverse-Proxy** (Caddy, nginx,
  Traefik, HAProxy). Du benötigst dies, damit OAuth-Callbacks korrekt aufgelöst
  werden, und für HTTPS. Siehe
  [Reverse-Proxy und APP_URL](#reverse-proxy-and-app_url).

Du musst **nichts** vorab bauen. Das Manager-Image wird von GHCR bezogen;
Worker-Images werden beim ersten Phasenlauf bedarfsgesteuert aus den lokalen
Manifesten gebaut.

## Ein-Befehl-Installation

Führe den Installer aus. Er lädt `docker-compose.yml` und `.env.example` herunter,
generiert eine `.env` mit zufälligen Secrets und startet den Stack:

```bash
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh | bash
```

Standardmäßig installiert dies in das **aktuelle Verzeichnis** (`$PWD`). Um
woanders zu installieren, übergib `--dir` (oder setze `ARGOS_INSTALL_DIR`):

```bash
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh \
    | bash -s -- --dir /opt/argos
```

Nach Abschluss gibt der Installer eine Zusammenfassung mit der URL, der
Admin-Anmeldung und dem generierten Admin-Passwort aus.

Installer-Flags (nach `bash -s --` anhängen oder die Umgebungsvariable setzen):

| Flag | Umgebungsvariable | Wirkung |
|---|---|---|
| `--dir PATH` | `ARGOS_INSTALL_DIR` | Installiert nach `PATH` statt nach `$PWD` |
| `--version REF` | `ARGOS_VERSION` | Heftet ein bestimmtes Git-Tag oder einen Branch an |
| `--stage` | `ARGOS_STAGE=1` | Verfolgt die rollierenden `:stage`-Images aus `develop` |
| `--next` | `ARGOS_NEXT=1` | Verfolgt die rollierenden `:next`-Images aus dem Integrationsbranch `next` |
| `--beta` | `ARGOS_BETA=1` | Installiert das neueste Release inklusive Pre-Releases |
| `--reset` | — | Fährt den Stack herunter und löscht alle Daten (destruktiv) |
| `--force` | — | Überspringt Sicherheitsabfragen (für `--reset` in nicht-interaktiven Shells erforderlich) |
| `--help` | — | Zeigt alle Optionen an |

Der Installer verweigert es, Dateien in ein nicht-leeres Verzeichnis zu legen,
sofern du nicht `--force` übergibst. Bringe eigene Anpassungen in einer
`docker-compose.override.yml` neben der ausgelieferten Compose-Datei ein — der
Installer fasst diese Datei niemals an (siehe
[Produktionsnahe Installation](#production-style-install)).

## Release-Kanäle

Der Kanal entscheidet, welches Image-Tag die Dienste `app`/`queue`/`scheduler`
beziehen (`ARGOS_APP_IMAGE` in `.env`) und aus welchem Branch der Installer
Manifeste holt. Die Wahl gilt **pro Aufruf** — übergib das Flag beim
Aktualisieren erneut, um denselben Kanal weiterzuverfolgen.

| Kanal | Auswahl | Branch / Quelle | Image-Tag | Wann verwenden |
|---|---|---|---|---|
| **release** (Standard) | kein Flag | neuestes stabiles Release-Tag | `:latest` | Produktion. Der Standard. |
| **beta** | `--beta` | neuestes Release inkl. Pre-Releases | das aufgelöste Tag | RC-Builds verfolgen oder bevor das erste stabile Release erscheint |
| **stage** | `--stage` | `develop` | `:stage` | Vorschau der nächsten Release-Linie — nicht für Produktion |
| **next** | `--next` | `next` (Integrationslinie) | `:next` | Vorschau der kommenden Version — vor `develop`, am wenigsten stabil |

Existiert noch kein stabiles Release, fällt der Standardkanal transparent auf das
neueste Pre-Release zurück (mit einer Warnung) und letztlich auf den
`develop`-Branch.

```bash
# stage (rollierendes develop)
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/develop/.tools/install.sh \
    | bash -s -- --dir ./argos-stage --stage

# next (rollierende Integrationslinie)
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/next/.tools/install.sh \
    | bash -s -- --dir ./argos-next --next
```

`--stage` und `--next` schließen sich gegenseitig aus; wähle eins.

## Was der Stack ausführt

Argos ist eine Laravel-Anwendung, die als Docker-Compose-Stack bereitgestellt
wird. Die Compose-Datei ist die Bereitstellungseinheit. Nur das
**Manager-Image** (`ghcr.io/nodus-it/argos-app`) wird bezogen; Worker-Images
werden bedarfsgesteuert auf dem Host gebaut.

| Dienst | Image | Zweck |
|---|---|---|
| `db` | `mariadb:11` | Anwendungsdatenbank. Die Daten liegen im Volume `argos-db`. |
| `redis` | `redis:7-alpine` | Queue-Backend für Horizon. Daten im Volume `argos-redis`. |
| `app` | `ghcr.io/nodus-it/argos-app` | Web-UI + [MCP-Server](SETUP-MCP.md) + REST-API (PHP-FPM). Führt beim Start Migrationen aus; bindet den Docker-Socket ein, um Worker zu starten. |
| `traefik` | `traefik:v3.5` | Der einzige Einstiegspunkt auf Port 80. Wird auf dem Host via `ARGOS_PORT` veröffentlicht. Leitet zu nginx (und zu Live-Demo-Containern) weiter. |
| `nginx` | `nginx:1.27-alpine` | Liefert statische Assets aus und leitet dynamische Anfragen an `app:9000` weiter. Sitzt hinter Traefik. |
| `queue` | `ghcr.io/nodus-it/argos-app` | Laravel-Horizon-Worker — führt die Task-Phasen (`RunPhaseJob`) auf Redis aus. Gleiches Image wie `app`. |
| `scheduler` | `ghcr.io/nodus-it/argos-app` | Laravel-Scheduler (`schedule:work`) — stößt wiederkehrende Jobs an (z. B. Issue-Polling). Gleiches Image wie `app`. |
| `worker` (transient) | `argos-worker:<stack>-<hash>-<agent>-<version>` | Kurzlebiger Container, der pro Phase von den Diensten `app`/`queue` gestartet wird. **Hier läuft die gesamte KI** — der Manager berührt Claude niemals direkt. Wird bedarfsgesteuert aus der `worker_stacks`-Zeile × dem gewählten Agent gebaut. |

Der Host-Port wird über **`ARGOS_PORT`** (Standard `8080`) gesetzt und auf
**Traefiks** Port 80 abgebildet. Alles, was nicht von einer Live-Demo-Host-Regel
erfasst wird, fällt durch Traefik → nginx → app, sodass `http://localhost:8080`
weiterhin die App ausliefert.

## Erster Start

Beim ersten Start läuft der Entrypoint des `app`-Containers (in dieser
Reihenfolge):

1. Bildet die GID des Docker-Sockets auf `www-data` ab, damit PHP
   Worker-Container starten kann.
2. Löst `APP_KEY` auf — verwendet den Wert aus `.env`, falls gesetzt (der
   Installer hat einen generiert), andernfalls wird ein automatisch generierter
   Schlüssel im Volume `argos-data` persistiert.
3. Synchronisiert Composer-Abhängigkeiten und den Package-Discovery-Cache, wenn
   veraltet.
4. Legt die Traefik-Route, die nginx-Konfiguration und die öffentlichen Assets in
   ihren geteilten Volumes an; generiert die Passport-Signierschlüssel (einmalig)
   unter `argos-data`.
5. Wartet auf die Datenbank und führt dann — **nur in der Rolle `app`** —
   `migrate --force` aus, legt den Admin-Benutzer an (`AdminUserSeeder`) und
   stößt Pre-Warm-Builds für das Standard-Worker-Image und das Live-Demo-Image an.

Die Dienste `queue` und `scheduler` teilen sich dasselbe Image, überspringen aber
die Schema-Arbeit — der Dienst `app` ist für Migrationen und Seeding zuständig.

Der `app`-Container meldet sich erst als gesund, sobald die Datenbank erreichbar
**und** das Schema vollständig migriert ist; `nginx`, `queue` und `scheduler`
warten auf dieses Tor. Die erste Phase, die eine frische Installation ausführt,
baut ihr Worker-Image (1–3 Minuten kalt; der Pre-Warm-Schritt beim Start
verbirgt das meiste davon), danach verwenden nachfolgende Läufe das gecachte
Image.

## Erste Anmeldung und Onboarding

Öffne die Admin-UI:

- `http://localhost:8080/admin` (oder `${APP_URL}/admin`). Der Aufruf von `/`
  leitet auf `/admin` um.

Melde dich mit den Zugangsdaten aus der Installer-Zusammenfassung an:

- **E-Mail:** `admin@argos.local`
- **Passwort:** das generierte `ADMIN_PASSWORD` aus der Zusammenfassung (auch in
  `.env`).

Ändere das Admin-Passwort unter **Profile** nach der ersten Anmeldung.

Ein in der App integrierter **Onboarding-Assistent** führt dich anschließend
durch das Einfügen deines Claude-Tokens und das Anlegen deines ersten Projekts.
Um ein Ziel-Repository "Argos-ready" zu machen (eine eigene Build-Umgebung oder
einen Live-Demo-Vertrag), siehe [PREPARE-PROJECT.md](PREPARE-PROJECT.md).

## Reverse-Proxy und APP_URL

Der Stack liefert reines HTTP auf dem Host-Port aus, der über `ARGOS_PORT`
gesetzt ist (Standard `8080`, abgebildet auf Traefiks Port 80). Für alles
Öffentliche terminierst du TLS an deinem Reverse-Proxy (Caddy, nginx, Traefik,
HAProxy) und leitest an diesen Port weiter.

Achte darauf:

- **`APP_URL`** auf die öffentliche URL zu setzen (z. B.
  `https://argos.example.com`). `APP_URL` ist die einzige Quelle der Wahrheit für
  Host und Schema — OAuth-Callbacks, die Domain des Session-Cookies und
  Live-Demo-Subdomains leiten sich alle davon ab.
- `X-Forwarded-Proto: https` weiterzuleiten (sowie `X-Forwarded-For` /
  `X-Forwarded-Host`). Argos und das mitgelieferte Traefik vertrauen
  weitergeleiteten Headern von jedem vorgelagerten Proxy. Ohne
  `X-Forwarded-Proto` werden Asset-URLs als `http://` gerendert und der Browser
  markiert Mixed Content.
- Dieselbe `APP_URL` beim Registrieren von OAuth-Apps zu verwenden — die
  Redirect-URI muss übereinstimmen.

HAProxy-Snippet (in das *Advanced pass thru* des Argos-Backends einfügen):

```
option forwardfor
http-request set-header X-Forwarded-Proto https if { ssl_fc }
```

Siehe die Session-/HTTPS-Variablen (`SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`) in
[CONFIGURATION.md#sessions-reverse-proxy--https](CONFIGURATION.md#sessions-reverse-proxy--https)
— beide leiten sich von `APP_URL` ab und müssen selten gesetzt werden.

## OAuth (optional)

Standardmäßig arbeitet Argos mit Personal Access Tokens (PATs) — füge pro Projekt
einen Token ein und du bist fertig. **OAuth** ist das optionale Upgrade, das
folgendes ermöglicht:

- Repository- und Branch-Dropdowns beim Anlegen eines Projekts
- Authentifizierung pro Benutzer (jeder Benutzer verbindet seine eigenen Konten)
- Unterstützung für selbst gehostetes GitLab ohne Token-Verwaltung pro Projekt

OAuth-Client-Zugangsdaten werden vollständig in der UI verwaltet (Configuration →
OAuth Apps) und in der Datenbank gespeichert — es gibt keinen ENV-Pfad. Siehe:

- [OAuth-Überblick](OAUTH.md) — wenn du dir nicht sicher bist, welchen Modus du
  wählen sollst
- [GitHub-Einrichtung](SETUP-GITHUB.md)
- [GitLab-Einrichtung](SETUP-GITLAB.md)
- [Bitbucket-Einrichtung](SETUP-BITBUCKET.md)

## Auswahl von Worker-Stack und Agent

Worker-Images werden **nicht** von GHCR bezogen — der Manager baut sie
bedarfsgesteuert aus der Tabelle `worker_stacks`, sobald zum ersten Mal ein Task
mit dieser Kombination aus (Stack × Agent × Version) läuft. Der erste Lauf dauert
1–3 Minuten; nachfolgende Läufe verwenden das gecachte Image.

Wähle den Stack und den Agent unter **Worker → Stacks** und **Worker → Agent
Credentials** in der Admin-UI, weise dann Standardwerte pro Projekt und
(optional) Overrides pro Task zu. Eingebaute Stacks (`php-8.3`, `php-8.4`, …)
werden bei jeder Migration aus dem Repo-Manifest in die DB gespiegelt; du kannst
in derselben UI deinen eigenen Benutzer-Stack hinzufügen.

Um ein *Ziel*-Repository auf Argos zuzuschneiden — eine eigene Build-Umgebung
(`.argos/worker.dockerfile`) oder einen Live-Demo-Vertrag (`.argos/demo.*`) —
siehe [PREPARE-PROJECT.md](PREPARE-PROJECT.md).

## Vorbelegen des Claude-Tokens

Der Token liegt **pro Agent in der Datenbank**. Der normale Weg ist der
Onboarding-Schritt in der App oder **Worker → Agent Credentials** im Admin —
eine DB-Zugangsinformation gewinnt immer (es gibt keinen
`CLAUDE_CODE_OAUTH_TOKEN`-Env-Var-Pfad).

Generiere einen Token mit der Claude-Code-CLI (angemeldet in deinem Pro-/Max-/
Team-Plan):

```bash
claude setup-token
```

Füge ihn in das Onboarding / die Agent Credentials ein. Tokens laufen nach einigen
Wochen ab — erneuere sie in der UI und führe `claude setup-token` erneut aus.

Für ein unbeaufsichtigtes Local-Dev-Seeding kannst du stattdessen den rohen Token
in eine Datei unter `$ARGOS_CONFIG_DIR/claude_token` legen (Standard
`/data/config/claude_token` im Compose-Stack): Der Worker liest sie als
allerletzten Fallback, und die nächste Migration importiert sie in eine Agent
Credential.

## Produktionsnahe Installation

Führe den Installer mit einem dedizierten Installationsverzeichnis aus und bringe
dann deine Anpassungen in einer `docker-compose.override.yml` neben der
ausgelieferten Compose-Datei ein. Compose führt das Override automatisch zusammen
und der Installer fasst es niemals an:

```bash
mkdir -p /srv/argos
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh \
    | bash -s -- --dir /srv/argos
```

```yaml
# /srv/argos/docker-compose.override.yml
services:
  app:
    environment:
      APP_URL: https://argos.example.com
    volumes:
      - /srv/argos-backups/data:/data
  db:
    volumes:
      - /srv/argos-backups/db:/var/lib/mysql
```

```bash
docker compose -f /srv/argos/docker-compose.yml up -d
```

Das Override oben gibt dir:

- **Bind-Mounts** statt benannter Volumes, sodass Backups und Snapshots mit
  deinem Host-Tooling erfolgen
- `APP_URL`, damit OAuth-Callbacks hinter einem Reverse-Proxy korrekt aufgelöst
  werden

Der Installer hat bereits ein starkes `ADMIN_PASSWORD`, einen `APP_KEY` und
DB-Passwörter in `/srv/argos/.env` generiert — verschiebe diese nicht in die
Compose-Datei (du würdest Secrets im Klartext fest verdrahten). Bearbeite `.env`,
falls du sie ändern musst.

## Aktualisieren

Führe den Installer im **selben Installationsverzeichnis** erneut aus, um zu
aktualisieren. Er lädt eine etwaige neuere `docker-compose.yml` herunter, führt
neue Schlüssel aus der vorgelagerten `.env.example` in deine `.env` zusammen,
ohne bestehende Werte anzutasten, zieht dann Images und startet den Stack neu:

```bash
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh \
    | bash -s -- --dir /srv/argos
```

Hinweise:

- Übergib `--stage` / `--next` beim Aktualisieren erneut, um diesen Kanal
  weiterzuverfolgen — andernfalls fällt das nächste Update auf das
  Standard-Release-Tag zurück.
- Wenn du die installer-verwaltete `docker-compose.yml` lokal bearbeitet hast,
  verweigert der Installer das Überschreiben und verweist dich auf
  `docker-compose.override.yml`. Verschiebe eigene Konfiguration dorthin.
- Nach dem Update führt `app` beim Start die Migrationen erneut aus — kein
  manueller Migrate-Schritt.

## Zurücksetzen und Sicherung

**Sicherung.** Der zu sichernde Zustand liegt in den benannten Volumes:

- `argos-db` — die MariaDB-Datenbank (das primäre Backup-Ziel)
- `argos-data` — persistierter App-Zustand (`APP_KEY`-Fallback,
  Passport-Schlüssel, `ARGOS_CONFIG_DIR`)

Verwende Bind-Mounts (siehe
[Produktionsnahe Installation](#production-style-install)), um diese mit deinem
Host-Tooling zu sichern, oder snapshotte die Volumes direkt. Die generierte
`.env` (Modus `600`) enthält deine Secrets — sichere sie ebenfalls, da `APP_KEY`
benötigt wird, um gespeicherte Zugangsdaten zu entschlüsseln.

**Zurücksetzen (destruktiv).** `--reset` fährt den Stack herunter und löscht alle
seine benannten Volumes, einschließlich der Datenbank:

```bash
# aus einem lokalen Checkout des Installationsverzeichnisses (interaktiv: fragt nach "yes")
bash /srv/argos/../argos-src/.tools/install.sh --dir /srv/argos --reset

# nicht-interaktiv (curl | bash): --force ist erforderlich
curl -fsSL https://raw.githubusercontent.com/nodus-it/argos/master/.tools/install.sh \
    | bash -s -- --dir /srv/argos --reset --force
```

`--force` ist für `--reset` in einer nicht-interaktiven Shell erforderlich (z. B.
`curl | bash`). Das Zurücksetzen berührt nur Volumes, die in der Compose-Datei
dieses Stacks deklariert sind — niemals unbeteiligte Compose-Projekte.

## Umgebungsvariablen

Siehe die [Konfigurationsreferenz](CONFIGURATION.md) für jede Variable, die Argos
liest, mit Standardwerten. Die Schlüssel, die die meisten Betreiber setzen:

- `APP_URL` — öffentliche URL (Host + Schema), siehe
  [Reverse-Proxy und APP_URL](#reverse-proxy-and-app_url)
- `ARGOS_PORT` — Host-Port für den Stack (Standard `8080`)
- `ARGOS_APP_IMAGE` — das Manager-Image-Tag (durch die Kanal-Flags gesetzt)
- `ADMIN_PASSWORD`, `APP_KEY`, `ARGOS_DB_PASSWORD`, `ARGOS_DB_ROOT_PASSWORD` —
  vom Installer generiert; nicht von Hand bearbeiten, sofern du nicht weißt, warum
