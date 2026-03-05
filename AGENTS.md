# AGENTS

## Rollen und Zuständigkeiten
- GPT: nur Anweisung/Erklärung, kein Code, keine Doku-Pflege
- Codex: Code + Doku-Pflege
- Nutzer: liefert Anforderungen, kann ZIP-Dateien geben, die Codex nicht sieht

## Arbeitsregeln
- Keine Doku außerhalb `README.md` und `AGENTS.md`
- Jede Doku-Änderung datieren (Changelog-Eintrag)
- Wenn Anforderungen unklar: Annahmen als `Annahmen` mit Datum dokumentieren
- Kleine, inkrementelle Diffs bevorzugen

## Projektstruktur
- `README.md`: Human-First Projekt- und Betriebsübersicht
- `AGENTS.md`: Agent-First Arbeits-, Architektur- und Dokumentationsstandard
- `web/index.php`: Root-Redirect auf `/mobile/`
- `web/mobile`: Gäste-UI + API
- `web/gallery`: öffentlicher Monitor (read-only) + optionales Admin-Login
- `web/admin`: Admin-Panel für Jobs/Bestellungen/Bilder/Drucker
- `import`: Ingest/Cleanup/Print-Worker CLI
- `ops`: PowerShell-Ops und Druck-Helper
- `shared`: Bootstrap, Konfiguration, Utilities
- `runtime`: Laufzeit-Artefakte
- `data`: Eventdaten (niemals Eventdateien committen)

## Kernmodule (Ist-Stand)
### 2026-03-05 – Konsolidierte Modulübersicht
- **Import**: Übernahme von JPGs aus `watch_path` oder `sd_card_path`, Thumbnail-Erstellung, DB-Indexierung.
- **Index**: SQLite-Auflösung für Token/ID, Listen und Media-Endpunkte.
- **Mobile Web**: Galerie, Favoriten, Einzelbild, Download, Bestellung, Druck-APIs.
- **Print**: Queue-Erstellung + Worker mit Spooler-Polling/Retry.
- **Admin/Monitor**: Öffentliche Statussicht + geschütztes Admin-Handling.
- **Cleanup**: Retention-Löschung im Dateisystem + DB-Markierung.

## Verbindliche Security-/Privacy-Regeln
- Token-/ID-basierter Medienzugriff, keine freien Dateipfade
- CSRF-Prüfung für mutierende Session-Endpunkte
- Rate-Limit über SQLite (`kv`) für relevante APIs
- Print nur bei aktiver Konfiguration (Drucker oder API-Key)
- `no-store`/`noindex` wo erforderlich
- Keine Secrets hardcoden, produktive Werte nur in lokaler `shared/config.php`

## Offline-first Regeln
- Keine CDN-/Remote-Skripte
- Keine verpflichtenden externen Requests im Laufzeitpfad
- Lokale Systemzeit als einzige Zeitquelle

## Kommandos
- start: `./start.ps1`
- stop: `./stop.ps1`
- status: `./status.ps1`
- db init: `php import/import_service.php init-db`
- ingest: `php import/import_service.php ingest`
- ingest-file: `php import/import_service.php ingest-file <path>`
- cleanup: `php import/import_service.php cleanup`
- print worker: `php import/print_worker.php run`
- print worker daemon: `php import/print_worker.php run-loop [sleep_seconds]`

## Boundaries
### ALWAYS
- Security by default
- Doku-Update mit Datum bei Verhaltensänderung
- Bestehende Architektur inkrementell erweitern

### ASK FIRST
- Neue Dependencies
- Neue Ports/Netzwerkfreigaben
- Löschen/Umbenennen von Dateien
- Datenmigrationen mit potenziell inkompatiblen Änderungen

### NEVER
- Doku außerhalb README/AGENTS
- Unsichere Defaults (offene Admin-Endpunkte, direkte Dateipfade)
- Secrets im Code/Repo

## Annahmen
- 2026-03-05 – Reifegradbewertung in README ist eine Architektur-/Betriebsbewertung für den aktuellen MVP-Stand, keine formale Zertifizierung oder Lasttest-Aussage.

## Changelog
- 2026-03-05 – AGENTS konsolidiert: redundante Historienblöcke entfernt, Modul-/Boundary-Regeln auf aktuellen Repo-Stand verdichtet, Security-/Offline-first Leitplanken präzisiert.
