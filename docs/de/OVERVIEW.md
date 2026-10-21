# Wie Argos funktioniert

Willkommen bei Argos. Diese Seite vermittelt das Gesamtbild: was Argos ist,
die wenigen Konzepte, die du im Kopf haben musst, und wie ein Arbeitspaket von
einem Satz, den du tippst, bis zu einem Pull Request fließt, den du prüfen
kannst. Lies sie einmal und folge dann den Links in die Detail-Dokumente, wenn
du sie brauchst.

## Was ist Argos

Argos ist **Claude Code im Browser, für ein Team**. Du öffnest einen
**Workspace** auf einem Repository — ein Branch, ein Container — und arbeitest
mit Claude wie auf deinem eigenen Rechner: Du chattest mit Claude, während es
arbeitet, gibst seine Änderungen frei, bearbeitest Dateien selbst in VS Code,
klickst das Ergebnis in einer Live-Preview durch und lieferst einen Pull Request
aus. Mehrere Personen können gleichzeitig im selben Workspace arbeiten.

Die Arbeit muss nicht interaktiv sein: Ein **automatischer Lauf** (Auto-Thread)
plant, setzt um, prüft die Quality Gates des Projekts, behebt, was fehlschlägt,
und öffnet den Pull Request selbstständig — gestartet aus der Oberfläche, über
die REST-API, MCP oder ein Issue in deinem Tracker. Du behältst die Kontrolle:
Du kannst zusehen, den Plan freigeben oder jederzeit übernehmen, und du prüfst
den Pull Request, bevor er gemergt wird.

Zwei Dinge unterscheiden Argos davon, eine API in einer Schleife aufzurufen:

- **Es läuft auf deinem Claude-Abonnement, nicht auf der API.** Argos nutzt den
  Claude-Code-OAuth-Token aus `claude setup-token`, sodass dein bestehender
  Pro- / Max- / Team-Plan die Arbeit abdeckt — es gibt keine
  Pro-Token-API-Abrechnung.
- **Jeder Workspace läuft in seinem eigenen Container.** Der Agent berührt
  niemals deinen Manager-Host oder andere Workspaces; er bekommt das
  Repository, eine eigene Datenbank und Preview, sonst nichts.

> Argos ist derzeit auf PHP-/Laravel-Projekte abgestimmt — die Quality Gates
> binden Composer, Pint, Pest/PHPUnit und PHPStan ein. Andere Stacks
> funktionieren, dort werden die Gates aber übersprungen.

## Das mentale Modell: Manager und Workspaces

Argos besteht aus zwei Hälften, und es hilft, sie gedanklich zu trennen.

### Der Manager — die App, die du nutzt

Der Manager ist die Webanwendung, in die du dich einloggst: Workspaces liegen
unter `${APP_URL}/w`, die Verwaltung (Projekte, Agenten, Benutzer,
Einstellungen) unter `${APP_URL}/admin`. Hier verbindest du deine Konten,
legst Projekte an, öffnest Workspaces, verfolgst Claude live und prüfst die
Ergebnisse. Der Manager hält alle deine Daten — Threads, Entscheidungen,
Einstellungen — und entscheidet, *was* passieren darf.

Wichtig: **Der Manager selbst führt niemals die KI aus.** Er hat keine eigene
Claude-Session.

### Der Workspace-Container — wo die Arbeit passiert

Jeder Workspace ist ein **Docker-Container**, den der Manager für ihn startet.
Er enthält den Checkout des Repositorys auf einem eigenen Branch, daneben die
Backing Services des Projekts (MySQL / Redis), OpenVSCode Server für den Editor
und einen kleinen **Daemon**, der die Claude-Sessions ausführt und ihre
Ereignisse an den Manager weitergibt. Ein Workspace stoppt bei Leerlauf und
startet wieder, wenn du ihn öffnest; seine Dateien und seine Datenbank bleiben
erhalten, bis du ihn löschst.

Der Container ist abgeschottet: Er bekommt das Repository, einen
Repository-Zugang über einen Git-Helper und sonst nichts. Er hat **keinen
Docker-Socket** und keinen Zugriff auf die Datenbank des Managers. Das Image,
in dem er läuft, wird aus einem [Worker-Stack](WORKER-STACKS.md) gebaut — stell
ihn dir als „den Werkzeugkasten, den der Agent bekommt“ vor —, den du pro
Projekt anpassen kannst.

Diese Aufteilung ist das Herzstück von Argos: ein stabiler, zustandsbehafteter
Manager, mit dem du interagierst, und isolierte Container, die die riskante,
teure Arbeit erledigen.

## Der Kernablauf auf einen Blick

1. **Zugangsdaten verbinden.** Lege deinen Claude-Agenten an (den Token aus
   `claude setup-token`) und verknüpfe ein Git-Konto oder einen Personal Access
   Token. Siehe [Credentials](CREDENTIALS.md) und [OAuth-Apps](OAUTH.md).
2. **Ein Projekt anlegen.** Ein Projekt richtet Argos auf ein Repository aus und
   trägt dessen Standardwerte — Default-Branch, Worker-Stack, Backing Services,
   Automatisierung. Siehe [Projekte](PROJECTS.md).
3. **Einen Workspace öffnen.** Wähle das Projekt und einen Namen; Argos baut das
   Image, klont das Repository auf einen `ws/…`-Branch und startet den
   Container. Siehe [Workspaces](WORKSPACES.md).
4. **In Threads arbeiten.** Beschreibe, was du möchtest; Claude liest, bearbeitet
   und führt Befehle aus und fragt dabei so nach Freigaben, wie es der Modus des
   Threads verlangt. Prüfe die [Quality Gates](WORKSPACES.md#quality-gates), sieh
   dir das [Review](WORKSPACES.md#the-review) und die [Preview](LIVE-DEMOS.md)
   an.
5. **Ausliefern.** **Commit & PR** pusht den Branch und öffnet den Pull Request.

Oder lass es selbst laufen: Ein [Auto-Thread](WORKSPACES.md#auto-threads)
erledigt die Schritte 4 und 5 allein —

```
Plan  →  Umsetzen  →  Quality Gates ⇄ Beheben  →  Pull Request
```

— und ein [Issue-Trigger](SETUP-TASK-PROVIDERS.md) startet einen solchen Lauf
für jedes neue passende Issue in GitHub, GitLab oder Linear.

## Glossar

Eine einzeilige Definition jedes Kernkonzepts. Folge dem Link für die Details.

- **[Projekt](PROJECTS.md)** — ein Repository plus seine Standardwerte
  (Default-Branch, Worker-Stack, Backing Services, Automatisierung,
  Authentifizierung). Jeder Workspace gehört zu einem Projekt.
- **[Workspace](WORKSPACES.md)** — ein interaktiver Ort, um an einem Branch zu
  arbeiten: ein Container mit dem Checkout, eigener Datenbank und Preview,
  Threads und Mitgliedern.
- **[Thread](WORKSPACES.md#threads)** — eine Unterhaltung mit Claude in einem
  Workspace, auf einem Agenten. Die Threads eines Workspaces teilen sich seine
  Dateien.
- **[Auto-Thread](WORKSPACES.md#auto-threads)** — ein Thread, der selbstständig
  läuft: planen, umsetzen, fehlschlagende Gates beheben, ausliefern.
- **[Worker-Stack](WORKER-STACKS.md)** — die Docker-Image-Definition, in der ein
  Workspace läuft: das Basis-Image plus die Werkzeuge, die der Agent bekommt.
  Wird bei Bedarf gebaut und ist pro Projekt anpassbar (lege eine
  `.argos/worker.dockerfile` ab für volle Kontrolle).
- **[Agent](AGENTS.md)** — ein Claude-Code-Zugang, auf dem ein Thread läuft:
  dein persönliches Abo oder ein geteiltes.
- **[Credential](CREDENTIALS.md)** — ein gespeichertes Secret, das Argos
  braucht: dein Claude-Token, um den Agenten auszuführen, und ein Git-Token, um
  das Repository zu lesen und zu schreiben — entweder ein **Personal Access
  Token (PAT)** oder eine vollständige **OAuth**-Kontobindung (die außerdem die
  Repo-/Branch-Auswahl freischaltet). Siehe [OAuth-Apps](OAUTH.md).
- **[Issue-Trigger](SETUP-TASK-PROVIDERS.md)** — eine Verbindung zu einem
  Issue-Tracker (GitHub, GitLab, Linear), die für jedes neue passende Issue
  einen automatischen Lauf startet und im Issue Rückmeldung gibt.
- **[Live-Demo / Preview](LIVE-DEMOS.md)** — ein Wegwerf-Deployment der
  aktuellen Dateien eines Workspaces, damit du die Änderung vor dem Mergen im
  Browser durchklicken kannst.
- **[MCP / REST-API](SETUP-MCP.md)** — steuere Argos von außerhalb der
  Weboberfläche: aus Claude Code über den eingebauten MCP-Server oder
  programmatisch über die [REST-API v1](REST-API.md) mit Sanctum-Bearer-Tokens.

## Wohin als Nächstes

- **Neue Installation?** Beginne mit dem [Setup](SETUP.md), um Konten zu
  verbinden und den Stack zu konfigurieren.
- **Möchtest du Repo-/Branch-Auswahl und eine Bindung pro Benutzer?** Verbinde
  eine [OAuth-App](OAUTH.md).
- **Machst du ein Repository für Argos bereit?** Siehe
  [Ein Projekt vorbereiten](PREPARE-PROJECT.md) — behandelt die Build-Umgebung
  und den Live-Demo-Vertrag.
- **Bereit, etwas laufen zu lassen?** Geh zu den [Projekten](PROJECTS.md), dann
  zu den [Workspaces](WORKSPACES.md).
- **Automatisierung?** Richte [Issue-Trigger](SETUP-TASK-PROVIDERS.md), den
  [MCP-Server](SETUP-MCP.md) oder die [REST-API](REST-API.md) ein.
