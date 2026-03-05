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
  - Request: `POST t`, Auth über Header `X-API-Key` oder Feld `print_api_key`
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
  - Request: `POST t`, optional `guest_name`
  - Response: `{orderId:int,itemsCount:int}`
  - Fehlerfälle: `400 invalid_token`, `404 photo_not_found`
  - Security: Token-Validierung, Name-Sanitizing, session-basierte Zuordnung
  - Privacy: speichert nur `guest_name` und Session-Token
  - Status/ToDo: ZIP bleibt Placeholder

- Endpoint: `/web/mobile/api_unmark.php`
  - Zweck: Foto aus Session-Bestellung entfernen
  - Request: `POST t`
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

### 2026-02-27 – Zusätzliche Web-Endpunkte
- Endpoint: `/web/mobile/image.php`
  - Zweck: JPEG-Ausgabe für `thumb|original` per Token
  - Request: `GET t`, `GET type`
  - Response: `image/jpeg`
  - Fehlerfälle: `400 invalid_token|invalid_type`, `404 not_found`
  - Security: niemals Dateipfade aus Query verwenden

- Endpoint: `/web/mobile/download.php`
  - Zweck: Originalbild als Attachment herunterladen
  - Request: `GET t`
  - Response: `image/jpeg` mit `Content-Disposition: attachment`
  - Fehlerfälle: `400 invalid_token`, `404 not_found`
  - Security: Token-Auflösung ausschließlich über DB


- Endpoint: `/gallery/`
  - Zweck: Öffentliche read-only Monitoransicht ohne Login
  - Request: `GET`
  - Response: HTML Status mit letzten Fotos und letzten Print-Jobs
  - Security: Keine Admin-Aktionen, nur Lesesicht

- Endpoint: `/gallery/admin.php`
  - Zweck: Optionaler Admin-Login/Platzhalterbereich
  - Request: `GET|POST password`
  - Response: HTML Login oder "Admin OK"
  - Fehlerfälle: `403` wenn `admin_password_hash` fehlt oder `CHANGE_ME`
  - Security: Session-Cookie `pb_admin`, `password_verify` gegen `admin_password_hash`

## Ops (Windows)
### 2026-02-27 – Supervisor, Watcher, Failure-Modes
- Einstiegspunkt ist `./start.ps1` (PowerShell 5.1, keine Prompts, offline-first).
- Supervisor überwacht alle 5 Sekunden: PHP-Prozess und Watcher-Subscriptions (Created/Renamed).
- Watcher reagiert auf `*.jpg|*.jpeg`, wartet auf FileReady und triggert `php import/import_service.php ingest-file <path>`.
- Logs unter `data/logs`: `supervisor.log`, `watcher.log`, `php.log` (ISO-Zeitstempel + Level + Nachricht).
- Failure-Modes werden klar geloggt: Port belegt, PHP fehlt, PHP-INI Parse-Fehler (`Parse error`/`Command line code`), fehlender SQLite-Treiber (`pdo_sqlite` Pflicht; `sqlite3` nur Zusatzinfo), Watch-Ordner fehlt/nicht schreibbar, Prozess ExitCode, fehlende Admin-Rechte für Firewall-Regel, gelockte PHP-Redirect-Logdatei.
- Supervisor-Restart-Strategie: exponentieller Backoff (5s, 10s, 20s, 40s, max. 60s), nach 5 PHP-Crashes Status `HALT` ohne Endlosloop.
- PHP-Log-Sync ist lock-tolerant: Shared-Read mit `FileShare.ReadWrite`, Retry-Backoff (100/300/800 ms), danach `WARN` und fortsetzen ohne Supervisor-Abbruch; Zugriff wird per benanntem Mutex serialisiert.
- Firewall: bei Admin automatische Regel via `New-NetFirewallRule`, sonst exakten Admin-Befehl ausgeben (ohne Interaktion).
- `status.ps1` erzeugt `data/logs` bei Bedarf automatisch und läuft damit auch ohne vorherigen `start.ps1` robust durch.
- Kamera-/Druckerchecks sind best-effort; fehlende Kamera bzw. ausbleibende Bilder (`camera_idle_minutes`, Default 30) führen nur zu Warnungen.

## Security & Privacy Regeln für Code-Änderungen
### 2026-02-27 – Verbindliche MVP-Regeln
- Token-URLs für Medienzugriff, keine direkten Dateipfade nach außen
- Zeitfenster-Druckregel aktiv (`gallery_window_minutes`)
- Inputvalidierung: Token, Zeitformat `HH:MM`, Namenslänge/Zeichen
- API-Rate-Limit pro IP + Zeitfenster über SQLite-`kv`
- `all.php` mit `noindex` und `no-store`
- Retention-Löschung physisch + DB-Markierung (`deleted=1`)
- Keine Secrets im Code hardcoden; produktive Werte in lokaler `shared/config.php`

### 2026-02-27 – Offline-first Regeln
- Keine externen Requests, keine CDN-Assets, keine Remote-Skripte
- QR-Ziel zeigt immer auf lokale URL (`hostname` oder `LAN-IP`)
- Kein Online-Time-Sync im Code; nur lokale Systemzeit verwenden
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
- 2026-02-27 – Ops-Log-Sync gehärtet: lock-tolerantes Lesen von `php.stdout.current.log`/`php.stderr.current.log` via `FileShare.ReadWrite`, Retry-Backoff (100/300/800 ms), danach `WARN` + Continue; Zugriff serialisiert per Mutex, `start.ps1` fängt Sync-Fehler defensiv ab.
- 2026-02-27 – Start-Fix: Leere Zeilen aus der PHP-Diagnoseausgabe (`php -v/--ini/-m`) werden beim Schreiben nach `php.log` übersprungen, damit `Write-PhotoboxLog` keinen leeren `Message`-Parameter erhält.
- 2026-02-27 – Galerie-Auth geändert: `/gallery/` öffentlich (read-only), `/gallery/admin.php` optional passwortgeschützt und nur aktiv mit gesetztem `admin_password_hash` (nicht `CHANGE_ME`). Zusätzlich Ops-Fixes: SQLite-Preflight akzeptiert nur `pdo_sqlite`, `status.ps1` erstellt `data/logs` selbst.
- 2026-02-27 – Windows Run-Härtung ergänzt: PHP-Config-Preflight (`php -v/--ini/-m`), SQLite-Treiber-Check, Crash-Backoff/HALT und Root-Redirect auf `/mobile/`.
- 2026-02-27 – Ops Commands und Windows-Supervisor/Watcher-Verhalten inklusive Logs und Failure-Modes ergänzt.
- 2026-02-27 – Web-Endpunkte und API-Verhalten (Parameter, Responses, Errors) konkretisiert.
- 2026-02-27 – Offline-first Regeln als verbindlicher Web-Standard ergänzt.
- 2026-02-27 – Module, Kommandos, DB-Schema und API-Endpunkte für MVP ergänzt.
- 2026-02-27 – Security/Privacy Regeln konkretisiert (Token, Zeitfenster-Druck, Retention, Rate-Limit).
- 2026-02-27 – Projektstruktur aktualisiert: Segmentpfade und Verantwortlichkeiten ergänzt; Hinweis zu `data/` und `.gitkeep` ergänzt.
- 2026-02-27 – Verbindlichen Dokumentationsstandard, Boundaries, Decision-Log-Format und Pflichtinhalte ergänzt.
- 2026-02-27 – Initiale Arbeitsregeln und Rollen dokumentiert.
- 2026-03-05 – AGENTS konsolidiert: redundante Historienblöcke entfernt, Modul-/Boundary-Regeln auf aktuellen Repo-Stand verdichtet, Security-/Offline-first Leitplanken präzisiert.
