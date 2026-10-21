# Pull Requests

Jede [Aufgabe](TASKS.md) endet in ihrem eigenen Pull Request (GitLab: Merge
Request) gegen ihren Basis-Branch. Sobald **Commit & PR** ihn geöffnet hat,
folgt ihm die Seitenleiste der Aufgabe: Zustand, Checks, Reviews und Konflikte
— mit Aktionen, die die Arbeit an Claude zurückgeben. Der Merge des Pull
Requests schließt die Aufgabe ab.

## Inhalt

- [Wo er erscheint](#wo-er-erscheint)
- [Zustand](#zustand)
- [Checks und Pipelines](#checks-und-pipelines)
- [Reviews und Review-Kommentare](#reviews-und-review-kommentare)
- [Konflikte und ein weitergelaufener Basis-Branch](#konflikte-und-ein-weitergelaufener-basis-branch)
- [Auto-Merge](#auto-merge)
- [Fertig und abgebrochen](#fertig-und-abgebrochen)
- [Synchronisierung](#synchronisierung)
- [Unterstützung je Provider](#unterstutzung-je-provider)

## Wo er erscheint

- Der Abschnitt **Pull Request** in der Seitenleiste der Aufgabe: Nummer und
  Zustand, Checks, Reviews, Konfliktwarnungen und die Aktionen unten;
  **Aktualisieren** liest ihn sofort neu ein.
- Die Kopfzeile der Aufgabe, der
  [Aufgaben-Wechsler](TASKS.md#der-aufgaben-wechsler) und
  [Meine Arbeit](WORKSPACES.md#der-bereich-w) zeigen den Pull Request und seinen
  Zustand.
- Änderungen werden im Chat angekündigt (*Die Pipeline ist fehlgeschlagen*,
  *Im Review wurden Änderungen gewünscht*, *Der Pull Request wurde gemergt — die
  Aufgabe ist fertig*, …) und als Benachrichtigungen der Art *Jemand hat eine
  Aufgabe ausgeliefert* an die Person, der die Aufgabe gehört, und die
  Mitarbeitenden.

Die Aktionen brauchen das Recht, in der Aufgabe auszuliefern (Besitz,
Mitarbeitende, Workspace-Admins). Etwas an Claude zu geben, schreibt eine
Nachricht unter deinem Namen in die Aufgabe; sie läuft wie jede Nachricht über
das Abo der Person, der die Aufgabe gehört.

## Zustand

Der Zustand ist *Entwurf*, *offen*, *gemergt* oder *geschlossen*. Ein Entwurf
bietet **Als bereit markieren** an, das ihn beim Provider aus dem
Entwurfszustand holt (GitLab entfernt das Präfix `Draft:` aus dem Titel).

## Checks und Pipelines

Die Zeile **Checks** zeigt den Gesamtzustand der Checks oder der Pipeline des
Pull Requests — *laufen*, *grün*, *fehlgeschlagen* oder *keine* — mit einem
Link zum Lauf.

Sind die Checks fehlgeschlagen, sammelt **Claude fixen lassen** die
Fehlerausgabe beim Provider ein und schreibt sie mit der Bitte um einen Fix in
die Aufgabe:

| Provider | Was Claude bekommt |
|---|---|
| GitHub | Fehlgeschlagene Check-Runs (bis zu zehn) mit ihrer Ausgabe und ihren Annotationen, das Log-Ende fehlgeschlagener GitHub-Actions-Jobs und fehlgeschlagene Commit-Status |
| GitLab | Die Job-Traces der fehlgeschlagenen Jobs der Head-Pipeline (bis zu fünf) |
| Bitbucket | Name, Beschreibung und Link jedes fehlgeschlagenen oder gestoppten Build-Status — Bitbucket bietet über diese API keine Logs |

## Reviews und Review-Kommentare

Die Zeile **Reviews** zeigt den Gesamtstand der Reviews: *freigegeben*,
*Änderungen gewünscht*, *kommentiert* oder *Noch keine Reviews.* Gibt es
Reviews oder Review-Kommentare, schreibt **Review an Claude geben** sie in die
Aufgabe — jedes Review mit Autor oder Autorin, Zustand und Text, jeden
Zeilen-Kommentar mit Autor oder Autorin, Datei und Zeile.

## Konflikte und ein weitergelaufener Basis-Branch

- *Konflikte mit `<base>` — der Branch braucht ein Rebase.*, wenn der Provider
  Merge-Konflikte meldet.
- *`<base>` ist seit dem Start dieses Branches weitergelaufen.*, wenn der
  Basis-Branch neue Commits hat (GitHub und GitLab; Bitbucket meldet das
  nicht).

Beide bieten **Rebase durch Claude** an: Claude führt `git fetch` aus, rebast
den Branch auf `origin/<base>`, löst die Konflikte und sagt Bescheid, wenn es
fertig ist. Liefere danach erneut aus, um den rebasten Branch zu pushen.

## Auto-Merge

**Automatisch mergen** lässt den Provider den Pull Request von selbst mergen,
sobald die Pipeline grün ist und die Reviews es erlauben. Es nutzt das
**native** Auto-Merge des Providers; Argos mergt nicht selbst.

- Einschalten kannst du es in der Seitenleiste, im Formular **Neue Aufgabe**,
  über die REST-API (`auto_merge` beim Start einer Aufgabe) oder MCP
  (`task_create` mit `auto_merge`). Wird es gewählt, bevor der Pull Request
  existiert, wird es angewendet, sobald das Ausliefern ihn öffnet.
- Lehnt der Provider ab, springt der Schalter zurück auf aus, und die Aufgabe
  meldet *Auto-Merge ließ sich für dieses Repository nicht einschalten*.

| Provider | Verhalten |
|---|---|
| GitHub | Nutzt das Auto-Merge von GitHub. Das Repository muss es erlauben (*Settings → General → Allow auto-merge*); GitHub lehnt auch ab, wenn der Pull Request sofort gemergt werden könnte. Nutzt die im Repository erlaubte Merge-Methode. |
| GitLab | Nutzt *Merge when pipeline succeeds*. Argos schaltet es nur scharf, während eine Pipeline des Merge Requests läuft — ohne laufende Pipeline wird es abgelehnt (damit der Merge Request nicht auf der Stelle gemergt wird). |
| Bitbucket | Nicht unterstützt — Einschalten schlägt immer mit dem Hinweis fehl. Merge in Bitbucket. |

## Fertig und abgebrochen

- **Gemergt** → die Aufgabe ist **Fertig**: Sie nimmt keine Nachrichten mehr
  an; ein verknüpftes Ticket wird geschlossen, wo die Integration das vorsieht
  (siehe [Tickets](TICKETS.md#schliessen-beim-merge)). Eine gemergte
  [Setup-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe) macht den Default-Branch
  zur Quelle des Workspace-Setups.
- **Ohne Merge geschlossen** → die Aufgabe ist **Abgebrochen**.

Worktree, Datenbank und Preview abgeschlossener Aufgaben werden nach der
Aufräumfrist des Workspaces entfernt; Branch, Pull Request und Unterhaltung
bleiben (siehe [Aufgaben → Aufräumen](TASKS.md#aufraumen)). Um weiterzumachen,
starte eine [Folge-Aufgabe](TASKS.md#eine-aufgabe-abschliessen).

## Synchronisierung

Argos liest die Pull Requests offener Aufgaben alle
`ARGOS_WORKSPACE_PR_SYNC_MINUTES` neu ein (Standard **3**,
`argos:tasks:sync-prs`, ausgeführt vom Scheduler), außerdem, wenn du den
Abschnitt Pull Request öffnest, und nach jeder seiner Aktionen. Review-Details
werden eine Minute lang gecacht. Bei einem Fehler des Providers bleibt der
zuletzt bekannte Zustand stehen.

## Unterstützung je Provider

| | GitHub | GitLab | Bitbucket |
|---|---|---|---|
| Zustand, Entwurf, als bereit markieren | ja | ja | ja (als bereit markieren nur, wenn Bitbucket es über die API annimmt) |
| Checks mit Link | Check-Runs + Commit-Status | Head-Pipeline | Build-Status |
| Log fehlgeschlagener Checks für Claude | ja | ja | nur Status-Beschreibungen |
| Reviews und Review-Kommentare | ja | Approvals + Diskussionen | Approvals + Kommentare |
| Konflikte | ja | ja | ja |
| Basis-Branch weitergelaufen | ja | ja | nein (Bitbucket meldet das nicht) |
| Auto-Merge | ja (wenn im Repository erlaubt) | nur während eine Pipeline läuft | nein |
