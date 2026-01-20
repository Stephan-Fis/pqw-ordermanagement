# Order-Management

Ein WordPress/WooCommerce Plugin zur erweiterten Verwaltung von Bestellungen im Admin-Bereich. Ermöglicht Aufteilen und Abschließen von Bestellungen auf Kunden- oder Artikel-Ebene sowie asynchrone Verarbeitung über eine Queue.

## Überblick

Das Plugin liefert eine responsive Admin-Oberfläche (Bootstrap) zum Verwalten von Bestellungen mit Status "in Bearbeitung" und "wartend". Es unterstützt Bulk-Operationen, Export-Funktionen und ein Queue-basiertes Hintergrund-Processing für große Mengen.

## Wichtige Änderungen (aktuell)

- Robustere Variantenerkennung: Wenn `product_id` fehlt, wird jetzt die Parent-Produkt-ID aus der Variation ermittelt, sodass Varianten korrekt gruppiert werden.
- Deduplizierung von Optionszeilen: Leere oder identische Options-/Meta-Zeilen werden vor dem Rendern dedupliziert, um unnötige leere Tabellenzeilen zu vermeiden.
- Alphabetische Sortierung: Aggregierte Artikel werden nach Produktname sortiert, Kundenlisten sind nach Namen sortiert.
- Queue-Overlay & Auto-Start: Nach Anlegen von Queue-Einträgen startet ein zentriertes Overlay mit Spinner und Polling; die asynchrone Verarbeitung wird automatisch ausgelöst.
- Verbesserter XLSX-Export: Exportiert aggregierte Daten pro Artikel und optional pro Kunde (inkl. Optionen), lädt SheetJS nur bei Bedarf.

## Features

- Aufteilen (Split) nach Kunde oder Artikel
- Abschließen (Complete) nach Kunde oder Artikel
- Asynchrone Queue-Verarbeitung mit Batch-Size (Standard 20)
- WP-Cron Fallback bei fehlendem asynchronen Trigger
- Responsive Tabellen mit per-Zeile Labels für mobile Ansicht
- XLSX-Export (SheetJS, lokal gebündelt)
- Nonce- und Capability-Checks für Sicherheit

## Installation

1. Plugin-Verzeichnis nach `/wp-content/plugins/ordermanagement/` hochladen.  
2. Im WordPress Admin aktivieren.  
3. Voraussetzungen: WooCommerce installiert/aktiviert. (Optional: PPOM wird geprüft, aber nicht zwingend benötigt.)

## Kompatibilität

- WordPress: 5.0+  
- WooCommerce: 3.0+  
- PPOM for WooCommerce 33.0+
- PHP: 7.4+  
- Bootstrap 5 (gebündelt), SheetJS (gebündelt)

## Verwendung (Kurz)

- Admin → Orders → Wähle Sub-Page (Split/Complete Name/Item).  
- Markiere Kunden oder Artikel per Checkbox.  
- Klick auf Aktion-Button: bei >1 Eintrag werden Queue-Einträge erstellt und asynchron verarbeitet; bei 1 Kunde erfolgt ggf. direkte Verarbeitung.  
- Nach Queue-Erstellung erscheint ein overlay und die Verarbeitung startet automatisch; Seite refresh nach Abschluss.

## Troubleshooting

- Artikel werden nicht gruppiert: Prüfe, ob in Bestell-Items `variation_id` oder `product_id` vorhanden sind; Plugin versucht, Parent-ID aus Variation zu ermitteln.  
- Leere/duplizierte Optionszeilen: Werden nun client- und serverseitig dedupliziert; bei abweichendem Verhalten prüfen, ob Item-Meta unterschiedliche Keys enthält.  
- Queue startet nicht: Stelle sicher, dass AJAX-Aufrufe möglich sind und WP-Cron nicht deaktiviert ist; Logs/DB-Tabelle `wp_om_order_queue` prüfen.

## Datenbank (kurz)

- `wp_om_order_queue` — Queue für Split-Operationen  
- `wp_om_order_complete_queue` — Queue für Complete-Operationen

## AJAX-Endpunkte

- `om_queue_status`, `om_process_queue_async`  
- `om_complete_queue_status`, `om_process_complete_queue_async`  
- `om_process_selected_jobs`

## Changelog

- 1.13.1-260120_20
  - Variant-Parent-Fallback implementiert
  - Deduplizierung leerer/duplizierter Option-Zeilen
  - Alphabetische Sortierung für aggregierte Listen
  - Queue-Overlay / Auto-Start + Polling
  - Verbesserter XLSX-Export (per-Artikel / per-Person)

## Lizenz

GPL v2+

Bei Problemen oder Feature-Wünschen bitte ein Issue öffnen oder PR einreichen.