# So funktioniert Argos

Diese Seite zeigt das Gesamtbild: was Argos ist, die wenigen Konzepte, die du
im Kopf haben solltest, und wie ein Stück Arbeit von einem Satz, den du tippst,
zu einem Pull Request wird, den du prüfen kannst. Lies sie einmal und folge
dann den Links in die Detail-Dokumente, wenn du sie brauchst.

## Was ist Argos

Argos ist **Claude Code im Browser, für ein Team**. Ein **Workspace** ist ein
Projekt — ein Repository mit seinem Stack, seinen Services, Secrets und seiner
Preview — und läuft in einem Container. Jede Unterhaltung mit Claude darin ist
eine **Aufgabe**: Sie gehört einer Person, hat ihren eigenen Branch, ihre
eigene Arbeitskopie und Datenbank und endet in ihrem eigenen Pull Request. Du
chattest mit Claude, während es arbeitet, gibst seine Änderungen frei,
bearbeitest Dateien selbst in VS Code, klickst das Ergebnis in einer
Live-Preview durch und lieferst den Pull Request aus. Kolleginnen und Kollegen
können mitlesen oder eingeladen werden, in derselben Aufgabe mitzuarbeiten.

Die Arbeit muss nicht interaktiv sein: Eine **automatische Aufgabe** plant,
setzt um, prüft die Quality Gates des Projekts, behebt, was fehlschlägt, und
öffnet den Pull Request selbstständig — gestartet aus der Oberfläche, über die
REST-API, MCP oder ein Issue in deinem Tracker. Du behältst die Kontrolle: Du
kannst zusehen, den Plan freigeben oder jederzeit übernehmen, und du prüfst den
Pull Request, bevor er gemergt wird.

Zwei Dinge unterscheiden Argos davon, eine API in einer Schleife aufzurufen:

- **Es läuft auf deinem Claude-Abo, nicht auf der API.** Argos nutzt den
  Claude-Code-OAuth-Token aus `claude setup-token`, sodass dein bestehender
  Pro- / Max- / Team-Plan die Arbeit abdeckt — es gibt keine Abrechnung pro
  API-Token. Eine Aufgabe läuft immer auf dem Abo der Person, der sie gehört.
- **Jeder Workspace läuft in seinem eigenen Container.** Der Agent berührt nie
  den Manager-Host oder andere Workspaces; er bekommt das Repository, die
  Backing Services des Workspaces und seine Preview, sonst nichts.

> Argos ist auf PHP-/Laravel-Projekte abgestimmt — die Quality Gates binden
> Composer, Pint, Pest/PHPUnit und PHPStan ein, und Laravel-Repositorys
> brauchen gar keine Einrichtung. Andere Stacks funktionieren; dort werden die
> Gates übersprungen, und die
> [Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe) hilft, das
> Repository zum Laufen zu bringen.

## Das mentale Modell: Manager und Workspaces

### Der Manager — die App, die du nutzt

Der Manager ist die Webanwendung, in die du dich einloggst: Deine Arbeit liegt
unter `${APP_URL}/`, dein Konto (Profil, Claude-Abos, verknüpfte Accounts)
unter `${APP_URL}/account`, die Administration (Benutzer, Access-Tokens,
OAuth-Apps, API-Clients, Systemübersicht) unter `${APP_URL}/system` und die
Dokumentation unter `${APP_URL}/docs`. Der
Manager hält alle deine Daten — Aufgaben, Entscheidungen, Einstellungen — und
entscheidet, *was* passieren darf. **Er führt die KI nie selbst aus**; er hat
keine eigene Claude-Session.

### Der Workspace-Container — wo die Arbeit passiert

Jeder Workspace ist ein **Docker-Container**, den der Manager für ihn startet.
Er enthält einen Haupt-Klon des Repositorys und pro Aufgabe einen Git-Worktree
unter `/workspace/tasks/<slug>`, daneben die Backing Services (MySQL / Redis),
OpenVSCode Server für den Editor und einen kleinen **Daemon**, der die
Claude-Sessions ausführt und ihre Ereignisse an den Manager weitergibt. Ein
Workspace stoppt bei Leerlauf und startet wieder, wenn du ihn öffnest oder eine
Aufgabe ihn braucht; Dateien und Datenbanken bleiben erhalten.

Der Container ist abgeschottet: Er bekommt das Repository, einen
Repository-Zugang über einen Git-Helper und sonst nichts. Er hat **keinen
Docker-Socket** und keinen Zugriff auf die Datenbank des Managers. Sein Image
kommt aus einem eingebauten [Stack](WORKER-STACKS.md) (PHP 8.5, PHP 8.4 oder
Node 22) oder aus der eigenen `.argos/worker.dockerfile` des Repositorys.

Die Aufgaben eines Workspaces teilen sich den Container. Sie werden durch
Leitplanken auseinandergehalten (eigener Worktree, eigene Datenbank,
Datei-Werkzeuge auf den Ordner der Aufgabe beschränkt), **nicht** durch eine
Sicherheitsgrenze: Die Mitglieder eines Workspaces vertrauen einander, wie in
einem gemeinsamen Repository.

## Der Kernablauf auf einen Blick

1. **Zugangsdaten verbinden.** Lege deinen Claude-Agenten an (den Token aus
   `claude setup-token`) und verknüpfe ein Git-Konto oder einen Personal Access
   Token. Siehe [Agenten](AGENTS.md), [Zugangs-Tokens](CREDENTIALS.md) und
   [OAuth](OAUTH.md).
2. **Einen Workspace anlegen** (Argos-Admins). Wähle das Repository; Argos
   untersucht es, wählt einen Stack und startet — wenn etwas fehlt — eine
   [Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe), die `.argos/`
   schreibt. Füge die Personen hinzu, die darin arbeiten. Siehe
   [Workspaces](WORKSPACES.md) und [Workspace-Einstellungen](WORKSPACE-SETTINGS.md).
3. **Eine Aufgabe starten.** Beschreibe, was du möchtest, und wähle optional
   ein [Ticket](TICKETS.md). Argos legt Branch, Worktree und Datenbank der
   Aufgabe an und führt die Einrichtungsschritte aus; dann startet Claude. Siehe
   [Aufgaben](TASKS.md).
4. **Arbeiten.** Claude liest, bearbeitet und führt Befehle aus und fragt dabei
   so nach Freigaben, wie es der Modus der Aufgabe verlangt. Prüfe die
   [Quality Gates](TASKS.md#quality-gates), das
   [Review](TASKS.md#das-review) und die [Preview](LIVE-DEMOS.md).
5. **Ausliefern.** Claude committet fertige Teile selbst; **Committen** und
   **Pushen & PR** committen den Rest, pushen den Branch und öffnen den Pull
   Request; die Seitenleiste verfolgt dann seine
   [Checks, Reviews und Konflikte](PULL-REQUESTS.md). Gemergt → die Aufgabe ist
   erledigt; geschlossen → abgebrochen.

Oder lass es selbst laufen: Eine
[automatische Aufgabe](TASKS.md#automatische-aufgaben) erledigt die Schritte 4 und 5
allein —

```
Plan  →  Implement  →  Quality gates ⇄ Fix  →  Pull request
```

— und ein [Issue-Trigger](SETUP-TASK-PROVIDERS.md) startet eine solche Aufgabe
für jedes neue passende Issue in GitHub, GitLab oder Linear.

## Glossar

- **[Workspace](WORKSPACES.md)** — ein Projekt: Repository, Default-Branch,
  Stack, Services, Secrets, Preview, Grenzen, Automatik, Integrationen und
  Mitglieder (Admin / Mitglied / Leser). Ein Container.
- **[Aufgabe](TASKS.md)** — eine Unterhaltung mit Claude in einem Workspace,
  die einer Person gehört, mit eigenem Branch, Worktree, eigener Datenbank,
  Preview und eigenem Pull Request. Mitarbeitende können eingeladen werden.
- **[Automatische Aufgabe](TASKS.md#automatische-aufgaben)** — eine Aufgabe, die
  selbstständig läuft: planen, umsetzen, fehlschlagende Gates beheben,
  ausliefern.
- **[Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe)** — eine
  besondere Aufgabe, in der Claude die `.argos/`-Dateien des Workspaces
  schreibt (Einrichtungsschritte, Preview-Vertrag, optional ein Image);
  **Einrichtung testen** prüft sie.
- **[Ticket](TICKETS.md)** — ein Issue in GitHub, GitLab, Linear oder deinem
  eigenen System, das mit einer Aufgabe verknüpft ist. Argos meldet dorthin
  zurück und schließt es beim Merge, wo die Integration das vorsieht.
- **[Pull Request](PULL-REQUESTS.md)** — der Pull-/Merge-Request der Aufgabe,
  mit Checks, Reviews, Konflikten und Auto-Merge in der Aufgabe angezeigt.
- **[Stack](WORKER-STACKS.md)** — das Image, in dem ein Workspace läuft: ein
  eingebauter Stack oder die `.argos/worker.dockerfile` des Repositorys.
- **[Agent](AGENTS.md)** — ein Claude-Code-Zugang (Abo), auf dem eine Aufgabe
  läuft.
- **[Zugangsdaten](CREDENTIALS.md)** — ein gespeichertes Secret: dein
  Claude-Token und ein Git-Token — ein **Personal Access Token (PAT)** oder
  eine **OAuth**-Kontobindung (die außerdem die Repository-/Branch-Auswahl
  freischaltet). Siehe [OAuth](OAUTH.md).
- **[Issue-Trigger](SETUP-TASK-PROVIDERS.md)** — eine Verbindung zu einem
  Issue-Tracker, die für jedes neue passende Issue eine automatische Aufgabe
  startet.
- **[Preview](LIVE-DEMOS.md)** — ein Wegwerf-Deployment der aktuellen Dateien
  einer Aufgabe unter einer eigenen Subdomain.
- **[MCP / REST-API](SETUP-MCP.md)** — steuere Argos von außerhalb der
  Weboberfläche: aus Claude Code über den eingebauten MCP-Server oder aus
  Skripten über die [REST-API v1](REST-API.md).

## Wohin als Nächstes

- **Neue Installation?** Beginne mit der [Einrichtung](SETUP.md).
- **Möchtest du Repository-/Branch-Auswahl?** Verbinde eine
  [OAuth-App](OAUTH.md).
- **Machst du ein Repository bereit?** Siehe
  [Ein Repository vorbereiten](PREPARE-PROJECT.md) — `.argos/`, die
  Einrichtungs-Aufgabe und der Preview-Vertrag.
- **Bereit zu arbeiten?** Lies [Workspaces](WORKSPACES.md), dann
  [Aufgaben](TASKS.md).
- **Automatisierung?** Richte [Issue-Trigger](SETUP-TASK-PROVIDERS.md),
  [Tickets aus deinem eigenen System](TICKETS.md#eigene-ticket-systeme), den
  [MCP-Server](SETUP-MCP.md) oder die [REST-API](REST-API.md) ein.
