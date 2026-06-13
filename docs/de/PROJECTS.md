# Projekte

Ein **Projekt** in Argos ist ein konfiguriertes Git-Repository — die Einheit,
an der Argos arbeitet. Bevor Sie Argos eine Aufgabe übergeben können, legen Sie
ein Projekt an, das festlegt, *welches* Repository verwendet wird, *wie*
authentifiziert wird, *welche* Umgebung der Code zum Bauen und Testen benötigt
und *welche* Standardwerte beim Ausführen gelten. Jede Aufgabe, die Sie
starten, ist genau einem Projekt zugeordnet und erbt diese Einstellungen (die
meisten lassen sich pro Aufgabe weiterhin überschreiben).

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
- [Tab „Worker & models“](#worker--models-tab)
  - [Worker (Stack & Agent)](#worker-stack--agent)
  - [Backing Services für Tests](#backing-services-for-tests)
  - [Live-Demo](#live-demo)
  - [Umgebung & Secrets](#environment--secrets)
  - [Modelle](#models)
- [Pflicht vs. optional — auf einen Blick](#required-vs-optional--at-a-glance)

## Was ein Projekt ist

Ein Projekt (intern ein `RepoProfile`) bündelt alles, was Argos braucht, um an
einem Repository zu arbeiten:

- Die **Repository-Adresse** und den **Default-Branch**, auf dem neue Arbeit
  aufsetzt.
- Die **Zugangsdaten**, mit denen Argos klont, pusht und Pull-/Merge-Requests
  eröffnet.
- Die **Worker-Konfiguration** — das Basis-Image und der Agent, die die Phasen
  Concept / Implement / Push ausführen.
- Die **Umgebung und Secrets**, die in den Worker (und die Live-Demo)
  eingespeist werden, damit Abhängigkeiten installiert werden und Tests
  durchlaufen.
- Optionale **Backing Services** (MySQL / Redis) für den Testlauf.
- Eine optionale **Live-Demo**, die nach jedem Implement hochfährt.
- Projektspezifische Standardwerte für **Modell** und **Turn-Budget**.

Ein Projekt hat viele **Aufgaben**. Wenn Sie eine Aufgabe anlegen, klont sie
das Repository des Projekts auf dem Default-Branch des Projekts, läuft in einem
Worker, der aus der Worker-Konfiguration des Projekts gebaut wird, und
authentifiziert sich mit den Zugangsdaten des Projekts. Zum Lebenszyklus der
Aufgabe selbst siehe [TASKS.md](TASKS.md).

Ein Projekt hat außerdem auf seiner Bearbeitungsseite drei zugehörige Listen:
seine **Aufgaben**, seine **Task-Provider-Bindungen** (siehe
[SETUP-TASK-PROVIDERS.md](SETUP-TASK-PROVIDERS.md)) und seine **API-Tokens**
(projektbezogene Sanctum-Tokens, z. B. für CI).

## Ein Projekt anlegen und bearbeiten

Öffnen Sie **Configuration → Projects** und wählen Sie **New project**, oder
klicken Sie auf eine bestehende Projektzeile, um sie zu bearbeiten. Es gibt
keine separate schreibgeschützte Detailansicht — die Zeile öffnet direkt das
Bearbeitungsformular, in dem auch die zugehörigen Listen gerendert werden.

Das Formular hat zwei Tabs, **General** und **Worker & models**. Die allererste
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
- **Auto-start concept** — wenn aktiviert, startet die Concept-Phase
  automatisch, sobald eine Aufgabe in diesem Projekt angelegt wird, statt
  darauf zu warten, dass Sie sie manuell starten.
- **Auto-create PR** — wenn aktiviert, läuft die Push-Phase (die den
  Pull-/Merge-Request eröffnet) nach einer erfolgreichen Implementierung
  automatisch, statt auf Ihre Freigabe zu warten.

Beide Schalter sind Komfortautomatisierungen; lassen Sie sie aus, wenn Sie
zwischen jeder Phase prüfen möchten.

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

Der **Default-Branch** ist der Branch, von dem jede Aufgabe in diesem Projekt
abzweigt und auf den sie mit ihrem PR zielt.

## Tab „Worker & models“

### Worker (Stack & Agent)

Dieser Abschnitt definiert die Umgebung, die die Phasen ausführt. Es ist der
projektweite Standard; einzelne Einstellungen lassen sich pro Aufgabe
überschreiben.

- **Image source** — woher das Basis-Image des Workers stammt:
  - **Registered stack** — verwenden Sie einen der in Argos konfigurierten
    Worker-Stacks (ein Basis-Image mit einer PHP-Version und Werkzeugen). Siehe
    [WORKER-STACKS.md](WORKER-STACKS.md).
  - **Own Dockerfile in the repo (BYOI)** — Argos liest
    `.argos/worker.dockerfile` aus Ihrem Repository (am Base-Branch) als Basis
    und legt den Agent und den Worker-Code darüber. Benötigte Werkzeuge müssen
    über `FROM`/`RUN` hereinkommen; kein `COPY` aus dem Repo (der Build-Kontext
    ist nicht das Repo). Siehe [BYOI.md](BYOI.md).
- **Worker Stack** — welcher registrierte Stack verwendet wird (nur bei der
  Quelle *Registered stack* sichtbar). Leer lassen, um den
  Argos-Standard-Stack zu verwenden.
- **Agent** — welcher Coding-Agent die Phasen ausführt (z. B. Claude Code). Leer
  lassen, um Claude Code zu verwenden. Die unten verfügbaren **Modelle** hängen
  vom gewählten Agent ab, daher löscht ein Wechsel des Agents alle fixierten
  Modellauswahlen. Siehe [AGENTS.md](AGENTS.md).

### Backing Services für Tests

Manche Test-Suites brauchen eine Datenbank oder einen Cache. Aktivieren Sie die
Services, die Ihre Tests benötigen, und Argos fährt sie für jeden
Implement-Lauf **ephemer** hoch — in ihrem eigenen privaten Netzwerk, mit
eingespeisten Zugangsdaten, und baut sie anschließend wieder ab:

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

Diese Services bedienen sowohl den Worker-Testlauf als auch die Live-Demo,
sodass beide aus derselben Definition hochfahren.

### Live-Demo

- **Enable live demo** — wenn aktiviert, fährt Argos nach jedem Implement
  automatisch eine Live-Demo auf einer eigenen Subdomain hoch.

Dies setzt voraus, dass Ihr Repository den Demo-Vertrag am Base-Branch
mitliefert: `.argos/demo.compose.yml` (die Laufzeit) und `.argos/demo.yml`
(Einstellungen wie Entry-Service, Port und Befehle). Fehlen sie, schlägt der
Demo-Build mit einer klaren Meldung fehl. Siehe [LIVE-DEMOS.md](LIVE-DEMOS.md)
und [PREPARE-PROJECT.md](PREPARE-PROJECT.md).

### Umgebung & Secrets

Projektspezifische Secrets speichert Argos **verschlüsselt** und speist sie
sowohl in den Worker (Dependency-Install + Quality Gates) als auch in die
Live-Demo ein. Zwei Arten:

**Private Composer-Registries** — auth-geschützte Composer-Quellen (Private
Packagist, Satis, Flux, Scramble, …). Für jede fügen Sie hinzu:

- **Host** (Pflicht) — z. B. `packages.filamentphp.com`
- **Username** — optional; standardmäßig `token`, wenn leer gelassen
- **Token** (Pflicht) — das Passwort/der Token der Registry

Argos setzt diese zu einem einzigen `COMPOSER_AUTH`-HTTP-Basic-Blob zusammen,
damit `composer install` die privaten Registries sowohl im Worker als auch in
der Demo erreicht.

**Zusätzliche Umgebungsvariablen** — beliebige Name/Wert-Paare (zusätzliche
API-Keys, eigene Datenbanknamen usw.). Für jede:

- **Name** (Pflicht) — z. B. `MEILISEARCH_KEY`
- **Value** — verschlüsselt gespeichert

Einige wichtige Regeln:

- Eine hier von Hand geschriebene `COMPOSER_AUTH`-Variable **überschreibt** die
  aus den oben genannten Registries generierte — das ist das bewusste
  Schlupfloch.
- Argos-eigene Variablen können nicht überschrieben werden. Namen, die es selbst
  setzt (z. B. `REPO_TOKEN`, `APP_KEY`, `APP_URL`, `TASK_ID`, `CLAUDE_MODEL`,
  die Agent-Zugangsdaten, …), werden aus Ihrer Liste entfernt, sodass ein
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

### Modelle

Phasenweise Standardwerte für Modell und Turn-Budget für dieses Projekt. Alle
optional.

- **Concept Model** / **Implement Model** — wählen Sie für jede Phase ein
  Modell aus den Optionen, die der gewählte Agent anbietet. Leer lassen, um das
  Standardmodell des Agents für diese Phase zu verwenden. Das Formular zeigt den
  Standard im Hinweis des Feldes.
- **Concept max-turns** / **Implement max-turns** — das Turn-Budget für jede
  Phase (zwischen 10 und 1000). Leer lassen für den globalen Standard. Eine
  Aufgabe kann diese weiterhin überschreiben.

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

- Auto-start concept, Auto-create PR (Standard aus)
- Image source (Standard Registered stack), Worker Stack (Standard
  Argos-Stack), Agent (Standard Claude Code)
- Backing Services und ihre MySQL-Zugangsdaten-Überschreibungen
- Live-Demo (Standard aus)
- Zusätzliche Umgebungsvariablen und Composer-Registries
- Concept-/Implement-Modell und max-turns (standardmäßig die Standardwerte des
  Agents / die globalen Standardwerte)
