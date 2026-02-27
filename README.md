# Hochzeits-Fotobox

Die **Hochzeits-Fotobox** ist ein lokales System zur Aufnahme, Bereitstellung und optionalen Ausgabe von Eventfotos für Hochzeiten und ähnliche Veranstaltungen. Der Fokus liegt auf einem einfachen Gästeerlebnis (QR-gestützter Zugriff), klaren Betreiberprozessen (Import, Galerie, Druck) und einem datenschutzsensiblen Betrieb im lokalen Umfeld.

## Features
- Lokale Fotobox-Nutzung für Veranstaltungen
- QR-Zugang zur Webgalerie für Gäste
- Bild-Download über die Galerie
- Übergabe von Bildern an eine Druckqueue
- Modularer Datenfluss zwischen Aufnahme, Import und Ausgabe

## Nutzerablauf (Gäste) in 4 Schritten
1. Foto wird an der Fotobox aufgenommen.
2. Gast öffnet die Galerie über den bereitgestellten QR-Code.
3. Gast sieht freigegebene Bilder im gültigen Zeitfenster.
4. Gast lädt Bilder herunter oder markiert sie für den Druck (falls aktiviert).

## Preise und Buchung
Preise, Pakete und Buchungslogik werden projektspezifisch gepflegt. Diese Informationen werden kurz und verständlich in diesem README ergänzt, sobald verbindliche Angebotsdaten vorliegen.

## Datenschutz Kurzinfo
- **Zweck:** Bereitstellung und optionaler Ausdruck von Eventfotos.
- **Zugriff:** Gäste über QR-Link im vorgesehenen Zeitfenster; Betreiber mit administrativem Zugriff.
- **Speicherort:** Primär lokal im Veranstaltungs- oder Betreiberumfeld.
- **Löschung:** Nach definierter Retention-Frist und/oder auf Anforderung über dokumentierten Löschpfad.

## Setup Kurzinfo
High-Level Setup: Kamera/Fotobox erzeugt Bilder, Importprozess übernimmt Dateien, Webkomponente stellt Galerie bereit, Druckkomponente verarbeitet Druckaufträge. Detaillierte Implementierungs- und Betriebsdetails werden ausschließlich in README.md und AGENTS.md ergänzt.

## Dokumentationsrichtlinie
### 2026-02-27 – Dokumentationsrichtlinie (verbindlich)

1) **Doku-Orte**
- Erlaubt: README.md, AGENTS.md
- Verboten: Wiki, Kommentare als Ersatz, separate Doku-Dateien
- Jede Doku-Änderung: datierter Eintrag (YYYY-MM-DD) im jeweiligen Abschnitt "Changelog"

2) **Was in README.md stehen muss (Human-First)**
- Zweck und Umfang (1 Absatz)
- Features (Liste)
- Nutzerablauf (Gäste) in 4 Schritten
- Preise und Buchung (kurz)
- Datenschutz Kurzinfo (Zweck, Zugriff, Speicherort, Löschung)
- Setup Kurzinfo (nur High-Level, keine langen HowTos)
- Changelog (datierte Stichpunkte)

3) **Was in AGENTS.md stehen muss (Agent-First)**
- Rollen und Zuständigkeiten (GPT/Codex/Nutzer)
- Projektstruktur (wichtige Ordner, Dateinamen, Pfade)
- Kernmodule (Import, Index, Web, Print, Cleanup) mit Zuständigkeit je Modul
- Kommandos (start, test, lint, build) als echte Befehle, sobald vorhanden
- Boundaries: ALWAYS / ASK FIRST / NEVER
- Security & Privacy Regeln für Code-Änderungen
- Decision Log: datierte Architekturentscheidungen (ADR-light)

## Changelog
- 2026-02-27 – Verbindliche Dokumentationsrichtlinie ergänzt und README auf Human-First-Struktur ausgerichtet.
- 2026-02-27 – Initiale Repo-Struktur und Basisdokumentation.
