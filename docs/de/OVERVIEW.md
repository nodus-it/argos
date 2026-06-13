# Wie Argos funktioniert

Willkommen bei Argos. Diese Seite vermittelt das Gesamtbild: was Argos ist,
die wenigen Konzepte, die du im Kopf haben musst, und wie ein Arbeitspaket von
einem Satz, den du tippst, bis zu einem Pull Request fließt, den du prüfen
kannst. Lies sie einmal und folge dann den Links in die Detail-Dokumente, wenn
du sie brauchst.

## Was ist Argos

Argos verwandelt eine **Aufgabenbeschreibung in einen geprüften Pull Request**.
Du beschreibst, was du möchtest — ein Feature, einen Fix, ein Refactoring — und
Argos entwirft ein Konzept, setzt es in einem isolierten Worker-Container um,
führt die Quality Gates des Projekts aus, pusht einen Branch und öffnet den Pull
Request. Du behältst die Kontrolle: Du prüfst das Konzept, bevor es gebaut wird,
und den Pull Request, bevor er gemergt wird.

Zwei Dinge unterscheiden Argos davon, eine API in einer Schleife aufzurufen:

- **Es läuft auf deinem Claude-Abonnement, nicht auf der API.** Argos nutzt den
  Claude-Code-OAuth-Token aus `claude setup-token`, sodass dein bestehender
  Pro- / Max- / Team-Plan die Arbeit abdeckt — es gibt keine
  Pro-Token-API-Abrechnung.
- **Jede Aufgabe läuft in ihrem eigenen Wegwerf-Container.** Der Agent berührt
  niemals deinen Manager-Host oder deine anderen Aufgaben. Wenn die Arbeit
  erledigt ist, ist der Container verschwunden.

> Argos ist derzeit auf PHP-/Laravel-Projekte abgestimmt — die Implement-Phase
> bindet Composer, npm, Pint und Pest/PHPUnit als Quality Gates ein. Andere
> Stacks funktionieren, aber die Prompts und Gates sind heute am schärfsten für
> Laravel.

## Das mentale Modell: Manager und Worker

Argos besteht aus zwei Hälften, und es hilft, sie im Kopf getrennt zu halten.

### Der Manager — die App, die du nutzt

Der Manager ist die Webanwendung, in die du dich unter `${APP_URL}/admin`
einloggst. Er ist die Schaltzentrale: Hier verbindest du deine Accounts,
definierst Projekte, erstellst Aufgaben, beobachtest ihren Lauf, liest den
Live-Agent-Stream und prüfst die Ergebnisse. Der Manager hält alle deine Daten
und entscheidet, *was* passieren soll.

Wichtig: **Der Manager selbst führt die KI niemals aus.** Er hat keine eigene
Claude-Session. Er orchestriert — er entscheidet, wann eine Phase laufen soll,
und übergibt den Job an einen Worker.

### Der Worker — der Container, der die Arbeit erledigt

Ein Worker ist ein **kurzlebiger Docker-Container**, den der Manager für eine
einzelne Phase einer einzelnen Aufgabe hochfährt. Er klont das Repository, führt
den Claude-Agenten aus, lässt die Quality Gates laufen und meldet zurück.
Danach wird er abgebaut.

Der Worker ist bewusst gekapselt: Er erhält das Repository, die Credentials, die
er als Umgebungsvariablen benötigt, und sonst nichts. Er hat **keinen
Docker-Socket** und keinen Weg zurück in den Manager. Das Image, in dem er
läuft, wird durch einen [Worker Stack](WORKER-STACKS.md) definiert — denke an
„den Werkzeugkasten, den der Agent bekommt" — den du pro Projekt anpassen
kannst.

Diese Trennung ist das Herzstück von Argos: ein stabiler, zustandsbehafteter
Manager, mit dem du interagierst, und einweg-Worker, die die riskante, teure
Arbeit isoliert erledigen.

## Der Kernablauf auf einen Blick

Ein typisches Arbeitspaket durchläuft diese Schritte. Das meiste davon ist
automatisch — deine Aufgabe sind die zwei Review-Gates.

1. **Credentials verbinden.** Füge deinen Claude-Token ein und verknüpfe einen
   Git-Account (oder einen Personal Access Token). Siehe
   [Credentials](CREDENTIALS.md) und [OAuth Apps](OAUTH.md).
2. **Ein Projekt anlegen.** Ein Projekt richtet Argos auf ein Repository aus und
   trägt seine Defaults — von welchem Branch zu starten ist, welcher Worker
   Stack, welche Modelle. Siehe [Projekte](PROJECTS.md).
3. **Eine Aufgabe anlegen.** Beschreibe, was du möchtest. Die **Concept**-Phase
   startet (entwirft einen Plan und erstellt den Feature-Branch), und du
   erhältst ein schriftliches Konzept zur Prüfung.
4. **Argos lässt die Phasen laufen.** Nachdem du das Konzept freigegeben hast,
   schreibt die **Implement**-Phase den Code und führt die Quality Gates aus,
   dann öffnet die **Push**-Phase den Pull Request:

   ```
   Concept  →  Implement  →  Push (Pull Request)
   ```

5. **Du prüfst.** Lies den Diff und den Pull Request. Wenn etwas geändert werden
   muss, sende Feedback, und die **Respond**-Phase überarbeitet den Branch — in
   so vielen Runden, wie du möchtest.

Phasen laufen asynchron: Wenn du eine anstößt, reiht der Manager den Job ein und
die Seite aktualisiert sich, während der Worker Fortschritte macht. Für die
vollständige Durchführung — jeder Status, die Review-Docks, Retries und
Feedback-Runden — siehe [Tasks](TASKS.md).

## Glossar

Eine einzeilige Definition jedes Kernkonzepts. Folge dem Link für die Details.

- **[Projekt](PROJECTS.md)** — ein Repository plus seine Defaults (Base-Branch,
  Worker Stack, Modelle, Auth). Jede Aufgabe gehört zu einem Projekt.
- **[Task](TASKS.md)** — eine Arbeitseinheit, von der Beschreibung bis zum Pull
  Request, die durch die Phasen läuft.
- **[Phase](TASKS.md)** — ein einzelner Schritt im Leben einer Aufgabe: Concept
  (Plan), Implement (Code schreiben), Push (PR öffnen) und Respond
  (Überarbeitung auf Feedback). Jede Phase ist ein isolierter Worker-Lauf.
- **[Worker Stack](WORKER-STACKS.md)** — die Docker-Image-Definition, in der ein
  Worker läuft: das Base-Image plus die Tools, die der Agent bekommt. Bei Bedarf
  gebaut, pro Projekt anpassbar (lege ein `.argos/worker.dockerfile` ab für
  volle Kontrolle).
- **[Agent](AGENTS.md)** — das KI-Coding-Tool, das der Worker im Container
  steuert (Claude Code ist der Standard).
- **[Credential](CREDENTIALS.md)** — ein gespeichertes Secret, das Argos
  benötigt: deinen Claude-Token, um den Agenten auszuführen, und einen
  Git-Token, um das Repository zu lesen und zu schreiben — entweder ein
  **Personal Access Token (PAT)** oder eine vollständige **OAuth**-Account-
  Bindung (die auch Repo-/Branch-Auswahllisten freischaltet). Siehe
  [OAuth Apps](OAUTH.md).
- **[Task Provider](SETUP-TASK-PROVIDERS.md)** — eine Verbindung zu einem
  Issue-Tracker (GitHub, GitLab, Linear), sodass du Issues direkt als Aufgaben
  in Argos importieren kannst.
- **[Live Demo](LIVE-DEMOS.md)** — ein kurzlebiges, wegwerfbares Deployment des
  implementierten Branches einer Aufgabe, sodass du die Änderung im Browser
  durchklicken kannst, bevor du mergst.
- **[MCP / REST API](SETUP-MCP.md)** — steuere Argos von außerhalb der Web-UI:
  aus Claude Code über den eingebauten MCP-Server oder programmatisch über die
  [REST API v1](REST-API.md) mit Sanctum-Bearer-Tokens.

## Wohin als Nächstes

- **Neue Installation?** Beginne mit [Setup](SETUP.md), um das Verbinden der
  Accounts und die Konfiguration des Stacks abzuschließen.
- **Repo-/Branch-Auswahllisten und Bindung pro Nutzer gewünscht?** Verbinde eine
  [OAuth App](OAUTH.md).
- **Ein Repository für Argos vorbereiten?** Siehe
  [Ein Projekt vorbereiten](PREPARE-PROJECT.md) — behandelt die
  Worker-Build-Umgebung und den Live-Demo-Vertrag.
- **Bereit, etwas laufen zu lassen?** Geh zu [Projekte](PROJECTS.md), dann
  [Tasks](TASKS.md).
- **Automatisieren?** Binde den [MCP-Server](SETUP-MCP.md) oder die
  [REST API](REST-API.md) ein.
