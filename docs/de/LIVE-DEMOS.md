# Previews

Eine **Preview** (Live-Demo) ist ein laufendes Deployment der aktuellen Dateien
einer [Aufgabe](TASKS.md). Argos startet den Worktree der Aufgabe in einem
eigenen kleinen Container-Stack und veröffentlicht ihn unter einer temporären
Subdomain, sodass du die Änderung im Browser durchklicken kannst, **bevor** der
Pull Request gemergt wird.

## Inhalt

- [Was eine Preview ist](#was-eine-preview-ist)
- [Wann eine Preview verfügbar ist](#wann-eine-preview-verfugbar-ist)
- [Eine Preview starten und öffnen](#eine-preview-starten-und-offnen)
- [Der Preview-Vertrag](#der-preview-vertrag)
- [Zugriff](#zugriff)
- [Lebenszyklus](#lebenszyklus)
- [Konfiguration für Betreibende](#konfiguration-fur-betreibende)

## Was eine Preview ist

Ein Workspace hält seinen Checkout in einem Docker-Volume; jede Aufgabe hat
dort ihren Worktree. Die Preview mountet **nur den Worktree dieser Aufgabe** in
einen kleinen Container-Stack, führt die Boot-Befehle des Vertrags aus und
veröffentlicht eine Route über Traefik unter
`demo-<workspace>-<task>.<base-domain>` (gekürzt auf 63 Zeichen). Weil sie den
Worktree mountet, zeigt die Preview immer die aktuellen Dateien der Aufgabe —
PHP-Änderungen erscheinen ohne Neubau.

Eine Preview ist **kurzlebig** und gehört **zu einer Aufgabe**:

- Höchstens eine Preview pro Aufgabe. **Neu bauen** ersetzt sie sauber (alte
  Container, Volumes und Route werden zuerst abgebaut).
- Sie hat ihre eigene Datenbank in ihrem Compose-Stack — nicht die
  Arbeitsdatenbank der Aufgabe.
- Sie hat eine **Lebensdauer (TTL)** und ist für ein schnelles Review gedacht,
  nicht als Produktiv-Deployment.

## Wann eine Preview verfügbar ist

Eine Aufgabe kann nur dann eine Preview starten, wenn **beides** zutrifft:

1. **Die Live-Demo ist für den Workspace aktiviert** (Einstellungen → Umgebung
   → *Live-Demo aktivieren*). Siehe
   [Workspace-Einstellungen](PROJECTS.md#live-demo).
2. **Previews sind plattformweit aktiviert** (`ARGOS_PREVIEW_ENABLED`,
   standardmäßig an).

Ist eines davon aus, nennt die Seitenleiste den Grund und bietet keine Preview
an.

## Eine Preview starten und öffnen

Die Preview-Steuerung sitzt im Bereich **Umgebung** der Seitenleiste der
Aufgabe (`/preview` zeigt die Seitenleiste):

- **Starten** baut die Preview im Hintergrund; die Zeile zeigt *Preview wird
  gebaut…*, bis sie live ist.
- **Öffnen** öffnet sie in einem eigenen Browser-Tab (einer pro Aufgabe, beim
  nächsten Klick wiederverwendet). Die Zeile zeigt, wann sie abläuft.
- **Neu bauen** ersetzt die laufende Preview durch einen frischen Build.
- **Setup-Befehle erneut ausführen** führt die `commands` des Vertrags in der
  laufenden Preview erneut aus — nach einer Migration, einer Änderung an
  `composer`/`npm` oder an der Build-Konfiguration — ohne Neubau. Die Ausgabe
  wird an das Build-Log angehängt.
- **Preview stoppen** entfernt ihre Container, Volumes und Route.
- Schlägt ein Build fehl, öffnet sich das **Build-Log**.

Die Befehle des Vertrags laufen als Benutzer `agent` des Workspaces, sodass
Dateien, die sie anlegen (z. B. `vendor/`, kompilierte Views), in der Aufgabe
bearbeitbar bleiben.

## Der Preview-Vertrag

Wie eine Preview gebaut wird, legen zwei Dateien im Repository fest:

| Datei | Zweck |
| --- | --- |
| `.argos/demo.compose.yml` | Die Docker-Compose-Services, die die Preview ausführt (die App, ihre Datenbank, weitere Backing-Services). |
| `.argos/demo.yml` | Einstellungen: zu welchem Service/Port geroutet wird, wo der Worktree gemountet wird, die Boot-Befehle und ein Health-Check. |

Sie werden am Setup-Ref des Workspaces gelesen — dem Default-Branch oder einem
getesteten, noch nicht gemergten Einrichtungs-Branch; die
[Einrichtungs-Aufgabe](PREPARE-PROJECT.md#die-einrichtungs-aufgabe) zeigt
ihren eigenen Branch als Preview. Beide müssen **zusammen** vorhanden sein; nur
eine mitzuliefern lässt den Build mit einem Fehler scheitern, statt still auf
den Standard zurückzufallen.

Ohne beide Dateien nutzt Argos einen **mitgelieferten Laravel-Vertrag**: nginx
+ php-fpm + Node, eine MariaDB-Datenbank und einen Standard-Start (`composer
install`, `.env` aus `.env.example`, `php artisan migrate --force --seed`,
`npm ci && npm run build`, `php artisan storage:link`). Seine Datenbank nutzt
die MySQL-Zugangsdaten des Workspaces, und ein Redis-Service kommt dazu, wenn
der Workspace Redis aktiviert hat.

Wie du einen Vertrag schreibst:
[Ein Repository vorbereiten](PREPARE-PROJECT.md#live-preview); die genauen
Befehle: [EXECUTION-COMMANDS.md](EXECUTION-COMMANDS.md#der-preview-deployer).

## Zugriff

Eine Preview ist **nur für Mitglieder**. Jede Anfrage durchläuft den
Forward-Auth-Check von Traefik gegen das Workspace-Gate, das nur Mitglieder des
Workspaces (Admins, Mitglieder und Leser) und Argos-Admins durchlässt. Einen
öffentlichen oder reinen Passwort-Modus gibt es nicht.

## Lebenszyklus

| Zustand | Bedeutung |
| --- | --- |
| **Wird gebaut** | Container fahren hoch, Boot-Befehle und Health-Check laufen. |
| **Live** | Die Preview ist unter ihrer URL erreichbar. |
| **Fehlgeschlagen** | Der Build ist fehlgeschlagen — das Build-Log erklärt, warum; halb gestartete Container werden aufgeräumt. |
| **Gestoppt** | Container, Volumes und Route sind entfernt. Sie kann erneut gestartet werden. |

Eine Preview verlässt den Zustand **Live** durch

- **Ablauf der TTL** — `ARGOS_PREVIEW_TTL_HOURS` (Standard 24) nach ihrem
  Build; eine geplante Aufräumaufgabe stoppt sie,
- **das Limit des Workspaces** — *Parallele Previews* (Einstellungen →
  Ressourcen, Standard 3): Eine weitere zu starten stoppt die älteste Preview
  des Workspaces,
- **die Plattform-Obergrenze** — `ARGOS_PREVIEW_MAX_CONCURRENT` (Standard 10,
  `0` schaltet sie ab): Die ältesten Previews anderer Aufgaben werden gestoppt,
  um Platz zu machen (protokolliert, nie still),
- **das Stoppen des Workspaces** (von Hand oder nach Leerlauf),
- **das Aufräumen der Aufgabe** — `cleanup_after_days` nach Abschluss oder
  Abbruch der Aufgabe oder wenn die Aufgabe gelöscht wird,
- **Preview stoppen**.

## Konfiguration für Betreibende

| Variable | Standard | Zweck |
| --- | --- | --- |
| `ARGOS_PREVIEW_ENABLED` | `true` | Plattformweiter Hauptschalter für Previews. |
| `ARGOS_PREVIEW_TTL_HOURS` | `24` | Wie lange eine Preview lebt, bevor sie automatisch abgebaut wird. |
| `ARGOS_PREVIEW_MAX_CONCURRENT` | `10` | Obergrenze gleichzeitig laufender Previews (`0` schaltet sie ab). |
| `ARGOS_PREVIEW_BASE_DOMAIN` | abgeleitet aus `APP_URL` | Basis-Domain, unter der Previews ausgeliefert werden. |

Previews brauchen einen Traefik-Edge mit dem gemeinsamen Netzwerk `argos_edge`
und eine Basis-Domain, die Wildcards unterstützt. Ohne diese Infrastruktur
schaltest du sie mit `ARGOS_PREVIEW_ENABLED=false` ab. Alle Einstellungen:
[Konfiguration](CONFIGURATION.md#live-previews).
