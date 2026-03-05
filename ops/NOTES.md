# Smoke Tests – Photobox Final Spec v1.0

- JPG nach `watch_path` abgelegt: erscheint in `Neu` in <30s
- Tap auf Kachel oeffnet Detail, Download funktioniert
- Long-Press auf Kachel toggelt Merken und zeigt Toast
- Entfernen aus Merkliste zeigt Toast mit `Rueckgaengig`
- ZIP aus Merkliste laedt und ist entpackbar am Handy
- Bestellung speichert DB-Eintrag + `order_done` zeigt Zusammenfassung
- `/gallery/` laedt read-only offline
- `/admin/?code=falsch` redirect auf `/mobile/` (still)
- `/admin/?code=korrekt` zeigt Tabs (Druckauftraege/Bestellungen/Bilder/Drucker)
