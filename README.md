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
- `web/gallery/index.php`: lokaler Admin/Monitor mit Passwort.
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
- `timezone` (Default: `Europe/Vienna`)
- `retention_days`
- `gallery_window_minutes` (Default: `15`)
- `print_api_key`
- `admin_password_hash_placeholder`
- `rate_limit_max`, `rate_limit_window_seconds`

## Betrieb

### 2026-02-27 – Windows Ops (PowerShell 5.1)
- Start erfolgt über `./start.ps1` (Supervisor + Watcher + PHP-Webserver unter `web` mit Pfaden `/mobile` und `/gallery`).
- Stop erfolgt über `./stop.ps1` (beendet Supervisor/PHP best-effort über State-Datei).
- Status erfolgt über `./status.ps1` (zeigt Prozessstatus, Port-Check, Watcher-Status und Log-Tails).
- Logs liegen in `data/logs`: `supervisor.log`, `watcher.log`, `php.log`.
- Start prüft Port, Firewall-Regel, Watch-Ordner, Kamera-/Drucker-Hinweise und protokolliert Failure-Modes ohne interaktive Prompts.

### Initialisieren
```bash
php import/import_service.php init-db
```

### Neue Bilder importieren
```bash
php import/import_service.php ingest
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

## Sicherheit & Datenschutz (MVP)
- Keine direkten Dateipfade nach außen; nur tokenbasierte URLs (`t=...`).
- Druck nur im Zeitfenster (`gallery_window_minutes`) erlaubt.
- `api_print.php` verlangt API-Key und hat IP-Ratenlimit über SQLite-`kv`.
- Eingaben validiert: Token-Format, Uhrzeit (`HH:MM`), Namenslänge/Zeichensatz.
- `all.php`: `noindex` + `no-store` Header.
- Cleanup löscht physische Dateien und markiert DB-Einträge `deleted=1`.

## Changelog
- 2026-02-27 – Windows Ops ergänzt: `start.ps1` Supervisor/Watcher, `stop.ps1`, `status.ps1`, Firewall- und Gerätechecks, LAN-Offline-Betrieb.
- 2026-02-27 – Web-Ebene implementiert: Mobile Galerie, Alle-Fotos-Ansicht, Bestellung, Print-Job-API, Admin-Statusseite.
- 2026-02-27 – Offline-first Setup ergänzt: Router-/QR-URL-Hinweise, keine externen Assets/Requests.
- 2026-02-27 – MVP implementiert: Import, Thumb-Generierung, SQLite-Index, mobile Galerie mit Zeitfenster/Alle-Fotos, Session-Bestellungen, Druckqueue-API, Druckworker, Cleanup.
- 2026-02-27 – Hardware-Setup und optionale Future-Themen (i2i/Anime nur Placeholder) dokumentiert.
- 2026-02-27 – Security/Privacy Betriebsregeln für den MVP ergänzt.
