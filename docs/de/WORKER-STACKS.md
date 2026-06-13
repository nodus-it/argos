# Worker-Stacks und Images

Wenn Argos eine Aufgabe ausführt, geschieht die eigentliche Arbeit — das Klonen
deines Repos, das Ausführen des Agents, das Erzeugen von Branches — innerhalb
eines kurzlebigen Docker-Containers. Die Umgebung, in der dieser Container
läuft, wird durch einen **Worker-Stack** beschrieben, und das lauffähige Image
wird bei Bedarf aus dem Stack, einem Agent und dem Argos-Worker-Code
zusammengesetzt.

Dieses Dokument erklärt in einfachen Worten, was ein Stack ist, wie Images
gebaut werden und wie ein Projekt die Umgebung auswählt, in der seine Aufgaben
laufen. Es richtet sich an Betreiber, die diese Umgebung auswählen oder
verwalten — du musst den Worker-Quellcode nicht lesen, um es zu verstehen.

## Inhalt

- [Was ein Worker-Stack ist](#what-a-worker-stack-is)
- [Die drei Schichten eines Worker-Images](#the-three-layers-of-a-worker-image)
- [Eingebaute, benutzerdefinierte und repo-definierte Stacks](#built-in-custom-and-repo-defined-stacks)
- [Wie Images bei Bedarf gebaut werden](#how-images-build-on-demand)
- [Was einen Neubau auslöst](#what-triggers-a-rebuild)
- [Wo Image-Builds einsehbar sind](#where-to-see-image-builds)
- [Verfolgung von Agent-Versionen](#agent-version-tracking)
- [Wie ein Projekt seinen Stack auswählt](#how-a-project-selects-its-stack)
- [Referenz: Stack-Felder](#reference-stack-fields)

## Was ein Worker-Stack ist

Ein Worker-Stack ist die **Basis-Toolchain**, in der der Worker läuft — das
Betriebssystem plus die Sprachen und Werkzeuge, die dein Projekt zum Bauen und
Testen benötigt (zum Beispiel PHP, Composer und Node). Ein Stack ist im Kern
ein Dockerfile zusammen mit einigen Metadaten, die ihn beschreiben.

Jeder Stack umfasst:

- Einen **Slug** (`name`) — ein eindeutiger Bezeichner, z. B. `php-8.4`.
- Einen **Anzeigenamen** (`label`) — das, was du in den Stack-Auswahlfeldern bei
  Aufgaben und Projekten siehst, z. B. `PHP 8.4`.
- Ein **Base-Image** — ein rein referenzieller Hinweis auf das vorgelagerte
  `FROM`-Image (z. B. `php:8.4-cli-bookworm`). Das eigentliche `FROM` steht im
  Dockerfile selbst; dieses Feld dokumentiert es nur für die Übersicht.
- Einen **Dockerfile-Body** — das vollständige Dockerfile, das zur Build-Zeit
  zum Stack-Image wird. Dies ist die maßgebliche Quelle dafür, was die Umgebung
  tatsächlich enthält.
- **Capabilities** — Tags wie `php`, `composer`, `node`. Diese sind nicht
  kosmetisch: Ein Agent prüft sie, bevor er auf einem Stack laufen darf (Claude
  Code etwa benötigt `node`). Eine Aufgabe auf einem Stack, dem eine vom Agent
  geforderte Capability fehlt, wird abgewiesen, bevor ein Build überhaupt läuft.
- **Common Tools** — rein dokumentarische Tags für die zusätzlich installierten
  Werkzeuge (`git`, `gh`, `jq`, `curl`, …). Nur informativ; nicht für die
  Validierung verwendet.
- Einen **Status** — `active`, `deprecated` oder `disabled`. Ein deaktivierter
  Stack wird vom Resolver übersprungen, selbst wenn eine Aufgabe oder ein
  Projekt ihn noch referenziert.

Stacks verwaltest du im Admin-Panel unter der Navigationsgruppe **Worker**,
unter `${APP_URL}/admin/worker-stacks`.

## Die drei Schichten eines Worker-Images

Der Stack ist nur die unterste Schicht. Das Image, das Argos tatsächlich
ausführt, wird in drei aufeinandergestapelten Schichten gebaut, die jeweils
unabhängig gecacht werden:

1. **Stack-Schicht** — die Basis-Toolchain, gebaut aus dem Dockerfile des Stacks
   (`FROM php:8.4-…`, plus deine Werkzeuge). Getaggt als
   `argos-stack:<name>-<hash>`.
2. **Agent-Schicht** — die CLI des Coding-Agents (Claude Code oder Codex),
   installiert auf dem Stack. Eine Änderung der Agent-Version invalidiert nur
   diese Schicht. Siehe [Agents](AGENTS.md) dazu, was ein Agent ist und wie
   Credentials funktionieren.
3. **Worker-Code-Schicht** — die Argos-Phasenskripte, -Bibliotheken, -Prompts
   und -Schemas sowie der Worker-Entrypoint. Diese ändert sich nur, wenn du eine
   neue Argos-Version ausrollst, daher wird sie aggressiv gecacht.

Das finale Image wird getaggt als
`argos-worker:<stack>-<stackHash>-<libHash>-<agent>-<version>`. Diese baust du
nie von Hand — der Manager setzt sie zusammen, wenn eine Aufgabe sie benötigt
(siehe unten).

## Eingebaute, benutzerdefinierte und repo-definierte Stacks

Es gibt drei Wege, auf denen ein Stack entstehen kann.

### Eingebaute Stacks (automatisch synchronisiert)

Argos bringt von Haus aus eingebaute Stacks mit — derzeit **PHP 8.3** und
**PHP 8.4**. Diese sind im Argos-Quell-Manifest definiert und werden **nach
jeder Datenbankmigration automatisch in die Datenbank synchronisiert**. Die
Synchronisierung ist idempotent: Ein Stack, dessen Definition sich nicht
geändert hat, bleibt unangetastet; eine geänderte Definition aktualisiert die
eingebauten Felder; ein eingebauter Stack, der aus dem Manifest entfernt wird,
wird auf `deprecated` umgestellt (nie gelöscht, damit Projekte, die ihn noch
referenzieren, weiter funktionieren).

Eingebaute Stacks sind in der UI **schreibgeschützt** — du kannst sie weder
bearbeiten noch löschen. Um einen anzupassen, verwende die Aktion
**Duplicate**: Sie klont den Stack in eine frische, bearbeitbare Kopie (zwingend
nicht-eingebaut, mit automatisch ergänztem Namens-Suffix), die die automatische
Synchronisierung niemals überschreibt. Dies ist der unterstützte Weg für „Ich
will PHP 8.4, aber mit einem zusätzlichen apt-Paket".

### Benutzerdefinierte Stacks

Ein benutzerdefinierter Stack ist jeder Stack, den du selbst erstellst oder
duplizierst (`is_builtin = false`). Sein Dockerfile gehört vollständig dir, du
kannst ihn bearbeiten und löschen, und die eingebaute Synchronisierung rührt ihn
nie an. Verwende einen benutzerdefinierten Stack, wenn deine Projekte eine
Toolchain benötigen, die die eingebauten nicht abdecken (eine andere Laufzeit,
zusätzliche Systempakete, ein hauseigenes Base-Image, …).

### Repo-definiertes Image (BYOI)

Ein Projekt kann auch seine eigene Image-Definition mitbringen, indem es ein
Dockerfile im Repository selbst ausliefert — **Bring Your Own Image (BYOI)**. In
diesem Modus liest Argos das Dockerfile des Repos und verwendet es als
Stack-Basis, legt dann aber Agent und Worker-Code genauso obendrauf wie bei
einem normalen Stack. So bleibt die Umgebungsdefinition zusammen mit dem Code
versioniert, den sie baut. Siehe [BYOI](BYOI.md) für den Ablageort der Datei und
den vollständigen Vertrag.

## Wie Images bei Bedarf gebaut werden

Worker-Images werden **lazy gebaut, bei der ersten Aufgabe, die sie benötigt** —
im normalen Ablauf gibt es keinen manuellen Vorbau-Schritt. Wenn eine Aufgabe
startet, geht der Manager so vor:

1. **Auflösen** des `(stack, agent)`-Paars für die Aufgabe (siehe
   [Wie ein Projekt seinen Stack auswählt](#how-a-project-selects-its-stack)) und
   Berechnen des deterministischen Image-Tags.
2. **Prüfen**, ob ein Image mit genau diesem Tag bereits existiert. Falls ja,
   verwendet die Aufgabe es sofort.
3. **Bauen**, falls es fehlt: zuerst das Stack-Image (übersprungen, wenn sein
   Content-Hash bereits gecacht ist), dann das Worker-Image (Stack + Agent +
   Worker-Code).
4. **Validieren** des frischen Images mit einem Smoke-Test — jedes
   Basis-Werkzeug (`bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`, `curl`)
   sowie das CLI-Binary des Agents müssen vorhanden sein. Fehlt eines davon,
   wird der Build als **failed** markiert und das defekte Image enttaggt, sodass
   es nicht stillschweigend wiederverwendet werden kann.

Da der Tag inhaltsbasiert abgeleitet wird, wird eine unveränderte Umgebung genau
einmal gebaut und für jede nachfolgende Aufgabe wiederverwendet.

## Was einen Neubau auslöst

Der Worker-Image-Tag ist ein **Fingerabdruck** von allem, was in das Image
einfließt. Ein neues Image wird gebaut, sobald sich eines dieser drei Dinge
ändert:

- **Der Stack** — konkret eine Änderung am Dockerfile-Body des Stacks. Der Tag
  bettet einen 8-stelligen Hash dieses Dockerfiles ein, sodass dessen
  Bearbeitung einen neuen Tag und einen neuen Build erzeugt.
- **Der Worker-Code** — ein Fingerabdruck (`libHash`) über die
  Worker-Bibliotheken, Phasenskripte, Prompts, Schemas und den
  Worker-Entrypoint/das Dockerfile. Das Ausrollen einer neuen Argos-Version mit
  geändertem Worker-Code baut Images neu, selbst wenn Stack und Agent unberührt
  bleiben.
- **Die Agent-Version** — der Agent-Name und seine fixierte Version sind Teil
  des Tags, sodass das Hochziehen des Agents auf eine neue Version einen neuen
  Tag erzeugt.

Ändert sich keines davon, wird das bestehende Image wiederverwendet — kein
Neubau, keine verschwendete Build-Zeit.

## Wo Image-Builds einsehbar sind

Jeder Build-Versuch wird als **Worker Image Build**-Zeile festgehalten, sichtbar
im Admin-Panel unter der Worker-Gruppe unter
`${APP_URL}/admin/worker-image-builds`. Jede Zeile zeigt:

- Den vollständigen Image-**Tag**, den **Stack** und den **Agent**, für die er
  gebaut wurde.
- Einen **Status** — `queued`, `building`, `ready` oder `failed`.
- Die Image-**Größe** und den **Built-at**-Zeitstempel.
- Das vollständige **Build-Log** (Stack-Build + Worker-Schicht +
  Validierungsschritt) auf der Detailseite — die erste Anlaufstelle, wenn ein
  Build fehlschlägt.
- Eine **Update-available**-Markierung, die Builds kennzeichnet, die veraltet
  sind (siehe unten).

Von diesem Bildschirm aus kannst du ein einzelnes Image **neu bauen** (Rebuild)
oder mit **Rebuild all outdated** jedes veraltete Image in einer Aktion
auffrischen. Builds werden nicht von Hand erstellt — die Liste wird von der
On-Demand-Build-Pipeline befüllt.

Ein Build wird als **outdated** markiert, wenn entweder:

- **Stack-Drift** — das Dockerfile des Stacks hat sich seit dem Build geändert
  (der aufgezeichnete Hash des Builds passt nicht mehr zum aktuellen), oder
- **Agent-Drift** — für den Agent ist ein Update verfügbar und der Build liegt
  vor der letzten Versionsprüfung.

## Verfolgung von Agent-Versionen

Agents (die CLI-Werkzeuge Claude Code und Codex) werden unabhängig von Argos
veröffentlicht. Damit du über neue Versionen Bescheid weißt, führt Argos eine
**tägliche Prüfung** durch (um 03:00 Uhr und auf Anforderung über
`php artisan argos:check-agent-versions`), die für das Paket jedes registrierten
Agents die npm-Registry abfragt und die zuletzt veröffentlichte Version mit der
von Argos fixierten Version vergleicht.

Das Ergebnis wird pro Agent gespeichert und erscheint als
**Update-available**-Signal im Panel — in den Stack-/Build-Ansichten und im
Worker-Updates-Dashboard-Widget. Eine Fixierung auf `latest` meldet immer ein
Update, sobald sich Upstream bewegt; eine feste Fixierung meldet nur dann ein
Update, wenn sich die veröffentlichte Version unterscheidet.

Ein Update-Signal ist informativ — nichts wird automatisch neu gebaut. Wenn du
die neue Version übernehmen willst, verwende **Rebuild** /
**Rebuild all outdated** auf dem Image-Builds-Bildschirm; der nächste Build
holt während seines Installationsschritts den aktuellen Agent.

## Wie ein Projekt seinen Stack auswählt

Eine Aufgabe löst in dieser Reihenfolge auf, welchen Stack und Agent sie
verwendet — der erste Treffer gewinnt:

1. **Per-Task-Override** — ein explizit auf der einzelnen Aufgabe gewählter
   Stack/Agent.
2. **Projekt-Einstellung (Repo-Profil)** — der auf dem Projekt konfigurierte
   Stack und Agent.
3. **Konfigurierter Standard** — fällt auf den Standard-Stack zurück
   (`php-8.4`, sofern nicht über `ARGOS_DEFAULT_STACK` überschrieben) und den
   Standard-Agent (Claude Code).

Die projektweite Auswahl legst du in den Einstellungen des Projekts fest, unter
den Optionen für die Worker-Umgebung: eine **Worker source** (Standard-Stack vs.
BYOI), den zu verwendenden **Worker stack** und den **Worker agent**. Wenn die
Quelle BYOI ist, wird das Stack-Auswahlfeld ausgeblendet, weil die Umgebung
stattdessen aus dem eigenen Dockerfile des Repos kommt. Siehe
[Projects](PROJECTS.md) dazu, wo diese liegen, und
[Configuration](CONFIGURATION.md) zum Standardwert `ARGOS_DEFAULT_STACK`.

## Referenz: Stack-Felder

| Feld | Bedeutung |
| --- | --- |
| `name` (Slug) | Eindeutiger Bezeichner, z. B. `php-8.4`. Bei eingebauten Stacks schreibgeschützt. |
| `label` | Menschenlesbarer Anzeigename, der in den Auswahlfeldern erscheint. |
| `base_image` | Rein referenzieller Hinweis auf das vorgelagerte `FROM`-Image. |
| `dockerfile_body` | Das vollständige Dockerfile, das zum Stack-Image wird. |
| `capabilities` | Tags, die ein Agent prüft, bevor er laufen darf (z. B. `php`, `node`). |
| `common_tools` | Rein dokumentarische Tags für installierte Werkzeuge. Nicht validiert. |
| `status` | `active`, `deprecated` oder `disabled` (disabled = übersprungen). |
| `is_builtin` | Ob der Stack von Argos ausgeliefert + synchronisiert wird (schreibgeschützt) oder dir gehört. |
| `has_update` | Ob für den Stack/Agent ein Update verfügbar ist. |
| `last_built_at` | Wann zuletzt ein Image für diesen Stack gebaut wurde. |
