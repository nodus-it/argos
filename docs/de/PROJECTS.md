# Workspace-Einstellungen

Ein Workspace ist das Projekt in Argos: das Repository plus alles, was es
braucht — Zugang, Default-Branch, Stack, Services, Secrets, Preview, Grenzen,
Automatik, Integrationen und Mitglieder. Diese Seite beschreibt jede
Einstellung. Was ein Workspace ist und wer was darf, steht unter
[Workspaces](WORKSPACES.md); die Repository-Seite (`.argos/`, die
Einrichtungs-Aufgabe) unter [Ein Repository vorbereiten](PREPARE-PROJECT.md).

**Wo:** Workspace-Admins (und Argos-Admins) öffnen die
**Workspace-Einstellungen** über den Kopf des Workspaces, unter
`/w/workspaces/{id}/settings`. Jeder Tab hat seinen eigenen
**Speichern**-Button. Argos-Admins finden dasselbe Formular im Admin-Panel
unter **Workspaces** (mit den Issue-Trackern und API-Tokens unter dem
Formular). Neue Workspaces legen nur Argos-Admins an — siehe
[Einen Workspace anlegen](WORKSPACES.md#einen-workspace-anlegen).

- [Anlegen: Plattform, Zugang, Repository](#anlegen-plattform-zugang-repository)
- [Allgemein](#allgemein)
- [Umgebung](#umgebung)
  - [Worker: Image-Quelle und Stack](#worker-image-quelle-und-stack)
  - [Backing Services](#backing-services)
  - [Live-Demo](#live-demo)
  - [Environment-Variablen und Platzhalter](#environment-variablen-und-platzhalter)
  - [Einrichtung](#einrichtung)
- [Ressourcen](#ressourcen)
- [Integrationen](#integrationen)
- [Automatik](#automatik)
- [Mitglieder](#mitglieder)

## Anlegen: Plattform, Zugang, Repository

Diese Felder werden beim Anlegen des Workspaces gesetzt. Plattform und
Repository stehen danach fest (sie zu ändern würde jeden Aufgaben-Branch und
Pull Request verwaisen lassen) — auf der Einstellungsseite in `/w` und im
Bearbeitungsformular im Admin-Panel. Dort lässt sich der Zugang weiterhin
erneuern, z. B. ein abgelaufener Token ersetzen.

- **Plattform** — GitHub, GitLab (einschließlich selbst gehosteter Instanzen)
  oder Bitbucket. Bis du eine auswählst, bleibt der Rest des Formulars
  gesperrt. Ein Hinweis verlinkt die Plattform-Anleitung
  ([GitHub](SETUP-GITHUB.md), [GitLab](SETUP-GITLAB.md),
  [Bitbucket](SETUP-BITBUCKET.md)).
- **Authentifizierung** — erscheint nur, wenn du für die Plattform ein
  verbundenes Konto hast:
  - **Personal Access Token (PAT)** — wird in den Workspace eingefügt und
    verschlüsselt gespeichert. GitHub: Scope `repo`; GitLab: `api` und
    `write_repository`; Bitbucket: ein Repository Access Token. Siehe
    [Zugangs-Tokens](CREDENTIALS.md).
  - **OAuth (verbundenes Konto)** — verwendet ein Git-Konto wieder, das du
    einmal verbunden hast; Tokens werden automatisch erneuert. Siehe
    [OAuth](OAUTH.md).
- **Repository** — mit OAuth eine durchsuchbare Liste der Repositorys des
  Kontos und ihrer Branches (der Default-Branch ist vorausgewählt); sonst die
  Clone-URL (z. B. `https://github.com/owner/repo`, abschließendes `/` und
  `.git` werden entfernt) plus der Token, danach lädt die Branch-Liste.
- **Name** — wird mit dem Kurznamen des Repositorys vorausgefüllt, wenn du
  eines auswählst.

Diesen Zugang nutzt Argos, um für **jede** Aufgabe des Workspaces zu klonen,
Aufgaben-Branches zu pushen und Pull Requests zu öffnen.

## Allgemein

| Feld | Bedeutung |
|---|---|
| Name | Anzeigename des Workspaces. |
| Repository | Adresse und Plattform, schreibgeschützt. |
| Default Branch | Neue Aufgaben zweigen davon ab, und ihre Pull Requests zielen darauf (sofern eine Aufgabe keine andere Basis wählt). |
| Branch-Muster | Wie Aufgaben-Branches heißen. Platzhalter: `{ticket}` (der Ticket-Schlüssel, oder `argos` ohne Ticket) und `{title}` (der Titel der Aufgabe als Slug). Leer: `{ticket}-{title}` mit Ticket, `argos/{title}` ohne. Erlaubte Zeichen: Buchstaben, Ziffern, `. _ / - { }`; max. 60. Ein vergebener Name bekommt eine Nummer. |

## Umgebung

### Worker: Image-Quelle und Stack

- **Image-Quelle**
  - **Registrierter Stack** — einer der eingebauten Stacks.
  - **Eigenes Dockerfile im Repo (BYOI)** — Argos baut
    `.argos/worker.dockerfile` aus dem Repository (an der Einrichtungs-Ref:
    dem Default-Branch oder einem getesteten, nicht gemergten
    Einrichtungs-Branch). Siehe [Eigenes Worker-Image](BYOI.md).
- **Worker-Stack** — *PHP 8.5*, *PHP 8.4* oder *Node 22* (bei BYOI
  ausgeblendet). Leer: der Standard der Installation (`ARGOS_DEFAULT_STACK`,
  `php-8.5`). Argos setzt ihn beim Anlegen des Workspaces anhand von
  `composer.json` / `package.json` vor. Siehe
  [Stacks und Images](WORKER-STACKS.md).

Ein bestandener Einrichtungstest, der eine `.argos/worker.dockerfile` gefunden
hat, stellt die Image-Quelle von selbst auf BYOI um.

### Backing Services

*Backing-Services für Tests* startet Sidecars neben dem Workspace-Container in
einem privaten Netz; ihre Daten bleiben erhalten, bis der Workspace gelöscht
wird:

| Wert | Service | Erreichbar unter |
|---|---|---|
| `mysql` | MySQL / MariaDB (`mariadb:11`) | Host `db`, Port `3306` |
| `redis` | Redis (`redis:7-alpine`) | Host `redis`, Port `6379` |

Jede Aufgabe bekommt ihre eigene MySQL-Datenbank (`task_<slug>`) und ihr
eigenes Redis-Präfix; Argos schreibt sie in die `.env` und die Umgebung der
Aufgabe (`DB_HOST`, `DB_DATABASE`, …, `REDIS_HOST`, `REDIS_PREFIX`,
`CACHE_PREFIX`). Projekte mit den Standard-Laravel-Namen brauchen nichts
weiter.

Mit aktiviertem MySQL kannst du **MySQL-Datenbank**, **MySQL-User** und
**MySQL-Passwort** setzen (jeweils Standard `argos`) — die Zugangsdaten, mit
denen der Sidecar angelegt wird; sie befüllen auch die mitgelieferte
Preview-Datenbank. Host und Port sind fest. Redis hat keine Zugangsdaten.

### Live-Demo

**Live-Demo aktivieren** erlaubt den Aufgaben dieses Workspaces, eine
[Preview](LIVE-DEMOS.md) auf einer eigenen Subdomain zu starten. Sie nutzt
`.argos/demo.compose.yml` + `.argos/demo.yml` aus dem Repository oder den
mitgelieferten Laravel-Vertrag, wenn das Repository keine davon hat. Siehe
[Ein Repository vorbereiten → Live-Preview](PREPARE-PROJECT.md#live-preview).

### Environment-Variablen und Platzhalter

*Environment & Secrets* — **verschlüsselt** gespeichert, in den
Workspace-Container (Einrichtungsschritte, Claudes Befehle, Gates) und in
Previews eingespeist:

- **Private Composer-Registries** — **Host** (Pflicht, z. B.
  `packages.filamentphp.com`), **Username** (Standard `token`), **Token**
  (Pflicht). Argos baut daraus einen `COMPOSER_AUTH`-HTTP-Basic-Blob.
- **Weitere Environment-Variablen** — **Name** (Pflicht) und **Wert**.

Regeln:

- Ein von Hand gesetztes `COMPOSER_AUTH` **überschreibt** das generierte.
- Argos-eigene Variablen (`REPO_TOKEN`, `APP_KEY`, `APP_URL`, die
  Agent-Zugangsdaten, die Daemon-Verdrahtung, …) werden entfernt — ein Secret
  überschreibt nie die Verdrahtung von Argos.
- Mit aktivierten Backing Services dürfen Werte Platzhalter nutzen, die Argos
  zur Laufzeit auflöst (das Formular listet die verfügbaren):
  - MySQL: `${mysql.host}`, `${mysql.port}`, `${mysql.database}`,
    `${mysql.username}`, `${mysql.password}`
  - Redis: `${redis.host}`, `${redis.port}`

  Beispiel: `MY_DB_DSN=mysql://${mysql.username}:${mysql.password}@${mysql.host}/${mysql.database}`.

### Einrichtung

Nur auf der Einstellungsseite, schreibgeschützt plus Aktionen:

- **Stand der Einrichtung** (*Einrichtung nötig*, *Wird eingerichtet*, *Wird
  getestet*, *Eingerichtet*, *Test fehlgeschlagen*, *Braucht ein Claude-Abo*)
  und, solange eine getestete Einrichtung nicht gemergt ist, der Branch, aus
  dem sie stammt.
- **Letzter Test** — jeder Schritt des letzten **Einrichtung testen** mit
  ✓ / ✗.
- **Aus dem Repository** — ob der Preview-Vertrag (`.argos/demo.yml`) und die
  Einrichtungsschritte (`.argos/setup.sh`) *im Repository* liegen, vom
  *Laravel-Standard* kommen, *fehlen* oder *nicht lesbar* sind.
- **Image** — Zustand und Alter des aktuellen Image-Builds, sein **Build-Log**
  und **Neu bauen** (der Workspace nutzt das neue Image ab seinem nächsten
  Start).
- **Einrichtung überarbeiten** / **Einrichtungs-Aufgabe öffnen** — siehe
  [Die Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe).

## Ressourcen

| Feld | Standard | Bedeutung |
|---|---|---|
| Parallele Sessions | `3` | Wie viele Aufgaben gleichzeitig mit Claude sprechen dürfen (1–20). Weitere Nachrichten warten in der [Session-Warteschlange](TASKS.md#session-warteschlange). |
| Parallele Previews | `3` | Gleichzeitig laufende Previews (1–20); eine weitere stoppt die älteste. |
| CPU-Grenze (Kerne) | `ARGOS_WORKSPACE_CPUS` | Für den Workspace-Container, 0,25–64. |
| Arbeitsspeicher-Grenze (MB) | `ARGOS_WORKSPACE_MEMORY` | Für den Workspace-Container, 512–262144. |
| Erledigte Aufgaben aufräumen nach (Tagen) | `14` | Preview, Worktree und Datenbank einer erledigten oder abgebrochenen Aufgabe werden nach so vielen Tagen entfernt (1–365); Unterhaltung, Remote-Branch und Pull Request bleiben. |

CPU und Arbeitsspeicher gelten ab dem nächsten Container-Start.

## Integrationen

- **Issue-Tracker** — GitHub-, GitLab- und Linear-Projekte, die dieser
  Workspace beobachtet, und eigene Ticket-Systeme. Siehe
  [Issue-Trigger](SETUP-TASK-PROVIDERS.md) und [Tickets](TICKETS.md).
- **API-Tokens** — Sanctum-Tokens, die an diesen Workspace gebunden sind
  (z. B. für CI oder ein eigenes Ticket-System). Sie erreichen nur diesen
  Workspace, handeln als dessen Automatik-Nutzer und können
  `workspaces:read`, `tasks:read` und `tasks:write` tragen (nie
  `workspaces:write`). Der Token wird einmal angezeigt. Siehe
  [REST-API](REST-API.md).

## Automatik

Standardwerte für [automatische Aufgaben](TASKS.md#automatische-aufgaben), die in
der Oberfläche, über die [REST-API](REST-API.md), [MCP](SETUP-MCP.md) oder
einen [Issue-Trigger](SETUP-TASK-PROVIDERS.md) gestartet werden. Ein einzelner
Lauf kann sie überschreiben; leere Felder nutzen die Standardwerte der
Installation aus der [Konfiguration](CONFIGURATION.md#automatische-aufgaben).

| Feld | Bedeutung |
|---|---|
| Automatik-Nutzer | Die Person, für die automatische Aufgaben der API-Tokens und Issue-Trigger dieses Workspaces handeln (sofern nicht die Person, der ein Issue zugewiesen ist, zugestimmt hat, sie zu übernehmen): Ihr Claude-Agent erledigt die Arbeit, und ihr gehört die Aufgabe. Ohne sie antworten Workspace-Tokens mit `no_actor`, und Issue-Trigger starten nichts. |
| Plan | *Kein Plan — direkt umsetzen* (`off`), *Planen, dann automatisch weiter* (`auto`), *Planen, dann auf Freigabe warten* (`approve`). |
| Wenn fertig | *Pull Request* (`pr`), *Entwurfs-Pull-Request* (`draft_pr`), *Nur committen und pushen* (`none`). |
| Gate-Fix-Runden | Wie oft Claude versuchen darf, fehlschlagende Quality Gates zu beheben, bevor trotzdem ausgeliefert wird (0–20). |
| Max. Turns, Max. Kosten (USD), Max. Laufzeit (Minuten) | Budgets, die einen Lauf stoppen. Kosten von `0` bedeuten kein Limit. |

## Mitglieder

Die Personen des Workspaces mit ihrer Rolle — *Admin*, *Mitglied* oder
*Leser* (siehe [Rollen](WORKSPACES.md#rollen)): ein Mitglied hinzufügen, eine
Rolle ändern, eine Person entfernen. Ein Workspace behält mindestens einen
Admin. Personen hinzuzufügen erfordert `ARGOS_WORKSPACE_COLLABORATION`. Wird
jemand entfernt, bleiben die Aufgaben, die der Person gehören; übergib sie,
wenn jemand weitermachen soll.
