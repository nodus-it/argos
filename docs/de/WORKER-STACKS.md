# Stacks und Images

Jeder Workspace läuft in einem Docker-Container. Sein Image wird bei Bedarf
zusammengesetzt aus einem **Stack** — dem Betriebssystem plus der Toolchain,
die das Projekt braucht —, der Claude-Code-CLI, den Gate-Helfern von Argos und
der Workspace-Schicht (Daemon und VS Code). Ein Workspace nutzt entweder einen
der **drei eingebauten Stacks** oder die eigene
**`.argos/worker.dockerfile`** des Repositorys. Einen Stack-Editor gibt es
nicht: Alles, was die eingebauten Stacks nicht abdecken, kommt in das
Dockerfile des Repositorys, das die
[Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe) für dich schreiben
kann.

## Inhalt

- [Die eingebauten Stacks](#die-eingebauten-stacks)
- [Das eigene Image des Repositorys](#das-eigene-image-des-repositorys)
- [Wie ein Workspace zu seinem Stack kommt](#wie-ein-workspace-zu-seinem-stack-kommt)
- [Die Schichten eines Workspace-Images](#die-schichten-eines-workspace-images)
- [Wie Images gebaut werden](#wie-images-gebaut-werden)
- [Was einen Neubau auslöst](#was-einen-neubau-auslost)
- [Wo du das Image siehst](#wo-du-das-image-siehst)

## Die eingebauten Stacks

| Stack | Slug | Basis-Image | Toolchain |
|---|---|---|---|
| **PHP 8.5** (Standard) | `php-8.5` | `php:8.5-cli-bookworm` | PHP 8.5 CLI, Composer, Node 22 |
| **PHP 8.4** | `php-8.4` | `php:8.4-cli-bookworm` | PHP 8.4 CLI, Composer, Node 22 |
| **Node 22** | `node-22` | `node:22-bookworm` | Node 22 mit Corepack (npm, yarn, pnpm) |

Die PHP-Stacks bringen die üblichen Extensions mit (`intl`, `pdo_mysql`,
`pdo_sqlite`, `pdo_pgsql`, `zip`, `bcmath`, `gd`, `pcntl`, `sockets`, `exif`,
`redis`) und haben kein CLI-Speicherlimit. Alle Stacks enthalten `git`, `gh`,
`jq`, `curl`, `unzip`, einen MySQL-Client und den Nicht-Root-Benutzer `agent`
(UID 1000).

Die Stacks werden mit Argos ausgeliefert (`.tools/docker/worker/built-ins.php`)
und nach jeder Migration in die Datenbank synchronisiert; ein Stack, der aus
dem Manifest entfernt wird, wird als veraltet markiert statt gelöscht. Der
Standard-Stack wird beim Booten der App vorgebaut
(`argos:warm-builtin-images`).

## Das eigene Image des Repositorys

Passt kein eingebauter Stack — eine andere Sprache, eine PHP-Extension oder ein
Systempaket, ohne das das Projekt nicht arbeiten kann, ein festgelegtes
Basis-Image —, liefert das Repository `.argos/worker.dockerfile` mit. Sie
ersetzt **nur die Stack-Schicht**; Argos legt weiterhin die Claude-Code-CLI,
die Gate-Helfer und die Workspace-Schicht darüber. Das Image muss Node.js (für
die CLI), den Benutzer `agent` mit UID 1000 und die Tools `bash`, `sh`, `jq`,
`git`, `sed`, `grep`, `awk`, `curl` bereitstellen. Der vollständige Vertrag
steht unter [Eigenes Worker-Image (BYOI)](BYOI.md).

## Wie ein Workspace zu seinem Stack kommt

1. **Beim Anlegen** prüft Argos das Repository: `composer.json` → PHP 8.5,
   oder PHP 8.4, wenn die PHP-Constraint 8.4 festlegt, ohne 8.5 zu erlauben;
   `package.json` ohne `composer.json` → Node 22; eine
   `.argos/worker.dockerfile` → das Image des Repositorys. Ist nichts davon
   vorhanden, startet die
   [Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe).
2. **In den Einstellungen** (Einstellungen → Umgebung → Worker) können
   Workspace-Admins die **Image-Quelle** (*Registrierter Stack* oder *Eigenes
   Dockerfile im Repo (BYOI)*) und den **Worker-Stack** umstellen.
3. **Ein bestandener Einrichtungs-Test**, der eine `.argos/worker.dockerfile`
   gebaut hat, stellt die Image-Quelle auf BYOI um.
4. Ist kein Stack gesetzt, gilt der Standard der Installation
   (`ARGOS_DEFAULT_STACK`, standardmäßig `php-8.5`).

Das Dockerfile des Repositorys wird am Setup-Ref des Workspaces gelesen — dem
Default-Branch oder einem getesteten, noch nicht gemergten Einrichtungs-Branch.

## Die Schichten eines Workspace-Images

1. **Stack-Schicht** — das Dockerfile des eingebauten Stacks oder die
   `.argos/worker.dockerfile` des Repositorys. Getaggt als
   `argos-stack:<name>-<hash>`.
2. **Agent-Schicht** — die Claude-Code-CLI (`@anthropic-ai/claude-code`),
   darüber installiert. Siehe [Agents](AGENTS.md).
3. **Gate-Helfer** — die Quality-Gate-Skripte von Argos (`worker/lib`).

Zusammen bilden sie das **Worker-Image**, getaggt als
`argos-worker:<stack>-<stackHash>-<libHash>-<agent>-<version>`. Darüber liegt
die **Workspace-Schicht** — der Daemon und OpenVSCode Server. Nichts davon
baust du von Hand.

## Wie Images gebaut werden

Images werden erst gebaut, wenn ein Workspace startet und sein Image fehlt:

1. Argos ermittelt den Stack und berechnet den deterministischen Tag.
2. Existiert ein Image mit diesem Tag, nutzt der Workspace es.
3. Sonst baut es das Stack-Image (übersprungen, wenn sein Hash im Cache ist),
   das Worker-Image und das Workspace-Image.
4. Ein Smoke-Test prüft, dass `bash`, `sh`, `jq`, `git`, `sed`, `grep`, `awk`,
   `curl` und die `claude`-CLI vorhanden sind. Fehlt etwas, wird der Build als
   **fehlgeschlagen** markiert und das Image enttaggt, sodass es nie
   wiederverwendet wird.

Alle Aufgaben eines Workspaces teilen sich seinen Container und damit sein
Image. Der erste Start eines neuen Images dauert einige Minuten; spätere
Starts nutzen es wieder.

## Was einen Neubau auslöst

Der Tag ist ein Fingerabdruck von allem im Image. Beim nächsten Start des
Workspaces wird ein neues Image gebaut, wenn

- sich der **Stack** ändert — ein neues Dockerfile eines eingebauten Stacks,
  das mit Argos ausgeliefert wird, oder eine geänderte
  `.argos/worker.dockerfile` am Setup-Ref,
- sich die **Gate-Helfer** ändern (eine neue Argos-Version) oder
- sich die festgelegte **Agent-Version** ändert.

**Neu bauen** unter Einstellungen → Umgebung → Einrichtung baut das aktuelle
Stack-Image auf Wunsch erneut (z. B. um neuere Upstream-Pakete zu übernehmen);
der Workspace nutzt es ab seinem nächsten Start.

## Wo du das Image siehst

Einstellungen → Umgebung → **Einrichtung** zeigt den aktuellen Image-Build des
Workspaces (Zustand und Alter), sein **Build-Log** (Stack-Build,
Worker-Schicht und Smoke-Test) und **Neu bauen**. Ein fehlgeschlagenes
**Einrichtung testen** zeigt das Build-Log des Repository-Images in der
Einrichtungs-Aufgabe. Eine globale Liste der Image-Builds gibt es nicht;
**Administration → Übersicht** (`/system/overview`) zählt nur veraltete
Worker-Images.
