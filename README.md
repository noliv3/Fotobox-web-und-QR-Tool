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
- `shared/bootstrap.php`: Konfiguration, DB, Ratenlimit, Token-/Session-Helfer.

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
- 2026-02-27 – MVP implementiert: Import, Thumb-Generierung, SQLite-Index, mobile Galerie mit Zeitfenster/Alle-Fotos, Session-Bestellungen, Druckqueue-API, Druckworker, Cleanup.
- 2026-02-27 – Hardware-Setup und optionale Future-Themen (i2i/Anime nur Placeholder) dokumentiert.
- 2026-02-27 – Security/Privacy Betriebsregeln für den MVP ergänzt.
