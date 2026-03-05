# Hochzeits-Fotobox

Offline-first Fotobox-Stack (PHP 8, SQLite, PowerShell-Ops) für Event-LAN ohne Cloud-Zwang.

## 2026-03-05 – Vollständige Prozessübersicht (Ist-Stand)

### 1) Bildentstehung & Ingest
1. Kamera liefert JPGs in den überwachten Eingang (`watch_path`) oder auf SD-Karte (`sd_card_path`, rekursiv).
2. Watcher/Supervisor triggert `import/import_service.php ingest-file <path>`.
3. Import übernimmt Bild nach `data/originals`, erzeugt Thumbnail in `data/thumbs`, schreibt Datensatz in `photos`.

### 2) Index & Medienzugriff
1. SQLite (`data/queue/photobox.sqlite`) ist zentrale Quelle (`photos`, `print_jobs`, `orders`, `order_items`, `kv`).
2. Mobile-Endpunkte lösen Bilder über Token/ID auf, niemals über freie Dateipfade.
3. Medienausgabe läuft über `image.php`/`download.php` mit Header-Härtung und Cache-Strategie.

### 3) Mobile Gäste-Flow
1. Gast öffnet `/mobile/` und sieht standardmäßig neue Fotos im Zeitfenster (`gallery_window_minutes`).
2. Gast kann Fotos merken (Session-basiert), einzeln ansehen und herunterladen.
3. Gast kann 1 Druckjob pro Foto oder 2 Druckjobs aus Favoriten anstoßen (CSRF + Ticket + Rate-Limit).
4. Gast kann Bestellung aus Merkliste abschließen (`order.php`) inkl. Name, E-Mail und optional Versandadresse.
5. Abschlussseite (`order_done.php`) zeigt Betrag, PayPal-Link und QR.

### 4) Druckpipeline
1. API schreibt Job in `print_jobs` und rendert Druckdatei nach `data/printfiles`.
2. Worker (`import/print_worker.php`) verarbeitet seriell, pollt Spoolerstatus und mapped Fehler robust.
3. Statusmodell: `queued`, `sending`, `spooled`, `needs_attention`, `paused`, `done`, `canceled`, `failed_hard`.

### 5) Admin-/Monitoring-Flow
1. `/gallery/` ist öffentliche Read-only Statusansicht.
2. `/admin/` (und Gallery-Admin) ist session-geschützt, sofern `admin_code` oder `admin_password_hash` gesetzt ist.
3. Admin kann Jobs überwachen, retryn/canceln/löschen und Bestell-ZIPs abrufen.

### 6) Betrieb, Stabilität & Cleanup
1. `start.ps1` übernimmt Preflight (PHP/SQLite/ZipArchive), Supervisor, Watcher, Webserver und dcc-Integration.
2. Logging nach `data/logs` mit robustem Sync/Backoff.
3. Cleanup entfernt abgelaufene Daten physisch + markiert DB (`photos.deleted=1`).

## Funktionsstatus – Wie nah an „Fotobox mit allen Funktionen“?

### Bereits stark umgesetzt (Produktionsnah im Event-LAN)
- End-to-End Kern: Import → Galerie → Favoriten → Bestellung → Druckqueue → Worker.
- Offline-first Betrieb ohne Cloud-Abhängigkeit.
- Security-MVP: CSRF, Session-Bindung, Token-Zugriff, Rate-Limit, keine offenen Dateipfade.
- Ops-Härtung auf Windows: Supervisor, Backoff, Fail-Fast, klare Fehlercodes.

### Teilweise umgesetzt / abhängig von Umgebung
- Kamera-Autofluss hängt von digiCamControl/Webserver-Konfiguration auf Host ab.
- Druckqualität und Stabilität hängen von Windows-Spooler + konkretem Druckermodell ab.
- Admin-Authentifizierung ist bewusst pragmatisch (LAN-Event-Modell), nicht Enterprise-Hardening.

### Noch offen bis „vollständig“ im Sinne einer kommerziellen Komplett-Fotobox
- Kein integrierter Kamera-Remote-Workflow in Web-UI (nur Ops-/DCC-getrieben).
- Kein dediziertes Rollen-/Rechtemodell mit mehreren Admin-Rollen.
- Keine automatisierte Test-/Lint-Pipeline im Repo hinterlegt.
- Kein integriertes Zahlungs-Webhook-Matching (PayPal-Link/QR ist vorhanden, Payment-Confirm manuell).

**Kurzbewertung (2026-03-05):**
- Für ein offline Event-Setup ist der Stand **fortgeschritten (ca. 80–85%)**.
- Für „alle Funktionen“ einer voll kommerziellen Fotobox-Plattform fehlen vor allem Automatisierungstiefe, Rollenmodell und formale QA-Pipeline.

## Projektstruktur
- `web/index.php` – Root-Redirect auf `/mobile/`
- `web/mobile` – Gäste-UI + APIs
- `web/gallery` – öffentlicher Monitor + optionaler Gallery-Admin
- `web/admin` – Admin-Panel (Jobs/Bestellungen/Bilder/Drucker)
- `import` – Ingest/Cleanup/Print-Worker CLI
- `ops` – PowerShell Ops- und Druckerhilfen
- `shared` – Bootstrap, Utilities, Konfiguration
- `runtime` – Laufzeit-/Diagnoseartefakte
- `data` – Eventdaten (nicht committen)

## Betriebs-Kommandos
- Start: `./start.ps1`
- Stop: `./stop.ps1`
- Status: `./status.ps1`
- Manueller Webstart: `php -S 0.0.0.0:8080 -t web`
- DB init: `php import/import_service.php init-db`
- Ingest: `php import/import_service.php ingest`
- Ingest-file: `php import/import_service.php ingest-file <path>`
- Cleanup: `php import/import_service.php cleanup`
- Print Worker einmalig: `php import/print_worker.php run`
- Print Worker Loop: `php import/print_worker.php run-loop [sleep_seconds]`

## Konfigurationshinweise
Nutze `shared/config.example.php` als Vorlage für `shared/config.php`.

Wichtige Schlüssel:
- `base_url`, `base_url_mobile`, `port`
- `watch_path`, `data_path`, `import_mode`, `sd_card_path`
- `gallery_window_minutes`, `retention_days`
- `print_api_key`, `printer_name` (über Admin/kv)
- `paypal_me_base_url`, `order_zip_dir`
- `admin_code`, `admin_password_hash`
- `rate_limit_max`, `rate_limit_window_seconds`

## Changelog
- 2026-03-05 – README konsolidiert: vollständige Prozessübersicht ergänzt, doppelte/überlange Historienblöcke entfernt, Reifegradbewertung „Wie nah an kompletter Fotobox“ ergänzt.
