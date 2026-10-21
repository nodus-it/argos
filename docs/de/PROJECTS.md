# Projekte

Ein **Projekt** in Argos ist ein konfiguriertes Git-Repository — die Einheit,
an der Argos arbeitet. Bevor Sie Argos Arbeit übergeben können, legen Sie ein
Projekt an, das festlegt, *welches* Repository verwendet wird, *wie*
authentifiziert wird, *welche* Umgebung der Code zum Bauen und Testen benötigt
und *welche* Standardwerte für automatische Läufe gelten. Jeder Workspace, den
Sie öffnen, gehört zu genau einem Projekt und erbt diese Einstellungen.

Diese Anleitung erläutert jede Einstellung im Projektformular — was sie
bedeutet, warum es sie gibt und wie Sie sie ausfüllen. Sie richtet sich an die
Person, die ein Projekt anlegt und verwaltet, nicht an die Entwicklerin oder
den Entwickler, die das Repository selbst vorbereiten; für den
repository-seitigen Vertrag (`.argos/`-Dateien, das Quality Gate) siehe
[PREPARE-PROJECT.md](PREPARE-PROJECT.md).

Projekte finden Sie unter **Configuration → Projects** im Admin-Panel
(`${APP_URL}/admin`).

- [Was ein Projekt ist](#what-a-project-is)
- [Ein Projekt anlegen und bearbeiten](#creating-and-editing-a-project)
- [Tab „General“](#general-tab)
  - [Plattform](#platform)
  - [Authentifizierung](#authentication)
  - [Allgemeine Einstellungen](#general-settings)
  - [Repository](#repository)
- [Tab „Worker“](#worker-tab)
  - [Worker (Stack & Agent)](#worker-stack--agent)
  - [Backing Services für Tests](#backing-services-for-tests)
  - [Live-Demo](#live-demo)
  - [Umgebung & Secrets](#environment--secrets)
- [Tab „Automation“](#automation-tab)
- [Pflicht vs. optional — auf einen Blick](#required-vs-optional--at-a-glance)

## Was ein Projekt ist

Ein Projekt (intern ein `RepoProfile`) bündelt alles, was Argos braucht, um an
einem Repository zu arbeiten:

- Die **Repository-Adresse** und den **Default-Branch**, auf dem neue Arbeit
  aufsetzt.
- Die **Zugangsdaten**, mit denen Argos klont, pusht und Pull-/Merge-Requests
  eröffnet.
- Die **Worker-Konfiguration** — das Basis-Image und der Agent, aus denen das
  Workspace-Image gebaut wird.
- Die **Umgebung und Secrets**, die in Workspaces (und die Live-Vorschau)
  eingespeist werden, damit Abhängigkeiten installiert werden und Tests
  durchlaufen.
- Optionale **Backing Services** (MySQL / Redis), die neben jedem Workspace
  laufen.
- Ob Workspaces eine **Live-Vorschau** starten dürfen.
- Die **Automatisierungs**-Standardwerte für automatische Läufe (Auto-Threads)
  und die Person, für die sie handeln.

Ein Projekt hat viele **Workspaces**. Ein neuer Workspace klont das Repository
des Projekts auf dessen Default-Branch (oder einem Branch Ihrer Wahl), läuft in
einem Image, das aus der Worker-Konfiguration des Projekts gebaut wird, und
authentifiziert sich mit den Zugangsdaten des Projekts. Siehe
[WORKSPACES.md](WORKSPACES.md).

Ein Projekt hat außerdem auf seiner Bearbeitungsseite zwei zugehörige Listen:
seine **Issue-Trigger-Bindungen** (siehe
[SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md)) und seine **API-Tokens**
(projektbezogene Sanctum-Tokens, z. B. für CI). Die Projektliste zeigt, wie
viele Workspaces jedes Projekt hat.

## Ein Projekt anlegen und bearbeiten

Öffnen Sie **Configuration → Projects** und wählen Sie **New project**, oder
klicken Sie auf eine bestehende Projektzeile, um sie zu bearbeiten. Es gibt
keine separate schreibgeschützte Detailansicht — die Zeile öffnet direkt das
Bearbeitungsformular, in dem auch die zugehörigen Listen gerendert werden.

Das Formular hat drei Tabs, **General**, **Worker** und **Automation**. Die allererste
Entscheidung ist die **Plattform**; bis Sie eine auswählen, bleibt der Rest des
Formulars gesperrt. Sobald eine Plattform gewählt ist, erscheinen die übrigen
Abschnitte, angepasst an diese Plattform und daran, welche Konten Sie verbunden
haben.

## Tab „General“

### Plattform

Wählen Sie die Git-Plattform, auf der das Repository liegt:

- **GitHub**
- **GitLab** (einschließlich selbst gehosteter Instanzen)
- **Bitbucket**

Dies ist der Türöffner für das gesamte Formular. Die Plattform bestimmt, welche
Authentifizierungsoptionen Sie erhalten, wie sich die Repository-Auswahl
verhält und wie die Clone-URL gebildet wird. Sobald Sie eine Plattform wählen,
erscheint ein kurzer Einrichtungshinweis mit einem Link zur passenden
Plattform-Anleitung ([SETUP-GITHUB.md](SETUP-GITHUB.md),
[SETUP-GITLAB.md](SETUP-GITLAB.md), [SETUP-BITBUCKET.md](SETUP-BITBUCKET.md)).

Für **selbst gehostetes GitLab** wird die Instanz über das verbundene Konto
(OAuth) oder über `GITLAB_INSTANCE_URL` für manuelle Einrichtungen
berücksichtigt — siehe [SETUP-GITLAB.md](SETUP-GITLAB.md).

### Authentifizierung

Argos benötigt Zugangsdaten, um das Repository zu klonen und Branches zu
pushen / PRs zu eröffnen. Es gibt zwei Methoden:

- **Personal Access Token (PAT)** — Sie fügen einen Token direkt in das Projekt
  ein. Der Token wird verschlüsselt gespeichert. Für GitHub genügt ein Token
  mit dem Scope `repo`; GitLab benötigt `api` und `write_repository`; Bitbucket
  verwendet einen gescopten **Repository Access Token** (direkt einfügen, ohne
  Benutzernamen-Präfix). Siehe die Plattform-Anleitungen oben und
  [CREDENTIALS.md](CREDENTIALS.md).
- **OAuth (verbundenes Konto)** — Argos verwendet ein Git-Konto, das Sie einmal
  zentral verbunden haben, und nutzt es projektübergreifend wieder.
  OAuth-Tokens werden automatisch erneuert, bevor sie ablaufen. Siehe
  [OAUTH.md](OAUTH.md).

Der Abschnitt **Authentication**, in dem Sie die Methode und (bei OAuth) das
verbundene Konto auswählen, erscheint nur, wenn Sie für die gewählte Plattform
ein verbundenes Konto haben. Haben Sie kein verbundenes Konto, gibt es nichts
auszuwählen — das Projekt verwendet dann einfach einen PAT, den Sie weiter
unten im Abschnitt „Repository“ eingeben.

Die Wahl von OAuth löscht jeden PAT und bindet das Projekt an das ausgewählte
Konto; ein Wechsel zurück zu PAT löscht die Kontobindung. Bei GitHub und GitLab
passt sich die Bezeichnung des OAuth-Kontos an die Plattform an; Bitbucket hat
seine eigene OAuth-Option.

### Allgemeine Einstellungen

Diese erscheinen, sobald eine Plattform gewählt ist:

- **Project Name** (Pflicht) — der Anzeigename im Panel. Wenn Sie ein
  Repository über die OAuth-Auswahl wählen und den Namen leer lassen, füllt
  Argos ihn mit dem Kurznamen des Repositorys vor.

### Repository

Wie Sie auf das Repository verweisen, hängt davon ab, ob Sie auf dem Pfad
**OAuth (verbunden)** oder dem **manuellen** Pfad sind:

**Verbundener Pfad** (Plattform + OAuth gewählt, mit passendem Konto):

- **Repo URL** — ein durchsuchbares Dropdown der Repositorys, die das verbundene
  Konto sehen kann. Die Auswahl eines Repositorys füllt die Clone-URL für Sie
  aus (und den Namen, falls leer).
- **Default Branch** — ein durchsuchbares Dropdown der Branches dieses
  Repositorys; Argos wählt den von der API gemeldeten Default-Branch des
  Repositorys vor.

**Manueller Pfad** (PAT oder jede Plattform ohne verbundenes Konto):

- **Repo URL** (Pflicht) — die vollständige Clone-URL, z. B.
  `https://github.com/owner/repo`. Abschließende Schrägstriche und `.git` werden
  beim Speichern normalisiert.
- **Token (PAT)** (Pflicht) — der unter [Authentifizierung](#authentication)
  beschriebene Access-Token. Verschlüsselt gespeichert; maskiert angezeigt.
- **Default Branch** (Pflicht) — sobald eine gültige URL und ein gültiger Token
  vorliegen, fragt Argos die Plattform ab und bietet die Branch-Liste als
  durchsuchbares Dropdown an.

Der **Default-Branch** ist der Branch, von dem Workspaces in diesem Projekt
abzweigen (sofern Sie keinen anderen Basis-Branch wählen) und auf den sie mit
ihrem Pull Request zielen.

## Tab „Worker“

### Worker (Stack & Agent)

Dieser Abschnitt definiert das Image, in dem die Workspaces des Projekts
laufen.

- **Image source** — woher das Basis-Image des Workers stammt:
  - **Registered stack** — verwenden Sie einen der in Argos konfigurierten
    Worker-Stacks (ein Basis-Image mit einer PHP-Version und Werkzeugen). Siehe
    [WORKER-STACKS.md](WORKER-STACKS.md).
  - **Own Dockerfile in the repo (BYOI)** — Argos liest
    `.argos/worker.dockerfile` aus Ihrem Repository (am Base-Branch) als Basis
    und legt den Agent und die Workspace-Werkzeuge darüber. Benötigte Werkzeuge müssen
    über `FROM`/`RUN` hereinkommen; kein `COPY` aus dem Repo (der Build-Kontext
    ist nicht das Repo). Siehe [BYOI.md](BYOI.md).
- **Worker Stack** — welcher registrierte Stack verwendet wird (nur bei der
  Quelle *Registered stack* sichtbar). Leer lassen, um den
  Argos-Standard-Stack zu verwenden.
- **Agent** — welchen Coding-Agent das Worker-Image installiert. Claude Code ist
  derzeit der einzige Agent; lassen Sie das Feld leer. Siehe
  [AGENTS.md](AGENTS.md).

### Backing Services für Tests

Manche Test-Suites brauchen eine Datenbank oder einen Cache. Aktivieren Sie die
Services, die Ihre Tests benötigen, und Argos fährt sie **neben jedem
Workspace** hoch — im eigenen privaten Netzwerk des Workspaces, mit
eingespeisten Zugangsdaten. Ihre Daten liegen in Volumes des Workspaces, sodass
ein gestoppter und neu gestarteter Workspace seine Datenbank behält; beim
Löschen des Workspaces werden sie entfernt:

- **MySQL / MariaDB** — erreichbar unter Host `db`, Port `3306`.
- **Redis** — erreichbar unter Host `redis`, Port `6379`.

Projekte, die standardmäßige Laravel-Verbindungsnamen verwenden (`DB_HOST`,
`DB_DATABASE`, `REDIS_HOST`, …), brauchen nichts weiter — Argos speist diese
automatisch ein. Für abweichende Namen verdrahten Sie sie mit den Platzhaltern,
die unter [Umgebung & Secrets](#environment--secrets) beschrieben sind.

Wenn MySQL aktiviert ist, können Sie dessen Zugangsdaten überschreiben, damit
sie zu dem passen, was Ihr Projekt fest verdrahtet hat:

- **MySQL database** (Standard `argos`)
- **MySQL user** (Standard `argos`)
- **MySQL password** (Standard `argos`)

Lassen Sie sie leer, um die Argos-Standardwerte zu verwenden. Host und Port sind
fest und nicht konfigurierbar. Redis hat keine konfigurierbaren Zugangsdaten.

Diese Services bedienen sowohl den Workspace (einschließlich seiner Quality
Gates) als auch die Live-Vorschau, sodass beide aus derselben Definition
hochfahren.

### Live-Demo

- **Enable live demo** — wenn aktiviert, können Workspaces dieses Projekts eine
  Live-Vorschau auf einer eigenen Subdomain starten.

Dies setzt voraus, dass Ihr Repository den Demo-Vertrag am Base-Branch
mitliefert: `.argos/demo.compose.yml` (die Laufzeit) und `.argos/demo.yml`
(Einstellungen wie Entry-Service, Port und Befehle). Fehlen sie, schlägt der
Demo-Build mit einer klaren Meldung fehl. Siehe [LIVE-DEMOS.md](LIVE-DEMOS.md)
und [PREPARE-PROJECT.md](PREPARE-PROJECT.md).

### Umgebung & Secrets

Projektspezifische Secrets speichert Argos **verschlüsselt** und speist sie
sowohl in den Workspace (Dependency-Install, Befehle von Claude, Quality Gates)
als auch in die Live-Vorschau ein. Zwei Arten:

**Private Composer-Registries** — auth-geschützte Composer-Quellen (Private
Packagist, Satis, Flux, Scramble, …). Für jede fügen Sie hinzu:

- **Host** (Pflicht) — z. B. `packages.filamentphp.com`
- **Username** — optional; standardmäßig `token`, wenn leer gelassen
- **Token** (Pflicht) — das Passwort/der Token der Registry

Argos setzt diese zu einem einzigen `COMPOSER_AUTH`-HTTP-Basic-Blob zusammen,
damit `composer install` die privaten Registries sowohl im Workspace als auch in
der Vorschau erreicht.

**Zusätzliche Umgebungsvariablen** — beliebige Name/Wert-Paare (zusätzliche
API-Keys, eigene Datenbanknamen usw.). Für jede:

- **Name** (Pflicht) — z. B. `MEILISEARCH_KEY`
- **Value** — verschlüsselt gespeichert

Einige wichtige Regeln:

- Eine hier von Hand geschriebene `COMPOSER_AUTH`-Variable **überschreibt** die
  aus den oben genannten Registries generierte — das ist das bewusste
  Schlupfloch.
- Argos-eigene Variablen können nicht überschrieben werden. Namen, die es selbst
  setzt (z. B. `REPO_TOKEN`, `APP_KEY`, `APP_URL`, die Agent-Zugangsdaten, die
  Verdrahtung des Workspace-Daemons, …), werden aus Ihrer Liste entfernt, sodass ein
  Projekt-Secret niemals die eigene Verdrahtung von Argos überschreiben kann.

**Platzhalter für Backing Services.** Wenn Sie oben MySQL oder Redis aktiviert
haben, können Sie deren aufgelöste Koordinaten in jedem Wert hier referenzieren,
und Argos ersetzt zur Laufzeit den echten internen Host bzw. die echten
Zugangsdaten. Das Formular zeigt die genauen Platzhalter, die für die von Ihnen
aktivierten Services verfügbar sind. Sie lauten:

- MySQL: `${mysql.host}`, `${mysql.port}`, `${mysql.database}`,
  `${mysql.username}`, `${mysql.password}`
- Redis: `${redis.host}`, `${redis.port}`

Damit kann ein Projekt mit abweichenden Env-Namen zu den Sidecars überbrücken,
ohne interne Hosts oder Zugangsdaten fest zu verdrahten — setzen Sie
beispielsweise
`MY_DB_DSN=mysql://${mysql.username}:${mysql.password}@${mysql.host}/${mysql.database}`.

## Tab „Automation“

Standardwerte für [Auto-Threads](WORKSPACES.md#auto-threads) — automatische
Läufe, gestartet in der Oberfläche, über die [REST-API](REST-API.md),
[MCP](SETUP-MCP.md) oder einen [Issue-Trigger](SETUP-TASK-PROVIDERS.md). Ein
einzelner Lauf kann sie überschreiben; leere Felder verwenden die Standardwerte
der Installation aus der [Konfiguration](CONFIGURATION.md#auto-threads).

- **Automation user** — die Person, für die automatische Läufe aus den
  API-Tokens und Issue-Triggern dieses Projekts handeln: Ihr Claude-Agent
  erledigt die Arbeit, und ihr gehört der Workspace. Ohne sie starten
  Issue-Trigger nichts (die Bindung zeigt den Fehler).
- **Plan** — *aus* (sofort umsetzen), *automatisch* (planen, dann weitermachen)
  oder *auf Freigabe warten* (planen, dann anhalten, bis jemand freigibt).
- **When done** — einen Pull Request, einen Draft-Pull-Request öffnen oder nur
  committen und pushen.
- **Gate fix rounds** — wie oft Claude versuchen darf, fehlschlagende Quality
  Gates zu beheben, bevor trotzdem ausgeliefert wird.
- **Max. turns**, **Max. cost (USD)**, **Max. runtime (minutes)** — Budgets, die
  einen Lauf stoppen. Kosten von `0` bedeuten kein Limit.

## Pflicht vs. optional — auf einen Blick

**Pflicht**

- Plattform
- Project Name
- Repository: entweder das OAuth-Repository + Branch oder (manueller Pfad) Repo
  URL + Token (PAT) + Default Branch
- Authentifizierungsmethode, wenn der Abschnitt „Authentication“ angezeigt wird
  (Standard PAT); das verbundene Konto, wenn die Methode OAuth ist; Host und
  Token einer privaten Composer-Registry, wenn Sie eine hinzufügen

**Optional (mit sinnvollen Standardwerten)**

- Image source (Standard Registered stack), Worker Stack (Standard
  Argos-Stack), Agent (Standard Claude Code)
- Backing Services und ihre MySQL-Zugangsdaten-Überschreibungen
- Live-Demo (Standard aus)
- Zusätzliche Umgebungsvariablen und Composer-Registries
- Automation user und die Automatisierungs-Standardwerte (standardmäßig die
  Einstellungen der Installation)
