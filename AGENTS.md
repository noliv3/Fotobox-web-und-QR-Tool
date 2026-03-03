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
- `web/index.php`: Root-Entry, Redirect auf `/mobile/`
- `web/gallery`: Galerie-Websegment für Admin/Monitor (lokal)
- `web/mobile`: Handy-Websegment für Gäste und API
- `import`: Importdienst- und Druckworker-CLI
- `ops`: PowerShell-Ops-Module und Supervisor-Helfer
- `runtime`: Laufzeitartefakte (z. B. PID/State)
- `shared`: Gemeinsame Konfiguration, Bootstrap, Utilities
- `data`: Eventdaten (`originals`, `thumbs`, `queue`, `logs`, `watch`); niemals Eventdateien committen

## Kernmodule
### 2026-02-27 – Module
- **Import**
  - Zuständigkeit: Scan von `watch_path` oder rekursivem `sd_card_path` (je nach `import_mode`), JPEG-Übernahme, Thumbnail-Erzeugung, Indexeintrag
  - Inputs/Outputs: `watch_path|sd_card_path` -> `data/originals`, `data/thumbs`, Tabelle `photos`
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


## Konfigurationsschlüssel
### 2026-02-27 – Import-Modi
- `import_mode`: `watch_folder` (Default) oder `sd_card`
- `sd_card_path`: Pfad zur SD-Karte (z. B. `F:\DCIM`), wird bei `import_mode=sd_card` rekursiv überwacht

## Kommandos
### 2026-02-27 – Verfügbare Befehle
- start: `./start.ps1` (Windows Supervisor-Start, startet PHP `-t web` auf konfiguriertem Port)
- stop: `./stop.ps1` (beendet Supervisor/PHP best-effort)
- status: `./status.ps1` (zeigt Supervisor/PHP/Port/Watcher + Log-Tail)
- manueller Start (Default-Port 8080): `php -S 0.0.0.0:8080 -t web`
- historisch (nicht Default): `php -S 0.0.0.0:8000 -t web`
- test: _derzeit nicht definiert (MVP ohne Testsuite)_
- lint: _derzeit nicht definiert_
- build: _nicht erforderlich (PHP ohne Build-Schritt)_
- db init: `php import/import_service.php init-db`
- ingest: `php import/import_service.php ingest`
- ingest-file: `php import/import_service.php ingest-file <path>`
- cleanup: `php import/import_service.php cleanup`
- print worker: `php import/print_worker.php run`
- print worker daemon: `php import/print_worker.php run-loop [sleep_seconds]`

## Datenbank-Schema
### 2026-02-27 – SQLite `data/queue/photobox.sqlite`
1) `photos(id TEXT PRIMARY KEY, ts INTEGER, filename TEXT, token TEXT UNIQUE, thumb_filename TEXT, deleted INTEGER DEFAULT 0)`
2) `print_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT, photo_id TEXT, created_ts INTEGER, status TEXT, error TEXT NULL)`
3) `kv(key TEXT PRIMARY KEY, value TEXT)`
4) `orders(id INTEGER PRIMARY KEY AUTOINCREMENT, created_ts INTEGER, guest_name TEXT, session_token TEXT, status TEXT, note TEXT)`
5) `order_items(order_id INTEGER, photo_id TEXT, PRIMARY KEY(order_id, photo_id))`

## API-Endpunkte
### 2026-02-27 – Mobile API Dokumentation
- Endpoint: `/mobile/api_print.php`
  - Zweck: Druckjob anlegen
  - Request: `POST t`, Auth über Header `X-API-Key` oder Feld `print_api_key`
  - Response: `{jobId:int}`
  - Fehlerfälle: `400 invalid_token`, `403 forbidden|outside_print_window`, `429 rate_limited`, `404 photo_not_found`, `503 print_not_configured`
  - Security: API-Key + IP-Rate-Limit + Zeitfensterprüfung
  - Privacy: keine PII außer IP-basiertes Rate-Limit in `kv`
  - Status/ToDo: Print ist nur mit gesetztem `print_api_key` aktiv (nicht leer, nicht `CHANGE_ME_PRINT_API_KEY`); sonst `503 print_not_configured`. Linux-Spooler aktiv; unter Windows endet der Job mit `error=NOT_IMPLEMENTED_WINDOWS_PRINT` (kein Re-Pending)

- Endpoint: `/mobile/api_job.php`
  - Zweck: Jobstatus lesen
  - Request: `GET id`
  - Response: `{status:string,error:string|null}`
  - Fehlerfälle: `400 invalid_job_id`, `404 not_found`
  - Security: nur validierte Integer-ID
  - Privacy: keine personenbezogenen Felder
  - Status/ToDo: offen für optionales Polling im Frontend

- Endpoint: `/mobile/api_mark.php`
  - Zweck: Foto in Session-Bestellung aufnehmen
  - Request: `POST t`, optional `guest_name`
  - Response: `{orderId:int,itemsCount:int}`
  - Fehlerfälle: `400 invalid_token`, `404 photo_not_found`
  - Security: Token-Validierung, Name-Sanitizing, session-basierte Zuordnung, CSRF-Header-Prüfung (`X-CSRF-Token`) für POST
  - Privacy: speichert nur `guest_name` und Session-Token
  - Status/ToDo: stabiler MVP-Umfang (implementiert)

- Endpoint: `/mobile/api_unmark.php`
  - Zweck: Foto aus Session-Bestellung entfernen
  - Request: `POST t`
  - Response: `{itemsCount:int}`
  - Fehlerfälle: `400 invalid_token`, `404 photo_not_found`
  - Security: session-basierte Löschung, Token-Validierung
  - Privacy: keine zusätzlichen Daten
  - Status/ToDo: stabiler MVP-Umfang

- Endpoint: `/mobile/api_order_name.php`
  - Zweck: Namen der aktuellen Session-Bestellung setzen/ändern
  - Request: `POST guest_name`
  - Response: `{ok:true}`
  - Fehlerfälle: `405 method_not_allowed`
  - Security: Name-Sanitizing und Session-Bindung
  - Privacy: nur minimaler Namensstring
  - Status/ToDo: stabiler MVP-Umfang

### 2026-02-27 – Zusätzliche Web-Endpunkte
- Endpoint: `/`
  - Zweck: Root-Redirect auf `/mobile/`
  - Request: `GET`
  - Response: `302 Location: /mobile/`

- Endpoint: `/mobile/image.php`
  - Zweck: JPEG-Ausgabe für `thumb|original` per Token
  - Request: `GET t`, `GET type`
  - Response: `image/jpeg`
  - Fehlerfälle: `400 invalid_token|invalid_type`, `404 not_found`
  - Security: niemals Dateipfade aus Query verwenden

- Endpoint: `/mobile/download.php`
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

## Annahmen
### 2026-03-03 – Admin-Härtung im Event-LAN
- Annahme: Im geschlossenen Hochzeits-LAN wird auf zusätzliche harte Admin-Auth-Mechanismen (MFA/VPN/externe IAM) verzichtet.
- Begründung: Offline-first Betrieb mit geringer Komplexität vor Ort; bestehendes Session-Gating bleibt als pragmatischer Basisschutz aktiv.

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

- 2026-03-03 – Mobile Header-Menüfix: Im Mobile-Header wurde das rechte „Galerie“-Textfeld vollständig entfernt und durch einen kleinen Hamburger-Button (`.menu-button`) ersetzt; der Galerie-Link bleibt ausschließlich im Overlay-Menü (`menu-panel`) erreichbar.

- 2026-03-03 – Windows Print Hardening: `ops/print/printer_status.ps1`, `job_status.ps1` und `submit_job.ps1` erzwingen jetzt strikt JSON-only-Ausgabe (UTF-8 ohne BOM, stille Streams, try/catch-Fehlercodes). `submit_job.ps1` nutzt deterministisches `PrintDocument`-Fill-Scaling auf `MarginBounds`, setzt eindeutige `DocumentName`s (`photobox_job_<jobid>_<unix>`) und pollt die Spool-`jobId` bis zu 10x/200ms (`JOB_ID_NOT_FOUND` bei Timeout). `import/print_worker.php` ruft PowerShell mit `-NonInteractive` via getrennten stdout/stderr-Pipes auf, behandelt JSON-Parsefehler als `PS_JSON_INVALID` ohne Fatal, und drosselt stderr-Logs auf Statuswechsel/Fingerprint statt Logflood.

- 2026-03-03 – Print-Pipeline-Härtung (Windows/CP1500): `print_jobs` um Spool-/Retry-Felder erweitert (`queued|sending|spooled|needs_attention|paused|done|canceled|failed_hard`, `spool_job_id`, `attempts`, `next_retry_at`, `printfile_path`, `updated_at`) inkl. Indizes. `web/mobile/api_print.php` erstellt persistente `data/printfiles/<jobid>.jpg`, erzwingt Queue-Cap (50 offene Jobs, `503 queue_full`) und liefert nur JSON `{ok,job_id,status}`. `import/print_worker.php` arbeitet mit Backpressure (max 1 aktiver Spool-Job), pollt Spooler-Zustände, mapped Fehler robust auf `needs_attention`/Retry statt Jobverlust und loggt nur Zustandswechsel/neue Fehlercodes. Neue PS-Helper unter `ops/print/*` (`printer_status.ps1`, `job_status.ps1`, `submit_job.ps1`) liefern deterministisches JSON. Cleanup schützt Originals bei offenen Jobs ohne vorhandenes Printfile und löscht Printfiles nur für `done|canceled|failed_hard`. Galerie-Status zeigt offene Jobs, Needs-Attention-Liste und letzte 20 Jobs read-only.

- 2026-03-03 – Final Audit Hardening: SQLite-PDO initialisiert jetzt mit `PRAGMA journal_mode=WAL` und `PRAGMA busy_timeout=5000` zur besseren Parallelitaet. Mobile Medien-Downloads nutzen harte Pfadauflösung (`basename` + Verzeichnisgrenzen-Check) gegen Path Traversal. Mobile/Admin-Fetches pruefen jetzt `response.ok` und JSON-Content-Type robust, Fehler zeigen konsistent einen UI-Hinweis statt still zu scheitern. Mobile Grid/CSS wurde fuer kleine Viewports korrigiert (kein Rechts-Offset, kein horizontales Scrollen). Bestellseite leitet bei leerer Merkliste serverseitig auf `/mobile/` um.
- 2026-03-03 – Ops/Admin/Download-Update: `start.ps1` startet bei Pending-Druckjobs synchron `import/print_worker.php run` und führt zusätzlich stündlich `import/import_service.php cleanup` aus. `web/mobile/download_zip.php` löscht beim Aufruf verwaiste ZIP-Dateien älter als 2 Stunden in `data/tmp`. Im Admin-Tab Bestellungen kann der Status per neuem Button auf `done` gesetzt werden (`complete_order`).
- 2026-03-03 – Mobile/CSRF-Refactor: Neue `initMobileSession()` zentralisiert `pb_mobile`-Sessionstart, Favoriten-Init und CSRF-Token-Init. Mobile-Views nutzen den zentralen Aufruf statt redundanter Session-Blöcke. `_layout.php` injiziert CSRF-Meta-Tag, `web/mobile/app.js` sendet `X-CSRF-Token` bei POST und behandelt HTTP-Fehler robust vor JSON-Parsing; `api_mark.php` bricht bei fehlendem/ungültigem Header mit `403` ab.
- 2026-03-03 – Security/Ops-Update: Print-Auth auf Session-gebundene CSRF- + Print-Ticket-Validierung umgestellt (kein Print-Secret im Client). Admin nutzt Session-Gating (Code/Passwort beim Einstieg, danach Session), mutierende Admin-/Order-POSTs prüfen CSRF. Admin-Fotolöschung verwendet nun Retention-kompatibel `photos.deleted=1` + Datei-Delete statt Hard-Delete. Supervisor startet bei Pending-Queue automatisch `print_worker.php run`; `import/print_worker.php` unterstützt zusätzlich `run-loop` für Daemon-Betrieb. Öffentliche Galerie zeigt keine Print-Konfigurations-/Fehlerdetails mehr; Galerie-Styles auf Mobile-Look vereinheitlicht.
- 2026-03-01 – Final Spec v1.0 integriert: Mobile-Routing `view=recent|all|favs` mit einheitlichem Layout/Toast/Long-Press/Undo, Session-Merkliste-API erweitert (`add|remove|toggle|list`), ZIP-Download und neuer Bestellabschluss (`order_done`) implementiert. Gallery ist read-only Statusseite; neuer `/admin/`-Bereich mit stillem Code-Gating, Tabs (Jobs/Bestellungen/Bilder/Drucker), Printer-Settings (`settings.printer_name`) und gezieltem Action-Logging (`admin.log`).
- 2026-03-01 – PHP-Bootstrap-Kompatibilität ergänzt: Legacy-Aufrufe (`app_config`, `app_paths`, `app_pdo`, `write_log`, `random_token`, `validate_token`, `find_photo_by_token`, `is_photo_printable`, `initialize_database`) werden wieder zentral in `shared/bootstrap.php` bereitgestellt, damit Import/Print/Web-Endpunkte konsistent funktionieren.
- 2026-03-01 – Import robust ohne GD: `import/import_service.php` nutzt bei fehlender GD-Funktion `imagecreatefromjpeg` einen Fallback und erstellt das Thumbnail als Kopie des Originals statt mit Fatal Error abzubrechen.
- 2026-03-01 – Watcher/Ops-Fix: Watcher verarbeitet jetzt zusätzlich `Changed`-Events und ruft `import/import_service.php ingest-file <path>` über absoluten Skriptpfad auf (robuster gegen Runspace/Working-Directory-Probleme). Außerdem wurde der `php -r`-Pending-Count im Supervisor auf robuste Here-String-Ausführung mit STDERR-Abfangung umgestellt, um sporadische `Command line code`-Parse-Meldungen nicht unkontrolliert auszugeben.
- 2026-03-01 – Doku-Konsistenz korrigiert: API-Endpunkte auf `/mobile/...` vereinheitlicht, Root-Redirect `/ -> /mobile/` ergänzt, Projektstruktur um `ops`, `runtime`, `web/index.php` erweitert, Kommandoliste um `ingest-file` ergänzt, manueller Start auf Default-Port `8080` präzisiert (`8000` nur historisch), `api_mark.php`-Status als implementiert dokumentiert.
- 2026-03-01 – Print-API/UI gehärtet: Print bleibt standardmäßig deaktiviert, solange `print_api_key` leer oder `CHANGE_ME_PRINT_API_KEY` ist. `web/mobile/photo.php` zeigt den Druckbutton nur bei Zeitfenster + konfiguriertem Print, sonst Hinweis „Druck nicht konfiguriert“. `web/mobile/api_print.php` liefert in diesem Fall `503 print_not_configured`; außerhalb des Zeitfensters bleibt `403 outside_print_window`.
- 2026-03-01 – Print-Queue-Verhalten geschärft: `import/print_worker.php` setzt nicht druckbare Jobs nach einem Versuch auf `error` statt `pending` (Windows: `NOT_IMPLEMENTED_WINDOWS_PRINT`, kein Spooler: `NO_SYSTEM_SPOOLER`); dadurch blockieren solche Jobs keine nachfolgenden Pending-Jobs.
- 2026-02-27 – Start/Ops+Import erweitert: PHP-Preflight mit `php -v` und Fallback-Plan `-n`, fail-fast ohne Restart-Loop wenn `pdo_sqlite` fehlt, Watcher-Health ohne Subscription-State (Inaktivität nur WARN), neuer Importmodus `watch_folder|sd_card` mit rekursiver SD-Karten-Überwachung.
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
