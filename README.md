# Hochzeits-Fotobox

Die **Hochzeits-Fotobox** ist ein offline-first MVP in PHP 8.x ohne Frameworks und ohne externe Abhängigkeiten. Bilder werden lokal importiert, indexiert, in einer mobilen Galerie angezeigt, optional innerhalb eines Zeitfensters gedruckt und nach Retention automatisch gelöscht.

## MVP-Umfang
### 2026-02-27 – MVP-Spezifikation + Hardware-Setup
- HDMI Live-View wird separat am Monitor betrieben.
- USB/Canon Tool dient ausschließlich der Bildübernahme in `watch_path`.
- Gäste sehen standardmäßig nur die letzten 15 Minuten und können dort drucken.
- Die komplette Galerie ist separat verfügbar, aber ohne Druckfunktion.
- Markieren/Bestellen funktioniert ohne Login per Name + Session-Cookie.

### 2026-02-27 – Future/Optional
- i2i/Anime bleibt ein optionaler Queue-Worker als Platzhalter in der Planung.
- i2i/Anime ist im MVP **nicht** implementiert.

## Architektur (MVP)
- `import/import_service.php`: CLI für DB-Setup, Ingest und Cleanup.
- `import/print_worker.php`: CLI für serielle Druckjobs über System-Spooler.
- `web/mobile/*`: Gästeansichten + API-Endpunkte.
- `web/gallery/index.php`: öffentlicher Galerie-/Monitor-Status (read-only).
- `web/gallery/admin.php`: optionaler Admin-Login, standardmäßig deaktiviert.
- `shared/bootstrap.php`: Konfiguration, DB-Autoinit, Header- und Order-Helfer.
- `shared/utils.php`: Zeit-, Validierungs-, Session- und Rate-Limit-Utilities.

## Offline-Setup (LAN ohne Internet)
### 2026-02-27 – Offline-first Betrieb
- Router SSID: `FOTOBOX_SSID_PLACEHOLDER`
- Router Passwort: `FOTOBOX_PASSWORT_PLACEHOLDER`
- Lokale QR-Ziel-URL: `http://photobox:8080/` oder alternativ `http://192.168.8.2:8080/`
- HTTP-Port: `8080` (konfigurierbar über `port` in `shared/config.php`).
- Keine externen Ressourcen verwenden (keine CDN-Assets, keine externen APIs, keine Analytics).
- Systemzeit des Mini-PC lokal korrekt halten; der Code nutzt keine Online-Zeitquelle.

## Konfiguration
Datei: `shared/config.php` (lokal erstellen, `shared/config.example.php` als Vorlage nutzen).

Wichtige Schlüssel:
- `base_url`, `base_url_mobile`
- `watch_path`, `data_path`
- `import_mode` (`watch_folder` oder `sd_card`)
- `sd_card_path` (z. B. `F:\\DCIM` für Kartenleser-Betrieb)
- `timezone` (Default: `Europe/Vienna`)
- `retention_days`
- `gallery_window_minutes` (Default: `15`)
- `print_api_key`
- `paypal_me_base_url` (z. B. `https://paypal.me/DEINNAME`)
- `order_zip_dir` (Default: `data/orders`)
- `order_max_age_hours` (legacy, aktuell ohne Wirkung auf Bestellfreigabe)
- `admin_password_hash` (`CHANGE_ME` = deaktiviert, alternativ zu `admin_code`)
- `admin_code` (`CHANGE_ME_ADMIN_CODE` = deaktiviert, alternativ zu `admin_password_hash`)
- `rate_limit_max`, `rate_limit_window_seconds`

## Betrieb

### 2026-02-27 – Windows Ops (PowerShell 5.1)
### 2026-03-04 – digiCamControl Auto-Install + Fail-Fast
- `start.ps1` führt vor dem Start der Photobox-Dienste `ops/install_digicamcontrol.ps1` aus. Wenn digiCamControl fehlt, wird der Installer (`digiCamControlsetup_2.1.7.exe`) automatisch geladen und mit `/SP- /VERYSILENT /SUPPRESSMSGBOXES /NORESTART` silent installiert.
- Bei Download-/Installationsfehlern wird sofort mit eindeutiger Fehlercode-Zeile abgebrochen (`kein Restart-Loop`). Ohne Internet ist der Ablauf nur dann erfolgreich, wenn ein lokaler Installer unter `E:\photobooth\runtime\downloads\digiCamControlsetup_2.1.7.exe` bereits vorhanden ist.
- `start.ps1` erzwingt die Firewall-Regel `Photobooth digiCamControl Webserver 5513` (TCP Inbound) und bricht ohne Admin-Rechte/Firewall-Fehler fail-fast ab, damit LiveView/Capture zuverlässig erreichbar ist.
- Wenn weder `digiCamControl.exe` noch `CameraControl.exe` läuft, wird die gefundene EXE minimiert gestartet und anschließend maximal 10 Sekunden `http://127.0.0.1:5513/session.json` geprüft. Ohne HTTP 200 bricht der Start mit `DCC_WEBSERVER_NOT_READY` ab und verweist klar auf `Settings -> Webserver -> Use web server` + Neustart von digiCamControl.
- Nach erfolgreichem Healthcheck setzt `start.ps1` `session.folder` per SLC auf `E:\photobooth\data\watch`; bei Fehler wird der Start ebenfalls abgebrochen.

#### digiCamControl Webserver-Endpunkte (lokal)
- LiveView starten: `http://127.0.0.1:5513/?CMD=LiveViewWnd_Show`
- Live Frame: `http://127.0.0.1:5513/liveview.jpg`
- Capture: `http://127.0.0.1:5513/?CMD=Capture`

- Start erfolgt über `./start.ps1` (Supervisor + Watcher + PHP-Webserver unter `web` mit Pfaden `/mobile` und `/gallery`).
- Stop erfolgt über `./stop.ps1` (beendet Supervisor/PHP best-effort über State-Datei).
- Status erfolgt über `./status.ps1` (zeigt Prozessstatus, Port-Check, Watcher-Status und Log-Tails).
- Logs liegen in `data/logs`: `supervisor.log`, `watcher.log`, `php.log`, `import.log`, `cleanup.log`, `print_worker.log`.
- Start prüft Port, Firewall-Regel, Watch-Ordner, Kamera-/Drucker-Hinweise und protokolliert Failure-Modes ohne interaktive Prompts.
- Log-Sync von PHP-Process-Redirection ist lock-tolerant: Dateilesen erfolgt mit `FileShare.ReadWrite`, nutzt Retry-Backoff (100/300/800 ms) und schreibt bei weiterhin gelockter Datei nur `WARN`, damit `start.ps1` weiterläuft.
- `start.ps1` kapselt `Sync-PhpProcessLogs` in Supervisor-Loop und Shutdown-Phase in `try/catch`; Log-Sync-Fehler führen nicht mehr zum Supervisor-Abbruch.



### 2026-02-27 – Kamera ohne USB (SD-Karte bevorzugt)
- Bevorzugter Importweg ohne USB-Tethering: SD-Karte der Kamera im Kartenleser des Mini-PC (`import_mode=sd_card`).
- In diesem Modus überwacht der Watcher rekursiv `sd_card_path` statt `watch_path`; WLAN-Transfer bleibt optional.
- Bei ausbleibenden neuen JPGs wird nur `WARN` geloggt (best-effort), kein Watcher-Neustart allein wegen Inaktivität.

### 2026-02-27 – PHP-Start robust bei kaputter php.ini
- Preflight bewertet `php -v` normal; bei Parse-Error/ExitCode!=0 wird auf `php -n` für Diagnosen und geplante Start-Commandline umgeschaltet.
- Die tatsächliche Start-Commandline wird immer im `supervisor.log` protokolliert (inkl. `-n`, falls aktiv).
- Falls `pdo_sqlite` im `-n`-Modus fehlt, startet der Webserver nicht (fail-fast, kein Restart-Loop) mit klarer Reparaturanweisung in den Logs.

### 2026-02-27 – Windows PHP-Diagnose und SQLite-Pflicht
- `./start.ps1` prüft vor dem Serverstart zwingend `php -v`, `php --ini` und `php -m`.
- Bei Parse-/INI-Fehlern (z. B. `Parse error`, `Command line code`) startet der PHP-Server **nicht**; Supervisor setzt Fehlerstatus statt Endlos-Restart.
- Leere Zeilen in der PHP-Diagnoseausgabe werden beim Logging übersprungen, damit `Write-PhotoboxLog` keine leeren `Message`-Werte erhält und `start.ps1` nicht mit ParameterBinding-Fehler abbricht.
- SQLite ist Pflicht für den MVP: `pdo_sqlite` muss in `php -m` vorhanden sein (`sqlite3` allein reicht nicht, da die App PDO nutzt).
- Logs enthalten bei Fehlern immer die vollständige Diagnoseausgabe von `php --ini` und `php -m` in `data/logs/php.log`.
- `./status.ps1` erzeugt fehlende `data/logs` automatisch und liefert dadurch auch ohne vorherigen `./start.ps1` robust OK/FAIL-Diagnosen.

#### Konkreter Fix für php.ini (Windows)
1. In der aktiven `php.ini` (siehe `php --ini`) aktivieren:
   - `extension=pdo_sqlite`
   - `extension=sqlite3`
2. Zusätzliche INI-Dateien auf Syntaxfehler prüfen (insbesondere bei Meldungen mit `Command line code`/`Parse error`).
3. Falls die INI-Landschaft beschädigt ist: portable, saubere PHP-Version unter `runtime/php/` verwenden oder bestehende INI-Dateien reparieren.


### 2026-02-27 – Galerie Auth-Modell
- `/gallery/` ist öffentlich und zeigt read-only Status, letzte Fotos und letzte Jobs ohne Login.
- `/gallery/admin.php` ist optional geschützt (Session-Cookie `pb_admin`).
- Admin ist aktiv, wenn mindestens eine Methode gesetzt ist: `admin_code` oder `admin_password_hash`.
- Fuer reinen Passwort-Login `admin_code` auf `CHANGE_ME_ADMIN_CODE` lassen und `admin_password_hash` setzen.
- Ist beides deaktiviert, redirectet `/admin/` wie bisher auf `/mobile/`.
- Passwort-Hash erzeugen: `php -r "echo password_hash('DEINPASS', PASSWORD_DEFAULT), PHP_EOL;"`

### Initialisieren
```bash
php import/import_service.php init-db
```

### Neue Bilder importieren
```bash
php import/import_service.php ingest
```

### Einzeldatei importieren
```bash
php import/import_service.php ingest-file <path>
```

### Alte Daten bereinigen
```bash
php import/import_service.php cleanup
```

### Druckjobs verarbeiten
```bash
php import/print_worker.php run
```

## Datenfluss
`watch_path` -> Import (`/data/originals`) -> Thumbnail (`/data/thumbs`) -> SQLite-Index (`/data/queue/photobox.sqlite`) -> Web-Ausgabe (Token-URLs) -> optional Druckqueue -> Cleanup nach Retention.

## Annahmen
### 2026-03-03 – Admin-Härtung im Hochzeitsbetrieb
- Annahme: Für den geschlossenen Hochzeits-LAN-Betrieb wird bewusst auf zusätzliche "harte" Admin-Auth-Schichten (z. B. MFA, VPN oder externe IAM-Systeme) verzichtet.
- Begründung: Das System bleibt offline-first und lokal bedienbar; die bestehende Session-basierte Admin-Absicherung bleibt aktiv, um versehentliche Änderungen abzufangen, ohne den Ablauf vor Ort unnötig zu verkomplizieren.

## Sicherheit & Datenschutz (MVP)
- Keine direkten Dateipfade nach außen; nur tokenbasierte URLs (`t=...`).
- Druck nur im Zeitfenster (`gallery_window_minutes`) erlaubt.
- `api_print.php` nutzt Session-CSRF + Print-Ticket; optionaler API-Key wird nur geprüft, wenn gesetzt. IP-Ratenlimit läuft über SQLite-`kv`.
- Eingaben validiert: Token-Format, Uhrzeit (`HH:MM`), Namenslänge/Zeichensatz.
- `all.php`: `noindex` + `no-store` Header.
- Cleanup löscht physische Dateien und markiert DB-Einträge `deleted=1`.

## Changelog

- 2026-03-04 – Print-Worker Diagnose- und Statusfix: `import/print_worker.php` behandelt Druckerstatus `Normal|Idle|Ready` nicht mehr als Fehler, sodass Queue-Jobs abgearbeitet werden. Blockierende Fehler (`VIRTUAL_PRINTER_UNSUPPORTED`, `PRINTER_NOT_FOUND`) gehen auf `needs_attention`, während `JOB_ID_NOT_FOUND` retrybar bleibt und bestehende Alt-Jobs mit diesem Fehler automatisch wieder auf `queued` gesetzt werden. `ops/print/submit_job.ps1` erkennt virtuelle Drucker (z. B. `Microsoft Print to PDF`, `OneNote`) explizit als nicht unterstützte Zielgeräte.
- 2026-03-04 – Supervisor Pending-Check korrigiert: `start.ps1` nutzt für Pending-Count, `print_worker.php run` und stündliches Cleanup dieselben PHP-Prefix-Args wie der Webserver (`Get-PhpLaunchPlan`), damit Queue-Jobs auch bei Fallback-Startmodus (`-n`) zuverlässig erkannt und verarbeitet werden.

- 2026-03-04 – Merkliste-Print (2 neue Bilder) + Druckfreigabe bei konfiguriertem Drucker: `/mobile/?view=favs` bietet jetzt `2 Gemerkte drucken` (wenn mindestens zwei neue druckbare Favoriten vorhanden sind) via neuem Endpoint `/mobile/api_print_favs.php`. Zusätzlich zeigt die Detailseite den Druckbutton, sobald ein Druckername gesetzt ist (`printer_name`) oder ein API-Key konfiguriert ist.
- 2026-03-04 – Drucker/Admin-Wartung erweitert: Admin-Login akzeptiert jetzt konfiguriertes Passwort auch ohne gesetzten `admin_code` (oder weiterhin Code). Der Drucker-Tab (`/admin/?tab=printer`) zeigt Spooler/CP1500-Erkennung, bietet `CP1500 koppeln` per WLAN-IP und setzt bei Erfolg den Druckernamen automatisch. Job-Retry setzt Druckjobs wieder konsistent auf `queued` statt Legacy-`pending`.
- 2026-03-04 – Bestell-Flow ohne mbstring gehärtet: `shared/utils.php` nutzt mit `textSubstr()` jetzt einen Fallback auf `iconv_substr`/`substr`, und `web/mobile/order.php` verwendet diesen Helper für E-Mail/Adressfelder. Damit führt fehlendes `mbstring` nicht mehr zu `500` in `order.php` beim POST.
- 2026-03-04 – Supervisor-Worker-Kopplung verschärft: Bei PHP-Webserver-Absturz stoppt `start.ps1` den Watcher sofort und startet ihn erst nach erfolgreichem PHP-Restart wieder, damit ohne laufende Webapp keine Import-/Worker-Aktivität weiterläuft.
- 2026-03-04 – digiCamControl EXE-Erkennung korrigiert: `start.ps1` und `ops/install_digicamcontrol.ps1` erkennen jetzt sowohl `digiCamControl.exe` als auch `CameraControl.exe` (inkl. `Program Files` und `Program Files (x86)`). Zusätzlich wurde der `param(...)`-Block in `ops/install_digicamcontrol.ps1` an den Dateianfang verschoben, damit `-SupervisorLog` bei direktem und indirektem Aufruf zuverlässig gebunden wird.
- 2026-03-04 – digiCamControl Install-Flow stabilisiert: `ops/install_digicamcontrol.ps1` nutzt TLS1.2, SourceForge-Primär/Fallback-URL mit Redirect-Limit, validiert Downloadgröße (>20MB), verwendet Offline-Fallback auf vorhandenen lokalen Installer und liefert im Fehlerfall genau einen Maschinen-Code (`DCC_DOWNLOAD_FAILED_OFFLINE`, `DCC_DOWNLOAD_FAILED`, `DCC_INSTALL_EXITCODE_*`, `DCC_INSTALL_NOT_DETECTED`). `start.ps1` übernimmt den Rückgabecode und bricht fail-fast mit exakt einer eindeutigen Grundzeile ab; der 5513-Healthcheck meldet bei Fehlschlag `DCC_WEBSERVER_NOT_READY` inkl. Aktivierungs-Hinweis für den DCC-Webserver.
- 2026-03-04 – Ops digiCamControl-Integration: Neues `ops/install_digicamcontrol.ps1` ergänzt automatische Silent-Installation (SourceForge-Installer) mit klaren Phase-Logs (`installed`, `download_failed`, `install_failed`). `start.ps1` wurde um fail-fast Integration erweitert: Install-Preflight vor PHP-Start, verpflichtende Firewall-Regel für TCP/5513, digiCamControl-Prozessstart, Webserver-Healthcheck (`/session.json`) und SLC-Set für `session.folder` auf `E:\photobooth\data\watch`. Bei jedem Fehlschritt erfolgt sofortiger Abbruch ohne Restart-Loop.
- 2026-03-03 – Korrektur Bestelllogik + Mobile-Performance: Die 24h-Altersgrenze für Bestellungen wurde entfernt (Bestellungen sind nicht mehr vom Fotoalter abhängig). Mobile-Grid nutzt Lazy-Loading (`loading="lazy"`, `decoding="async"`, `fetchpriority="low"`) mit stabiler 1:1-Thumbnail-Geometrie. „Alle“ zeigt strikt alle nicht gelöschten Fotos (`deleted=0`, Sortierung nach `created_at DESC`), „Neu“ filtert nur nach Zeitfenster und nicht nach Druckstatus. Bildendpunkte liefern jetzt aggressive Byte-Caches (`public, max-age=31536000, immutable`) mit `ETag`/`Last-Modified`/`304`, während HTML weiterhin `no-store` bleibt. Foto-Detail und Download unterstützen stabile `id`-Links (Token nur noch kompatibler Alias).

- 2026-03-03 – Bestellwesen Final: `web/mobile/order.php` validiert jetzt Name+E-Mail (und bei Versand vollstaendige Adresse), erzwingt die 24h-Regel auf Basis der Foto-Zeitstempel, speichert Bestellungen mit `order_token`/`price_cents`/`paypal_url` und erzeugt pro Bestellung ein Admin-ZIP unter `data/orders/<order_id>/order_<order_id>.zip` (wenn `ZipArchive` verfuegbar). `web/mobile/order_done.php` nutzt Token-Lookup und zeigt PayPal-Abschnitt inkl. QR-Bild (`/mobile/qr.php`) + Offline-Hinweis. Admin-Bereich zeigt ZIP-Link (`/admin/download_order_zip.php`) nur intern; Legacy-APIs (`api_order_name.php`, `api_unmark.php`) wurden auf Session+CSRF+Rate-Limit gehaertet.
- 2026-03-03 – Mobile/ZIP-Hardening: `.menu-overlay` rendert im Hidden-State nun strikt mit `display:none` und schaltet nur sichtbar auf `display:flex`, um fehlerhafte Header-Layouts zu vermeiden. `web/mobile/download_zip.php` wurde offline-stabil gehärtet (Empty-State statt Fatal, `ZipArchive`-Check, `data/tmp`-Checks, Max-Items=200, robuste Header/Output-Buffer-Bereinigung, nur valide Originale im ZIP). `start.ps1` prüft zusätzlich fail-fast auf `ZipArchive` und bricht mit klarer Meldung bei fehlender ZIP-Extension ab.

- 2026-03-03 – Mobile Header-Menüfix: Im Mobile-Header wurde das rechte „Galerie“-Textfeld vollständig entfernt und durch einen kleinen Hamburger-Button (`.menu-button`) ersetzt; der Galerie-Link bleibt ausschließlich im Overlay-Menü (`menu-panel`) erreichbar.

- 2026-03-03 – Windows Print Hardening: `ops/print/printer_status.ps1`, `job_status.ps1` und `submit_job.ps1` erzwingen jetzt strikt JSON-only-Ausgabe (UTF-8 ohne BOM, stille Streams, try/catch-Fehlercodes). `submit_job.ps1` nutzt deterministisches `PrintDocument`-Fill-Scaling auf `MarginBounds`, setzt eindeutige `DocumentName`s (`photobox_job_<jobid>_<unix>`) und pollt die Spool-`jobId` bis zu 10x/200ms (`JOB_ID_NOT_FOUND` bei Timeout). `import/print_worker.php` ruft PowerShell mit `-NonInteractive` via getrennten stdout/stderr-Pipes auf, behandelt JSON-Parsefehler als `PS_JSON_INVALID` ohne Fatal, und drosselt stderr-Logs auf Statuswechsel/Fingerprint statt Logflood.

- 2026-03-03 – Print-Pipeline-Härtung (Windows/CP1500): `print_jobs` um Spool-/Retry-Felder erweitert (`queued|sending|spooled|needs_attention|paused|done|canceled|failed_hard`, `spool_job_id`, `attempts`, `next_retry_at`, `printfile_path`, `updated_at`) inkl. Indizes. `web/mobile/api_print.php` erstellt persistente `data/printfiles/<jobid>.jpg`, erzwingt Queue-Cap (50 offene Jobs, `503 queue_full`) und liefert nur JSON `{ok,job_id,status}`. `import/print_worker.php` arbeitet mit Backpressure (max 1 aktiver Spool-Job), pollt Spooler-Zustände, mapped Fehler robust auf `needs_attention`/Retry statt Jobverlust und loggt nur Zustandswechsel/neue Fehlercodes. Neue PS-Helper unter `ops/print/*` (`printer_status.ps1`, `job_status.ps1`, `submit_job.ps1`) liefern deterministisches JSON. Cleanup schützt Originals bei offenen Jobs ohne vorhandenes Printfile und löscht Printfiles nur für `done|canceled|failed_hard`. Galerie-Status zeigt offene Jobs, Needs-Attention-Liste und letzte 20 Jobs read-only.

- 2026-03-03 – Final Audit Hardening: SQLite-PDO initialisiert jetzt mit `PRAGMA journal_mode=WAL` und `PRAGMA busy_timeout=5000` zur besseren Parallelitaet. Mobile Medien-Downloads nutzen harte Pfadauflösung (`basename` + Verzeichnisgrenzen-Check) gegen Path Traversal. Mobile/Admin-Fetches pruefen jetzt `response.ok` und JSON-Content-Type robust, Fehler zeigen konsistent einen UI-Hinweis statt still zu scheitern. Mobile Grid/CSS wurde fuer kleine Viewports korrigiert (kein Rechts-Offset, kein horizontales Scrollen). Bestellseite leitet bei leerer Merkliste serverseitig auf `/mobile/` um.
- 2026-03-03 – Ops/Admin/Download-Update: `start.ps1` startet bei Pending-Druckjobs synchron `import/print_worker.php run` und führt zusätzlich stündlich `import/import_service.php cleanup` aus. `web/mobile/download_zip.php` löscht beim Aufruf verwaiste ZIP-Dateien älter als 2 Stunden in `data/tmp`. Im Admin-Tab Bestellungen kann der Status per neuem Button auf `done` gesetzt werden (`complete_order`).
- 2026-03-03 – Mobile/CSRF-Update: Mobile-Session-Initialisierung auf `initMobileSession()` zentralisiert (`pb_mobile`, Favoriten-Init, CSRF-Token-Init). Mobile-Layout enthält CSRF-Meta-Tag, `app.js` sendet `X-CSRF-Token` bei jedem POST und behandelt HTTP-Fehler robust vor JSON-Parsing. `api_mark.php` validiert CSRF-Header mit 403 bei fehlendem/ungültigem Token.
- 2026-03-03 – Security/Ops-Update: Print-Auth von Client-Secret auf Session-CSRF + kurzlebiges Print-Ticket umgestellt (kein `print_api_key` mehr im HTML), Admin auf Session-Auth fixiert und mutierende Admin-/Bestell-POSTs um CSRF erweitert. Admin-Bildlöschung vereinheitlicht auf Datei-Delete + `photos.deleted=1` (kein Hard-Delete). Supervisor triggert jetzt bei Pending-Jobs automatisch `import/print_worker.php run`; zusätzlich unterstützt der Worker `run-loop` für Dauerbetrieb. Galerie-UI farblich auf Mobile-Design vereinheitlicht und öffentliche Print-Status/Fehleranzeige entfernt.
- 2026-03-01 – Final Spec v1.0 umgesetzt: Mobile auf zentrales Layout mit Tabs/Overlay/Footer + Toast/Long-Press/Undo umgestellt, Session-Merkliste via `api_mark.php` (add/remove/toggle/list), Detailseite/ZIP/Bestellfluss erneuert, Gallery auf read-only Statusseite reduziert und neuer stiller `/admin/`-Bereich mit Jobs/Bestellungen/Bildern/Drucker-Settings (inkl. Printer-Erkennung und Action-Logging) hinzugefuegt.
- 2026-03-01 – Kompatibilitätsfix im Bootstrap: Legacy-Funktionsnamen für Import/Print/ältere Endpunkte werden wieder unterstützt (`app_*`, `write_log`, Token-/Photo-Helfer), sodass Watcher-`ingest-file` nicht mehr mit `undefined function app_paths()` abbricht.
- 2026-03-01 – Import-Fallback ohne GD: Wenn `imagecreatefromjpeg` nicht verfügbar ist, wird das Thumbnail als Kopie des Originals erzeugt, damit der Import nicht mit Fatal Error stoppt.
- 2026-03-01 – Ops-Verbesserung für Stabilität: Der Watcher reagiert neben `Created/Renamed` nun auch auf `Changed` und nutzt für `ingest-file` den absoluten Pfad auf `import/import_service.php`, damit JPEG-Events im Watch-Ordner zuverlässig verarbeitet werden. Zusätzlich wurde der interne `php -r`-Pending-Count-Aufruf robust gemacht (Here-String + STDERR-Abfangung), um sporadische `Command line code`-Parse-Ausgaben zu vermeiden.
- 2026-03-01 – Doku-Konsistenz ergänzt: Logliste um `import.log`, `cleanup.log`, `print_worker.log` erweitert und Kommando `ingest-file` dokumentiert; Portangaben bleiben konsistent auf Default `8080`.
- 2026-03-01 – Print standardmäßig deaktiviert bis bewusst konfiguriert: `print_api_key` aktiviert Print nur, wenn er weder leer noch `CHANGE_ME_PRINT_API_KEY` ist. In `mobile/photo.php` erscheint „Drucken“ nur bei Zeitfenster + konfiguriertem Print; sonst innerhalb des Zeitfensters Hinweis „Druck nicht konfiguriert“. `mobile/api_print.php` liefert dann `503 print_not_configured`, außerhalb des Zeitfensters weiterhin `403 outside_print_window`.
- 2026-03-01 – Print-Worker entblockt: nicht druckbare Jobs werden nach genau einem Verarbeitungsversuch auf `error` gesetzt statt auf `pending` (Windows: `NOT_IMPLEMENTED_WINDOWS_PRINT`, ohne `lp/lpr`: `NO_SYSTEM_SPOOLER`), damit nachfolgende Jobs nicht blockieren.
- 2026-02-27 – Start/Ops gehärtet: `php -v` Preflight mit Auto-Fallback auf `php -n`; tatsächliche PHP-Start-Commandline wird immer geloggt; bei `-n` ohne `pdo_sqlite` fail-fast ohne Restart-Loop. Watcher-Health basiert jetzt auf Watcher-Objekt/Handler/Recent-Exception statt Subscription-State, Inaktivität erzeugt nur WARN. Importmodus erweitert um `watch_folder|sd_card` mit rekursivem SD-Card-Scan (Kamera ohne USB).
- 2026-02-27 – Windows Ops Loghandling gehärtet: `Sync-PhpProcessLogs` liest Redirect-Logs lock-tolerant (`FileShare.ReadWrite`) mit Retry/Backoff (100/300/800 ms), schreibt bei persistierendem Lock `WARN` und läuft weiter; `start.ps1` behandelt Log-Sync-Fehler defensiv ohne Crash.
- 2026-02-27 – Start-Fix: Leere Zeilen aus `php -v/--ini/-m`-Diagnose werden beim Schreiben in `php.log` ignoriert, damit `Write-PhotoboxLog` nicht mit leerer `Message` fehlschlägt.
- 2026-02-27 – Galerie-Auth umgestellt: `/gallery/` öffentlich/read-only, optionales `/gallery/admin.php` mit `pb_admin`-Session, Default `admin_password_hash=CHANGE_ME` (Admin deaktiviert); Ops-Fixes: SQLite-Preflight verlangt `pdo_sqlite`, `status.ps1` funktioniert ohne vorherigen Start durch `data/logs`-Autocreate.
- 2026-02-27 – Windows Run stabilisiert: PHP-Konfigurationsdiagnose (`php -v/--ini/-m`), SQLite-Pflichtprüfung, Crash-Backoff (5/10/20/40/60s), HALT nach 5 Crashes, Root-Redirect `web/index.php` ergänzt.
- 2026-02-27 – Windows Ops ergänzt: `start.ps1` Supervisor/Watcher, `stop.ps1`, `status.ps1`, Firewall- und Gerätechecks, LAN-Offline-Betrieb.
- 2026-02-27 – Web-Ebene implementiert: Mobile Galerie, Alle-Fotos-Ansicht, Bestellung, Print-Job-API, Admin-Statusseite.
- 2026-02-27 – Offline-first Setup ergänzt: Router-/QR-URL-Hinweise, keine externen Assets/Requests.
- 2026-02-27 – MVP implementiert: Import, Thumb-Generierung, SQLite-Index, mobile Galerie mit Zeitfenster/Alle-Fotos, Session-Bestellungen, Druckqueue-API, Druckworker, Cleanup.
- 2026-02-27 – Hardware-Setup und optionale Future-Themen (i2i/Anime nur Placeholder) dokumentiert.
- 2026-02-27 – Security/Privacy Betriebsregeln für den MVP ergänzt.


