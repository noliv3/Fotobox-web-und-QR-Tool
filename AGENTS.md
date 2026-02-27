# AGENTS

## Rollen und Zuständigkeiten
- GPT: nur Anweisung/Erklärung, kein Code, keine Doku-Pflege
- Codex: Code + Doku-Pflege
- Nutzer: liefert Anforderungen, kann ZIP-Dateien geben, die Codex nicht sieht

## Arbeitsregeln
- Keine Doku außerhalb README.md und AGENTS.md
- Jede Doku-Änderung datieren
- Wenn Anforderungen unklar: Annahmen als "Annahmen" mit Datum dokumentieren

## Projektstruktur
- `README.md`: Human-First Projekt- und Betriebsübersicht
- `AGENTS.md`: Agent-First Arbeits-, Architektur- und Dokumentationsstandard
- `web/gallery`: Galerie-Websegment für Anzeige freigegebener Fotos
- `web/mobile`: Handy-Websegment für mobile Interaktionen
- `import`: Importdienst-Segment für Fotoübernahme (USB/SD)
- `shared`: Gemeinsame Konfigurations- und Utility-Stubs
- `data`: Eventdaten (originals, thumbs, queue, logs); niemals ins Repo committen außer `.gitkeep`
- Weitere Projektpfade/Ordner sind bei Einführung hier mit Zweck und Verantwortlichkeit zu ergänzen

## Kernmodule
- **Import**
  - Zuständigkeit: Übernahme neuer Fotos aus der Aufnahmequelle in den internen Datenfluss
- **Index**
  - Zuständigkeit: Erfassung, Indizierung und Auffindbarkeit der Fotos für Galerie/Prozesse
- **Web**
  - Zuständigkeit: Bereitstellung der Galerie und öffentlich erreichbarer Endpunkte
- **Print**
  - Zuständigkeit: Verarbeitung von Druckanforderungen und Übergabe an Drucksysteme
- **Cleanup**
  - Zuständigkeit: Retention, Löschung und Aufräumprozesse nach definierten Fristen

## Kommandos
> Echte Befehle für `start`, `test`, `lint`, `build` sind zu dokumentieren, sobald sie im Projekt verfügbar sind.
- start: _derzeit nicht definiert_
- test: _derzeit nicht definiert_
- lint: _derzeit nicht definiert_
- build: _derzeit nicht definiert_

## Security & Privacy Regeln für Code-Änderungen
- Security by default: keine offenen Uploads, kein Directory Listing, keine unsicheren Standardendpunkte
- Eingaben validieren (Typ, Länge, erlaubte Werte), Dateipfade absichern, Fehlerausgaben ohne sensible Daten
- Keine Secrets im Code oder in Commits hardcoden
- Datenschutzdaten minimieren und nur zweckgebunden verarbeiten
- Speicherort, Retention und Löschpfad dokumentieren, sobald Datenhaltung betroffen ist

## Boundaries
### ALWAYS
- Kleine Diffs, inkrementell
- Security by default (keine offenen Uploads/Directory Listing)
- Dokumentationsupdate mit Datum bei jeder Verhaltensänderung

### ASK FIRST
- Neue Dependencies
- Neue Ports/Netzwerkfreigaben
- Löschen/Umbenennen von Dateien, Datenmigrationen

### NEVER
- Doku außerhalb README/AGENTS
- Unsichere Defaults (unauth endpoints, direkte Dateipfade)
- Hardcoding von Secrets

## Dokumentationsstandard
### 2026-02-27 – Dokumentationsstandard (verbindlich)

### A) Dokumentations-Checkliste pro Code-Änderung
- Betroffene Module benennen
- Öffentliche Schnittstellen dokumentieren (URL, Methode, Parameter, Response, Errors)
- Datenfluss dokumentieren (Quelle -> Verarbeitung -> Speicher -> Ausgabe)
- Security: Auth/Token, Rate Limit, Input Validation, Pfadschutz
- Privacy: welche Daten, Speicherort, Retention, Löschpfad
- Betrieb: Logs, Konfiguration, Failure Modes, Recovery
- Testhinweis: was wurde getestet, wie reproduzierbar

### B) Struktur für API/Endpoints (wenn vorhanden)
- Endpoint: /path
- Zweck:
- Request:
- Response:
- Fehlerfälle:
- Security:
- Privacy:
- Status/ToDo:

### C) Struktur für Module
- Modulname:
- Verantwortung:
- Inputs/Outputs:
- Abhängigkeiten:
- Konfig:
- Fehlerfälle:
- Tests:

### D) Decision Log (ADR-light)
- 2026-02-27 – Entscheidung: Zeitfenster-Galerie statt Vollgalerie, Grund: Privatsphäre/Übersicht
- Format je Entscheidung:
  - Datum
  - Entscheidung
  - Kontext
  - Alternativen
  - Konsequenzen

### E) Boundaries (präzise)
ALWAYS:
- Kleine Diffs, inkrementell
- Security by default (keine offenen Uploads/Directory Listing)
- Dokumentationsupdate mit Datum bei jeder Verhaltensänderung

ASK FIRST:
- Neue Dependencies
- Neue Ports/Netzwerkfreigaben
- Löschen/Umbenennen von Dateien, Datenmigrationen

NEVER:
- Doku außerhalb README/AGENTS
- Unsichere Defaults (unauth endpoints, direkte Dateipfade)
- Hardcoding von Secrets

## Decision Log
- 2026-02-27 – Entscheidung: Zeitfenster-Galerie statt Vollgalerie.
  - Kontext: Eventgalerien benötigen einfachen Zugriff für Gäste, aber begrenzte Sichtbarkeit aus Datenschutz- und Übersichtsgründen.
  - Alternativen: Vollgalerie ohne Zeitlimit; passwortgeschützte Vollgalerie; rein lokaler Einzelzugriff.
  - Konsequenzen: Bessere Privatsphäre und übersichtlichere Nutzung, aber zusätzlicher Aufwand für Zeitfenster-Konfiguration.

## Startstatus
- 2026-02-27: Repository-Grundgerüst für "Hochzeits-Fotobox" initialisiert.

## Changelog
- 2026-02-27 – Projektstruktur aktualisiert: Segmentpfade und Verantwortlichkeiten ergänzt; Hinweis zu `data/` und `.gitkeep` ergänzt.
- 2026-02-27 – Verbindlichen Dokumentationsstandard, Boundaries, Decision-Log-Format und Pflichtinhalte ergänzt.
- 2026-02-27 – Initiale Arbeitsregeln und Rollen dokumentiert.
