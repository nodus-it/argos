# User Global System Prompt

Diese Datei wird zu jedem Worker-Phase-System-Prompt zusätzlich angefügt. Hier kannst du Konventionen festlegen, die für **alle** deine Projekte gelten sollen.

Diese Default-Version ist bewusst minimal — passe sie an deine Bedürfnisse an. Beim Image-Build wird sie ins Image kopiert.

## Beispiel-Konventionen (anpassen oder löschen)

### Code-Stil PHP

- `declare(strict_types=1);` in jeder PHP-Datei
- Final-Klassen wo möglich
- Readonly-Properties für DTOs/Value Objects

### Tests

- Pest bevorzugt vor PHPUnit
- Feature-Tests für HTTP-Endpunkte, Unit-Tests für reine Logik
- Test-Files spiegeln die Verzeichnis-Struktur des produktiven Codes

### Architektur

- Service-Klassen statt Eloquent-Logik in Controllern
- DTOs/Value Objects statt Arrays für strukturierte Daten

### Sonstiges

- Niemals Secrets in Code, immer aus `.env`
- Logging über `Log::`-Facade, nicht `var_dump`/`dd` im Production-Code
