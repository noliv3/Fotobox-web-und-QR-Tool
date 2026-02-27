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
- `web/gallery`: Galerie-Websegment für Admin/Monitor (lokal)
- `web/mobile`: Handy-Websegment für Gäste und API
- `import`: Importdienst- und Druckworker-CLI
- `shared`: Gemeinsame Konfiguration, Bootstrap, Utilities
- `data`: Eventdaten (`originals`, `thumbs`, `queue`, `logs`, `watch`); niemals Eventdateien committen

## Kernmodule
### 2026-02-27 – Module
- **Import**
  - Zuständigkeit: Scan von `watch_path`, JPEG-Übernahme, Thumbnail-Erzeugung, Indexeintrag
  - Inputs/Outputs: `watch_path` -> `data/originals`, `data/thumbs`, Tabelle `photos`
- **Index**
  - Zuständigkeit: SQLite-gestützte Auffindbarkeit per Token/Zeitraum/Pagination
  - Inputs/Outputs: Tabelle `photos`, Token-Auflösung für Media-Endpunkte
- **Web**
  - Zuständigkeit: Mobile Gäste-UI, API-Endpunkte, Admin-Monitor
  - Inputs/Outputs: Token-URLs, Session-Cookie, JSON-APIs
- **Print**
  - Zuständigkeit: Print-Queue-Anlage + serielle Worker-Verarbeitung
  - Inputs/Outputs: Tabelle `print_jobs`, Spooler (`lp`/`lpr`)
- **Cleanup**
  - Zuständigkeit: Retention-Löschung (Dateisystem + DB-Flag)
  - Inputs/Outputs: Entfernte Dateien, `photos.deleted=1`

## Kommandos
### 2026-02-27 – Verfügbare Befehle
- start: `php -S 0.0.0.0:8000 -t web`
- test: _derzeit nicht definiert (MVP ohne Testsuite)_
- lint: _derzeit nicht definiert_
- build: _nicht erforderlich (PHP ohne Build-Schritt)_
- db init: `php import/import_service.php init-db`
- ingest: `php import/import_service.php ingest`
- cleanup: `php import/import_service.php cleanup`
- print worker: `php import/print_worker.php run`

## Datenbank-Schema
### 2026-02-27 – SQLite `data/queue/photobox.sqlite`
1) `photos(id TEXT PRIMARY KEY, ts INTEGER, filename TEXT, token TEXT UNIQUE, thumb_filename TEXT, deleted INTEGER DEFAULT 0)`
2) `print_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT, photo_id TEXT, created_ts INTEGER, status TEXT, error TEXT NULL)`
3) `kv(key TEXT PRIMARY KEY, value TEXT)`
4) `orders(id INTEGER PRIMARY KEY AUTOINCREMENT, created_ts INTEGER, guest_name TEXT, session_token TEXT, status TEXT, note TEXT)`
5) `order_items(order_id INTEGER, photo_id TEXT, PRIMARY KEY(order_id, photo_id))`

## API-Endpunkte
### 2026-02-27 – Mobile API Dokumentation
- Endpoint: `/web/mobile/api_print.php`
  - Zweck: Druckjob anlegen
  - Request: `POST token`, Auth über Header `X-API-Key` oder Feld `api_key`
  - Response: `{jobId:int}`
  - Fehlerfälle: `400 invalid_token`, `403 forbidden|outside_print_window`, `429 rate_limited`, `404 photo_not_found`
  - Security: API-Key + IP-Rate-Limit + Zeitfensterprüfung
  - Privacy: keine PII außer IP-basiertes Rate-Limit in `kv`
  - Status/ToDo: Linux-Spooler aktiv, Windows bewusst Placeholder im Worker

- Endpoint: `/web/mobile/api_job.php`
  - Zweck: Jobstatus lesen
  - Request: `GET id`
  - Response: `{status:string,error:string|null}`
  - Fehlerfälle: `400 invalid_job_id`, `404 not_found`
  - Security: nur validierte Integer-ID
  - Privacy: keine personenbezogenen Felder
  - Status/ToDo: offen für optionales Polling im Frontend

- Endpoint: `/web/mobile/api_mark.php`
  - Zweck: Foto in Session-Bestellung aufnehmen
  - Request: `POST token`, optional `guest_name`
  - Response: `{orderId:int,itemsCount:int}`
  - Fehlerfälle: `400 invalid_token`, `404 photo_not_found`
  - Security: Token-Validierung, Name-Sanitizing, session-basierte Zuordnung
  - Privacy: speichert nur `guest_name` und Session-Token
  - Status/ToDo: ZIP bleibt Placeholder

- Endpoint: `/web/mobile/api_unmark.php`
  - Zweck: Foto aus Session-Bestellung entfernen
  - Request: `POST token`
  - Response: `{itemsCount:int}`
  - Fehlerfälle: `400 invalid_token`, `404 photo_not_found`
  - Security: session-basierte Löschung, Token-Validierung
  - Privacy: keine zusätzlichen Daten
  - Status/ToDo: stabiler MVP-Umfang

- Endpoint: `/web/mobile/api_order_name.php`
  - Zweck: Namen der aktuellen Session-Bestellung setzen/ändern
  - Request: `POST guest_name`
  - Response: `{ok:true}`
  - Fehlerfälle: `405 method_not_allowed`
  - Security: Name-Sanitizing und Session-Bindung
  - Privacy: nur minimaler Namensstring
  - Status/ToDo: stabiler MVP-Umfang

## Security & Privacy Regeln für Code-Änderungen
### 2026-02-27 – Verbindliche MVP-Regeln
- Token-URLs für Medienzugriff, keine direkten Dateipfade nach außen
- Zeitfenster-Druckregel aktiv (`gallery_window_minutes`)
- Inputvalidierung: Token, Zeitformat `HH:MM`, Namenslänge/Zeichen
- API-Rate-Limit pro IP + Zeitfenster über SQLite-`kv`
- `all.php` mit `noindex` und `no-store`
- Retention-Löschung physisch + DB-Markierung (`deleted=1`)
- Keine Secrets im Code hardcoden; produktive Werte in lokaler `shared/config.php`

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

## Decision Log
- 2026-02-27 – Entscheidung: Zeitfenster-Galerie statt Vollgalerie.
  - Kontext: Eventgalerien benötigen einfachen Zugriff für Gäste, aber begrenzte Sichtbarkeit aus Datenschutz- und Übersichtsgründen.
  - Alternativen: Vollgalerie ohne Zeitlimit; passwortgeschützte Vollgalerie; rein lokaler Einzelzugriff.
  - Konsequenzen: Bessere Privatsphäre und übersichtlichere Nutzung, aber zusätzlicher Aufwand für Zeitfenster-Konfiguration.
- 2026-02-27 – Entscheidung: Offline-first ohne externe APIs/Composer.
  - Kontext: stabile Nutzung auch ohne Internetzugriff auf Events.
  - Alternativen: Cloud-Services für Queue/Storage/Auth.
  - Konsequenzen: geringere Ausfallrisiken, aber mehr lokale Betriebsverantwortung.

## Startstatus
- 2026-02-27: Repository-Grundgerüst für "Hochzeits-Fotobox" initialisiert.

## Changelog
- 2026-02-27 – Module, Kommandos, DB-Schema und API-Endpunkte für MVP ergänzt.
- 2026-02-27 – Security/Privacy Regeln konkretisiert (Token, Zeitfenster-Druck, Retention, Rate-Limit).
- 2026-02-27 – Projektstruktur aktualisiert: Segmentpfade und Verantwortlichkeiten ergänzt; Hinweis zu `data/` und `.gitkeep` ergänzt.
- 2026-02-27 – Verbindlichen Dokumentationsstandard, Boundaries, Decision-Log-Format und Pflichtinhalte ergänzt.
- 2026-02-27 – Initiale Arbeitsregeln und Rollen dokumentiert.
