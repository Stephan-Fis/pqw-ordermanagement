# PQW Order-Management

Ein WordPress/WooCommerce Plugin für erweiterte Bestellungsverwaltung mit Aufteilungs- und Abschlussfunktionen.

## Überblick

Das PQW Order-Management Plugin erweitert WooCommerce um leistungsstarke Funktionen zur Verwaltung von Bestellungen im Status "in Bearbeitung". Es ermöglicht das Aufteilen und Abschließen von Bestellungen auf Kunden- oder Artikelebene mit einer modernen, responsiven Benutzeroberfläche.

## Features

### Bestellungsaufteilung

- **Nach Kunde**: Alle Bestellungen eines Kunden auf "wartend" setzen
- **Nach Artikel**: Einzelne Artikel in separate Bestellungen aufteilen
- Automatische Stornierung bei 0€-Bestellungen
- Erhaltung der Bestellhistorie

### Bestellungsabschluss

- **Nach Kunde**: Alle Bestellungen eines Kunden abschließen
- **Nach Artikel**: Einzelne Artikel-Bestellungen abschließen
- Intelligente Behandlung leerer/0€-Bestellungen

### Queue-System

- **Asynchrone Verarbeitung** für große Datenmengen
- **Batch-Processing** (10 Einträge pro Durchlauf)
- **Automatische Wiederholung** bei ausstehenden Einträgen
- **WP-Cron Fallback** für zuverlässige Verarbeitung

### Benutzeroberfläche

- **Bootstrap 5** Design
- **Responsive Tabellen** für alle Bildschirmgrößen
- **Bulk-Operationen** mit Checkbox-Auswahl
- **Real-time Status** Updates via AJAX

## Installation

1. Plugin-Dateien in `/wp-content/plugins/pqw-ordermanagement/` hochladen
2. Plugin im WordPress Admin-Bereich aktivieren
3. WooCommerce muss installiert und aktiviert sein
4. Berechtigung: `manage_woocommerce` oder `manage_options`

## Technische Details

### Datenbank-Tabellen

- `wp_pqw_order_queue` - Queue für Aufteilungsoperationen
- `wp_pqw_order_complete_queue` - Queue für Abschlussoperationen

### AJAX-Endpunkte

- `pqw_queue_status` - Status der Aufteilungs-Queue
- `pqw_process_queue_async` - Trigger für asynchrone Verarbeitung
- `pqw_complete_queue_status` - Status der Abschluss-Queue  
- `pqw_process_complete_queue_async` - Trigger für Abschluss-Verarbeitung

### Cron-Jobs

- `pqw_process_queue` - Verarbeitung der Aufteilungs-Queue
- `pqw_process_complete_queue` - Verarbeitung der Abschluss-Queue
- `pqw_cleanup_queue` - Tägliche Bereinigung alter Queue-Einträge (7+ Tage)

### WordPress Hooks

- `admin_menu` - Registrierung der Admin-Menüs
- `admin_enqueue_scripts` - Laden von Bootstrap CSS
- `wp_ajax_*` - AJAX-Handler für asynchrone Verarbeitung

## Verwendung

### Admin-Menü

Navigate zu **PQW Orders** im WordPress Admin:

1. **Bestellung aufteilen - Name**: Alle Bestellungen ausgewählter Kunden aufteilen
2. **Bestellung aufteilen - Artikel**: Einzelne Artikel in separate Bestellungen aufteilen  
3. **Bestellung abschließen - Name**: Alle Bestellungen ausgewählter Kunden abschließen
4. **Bestellung abschließen - Artikel**: Artikel-Bestellungen abschließen

### Workflow

1. Kunden über Checkboxes auswählen
2. Aktion per Button ausführen
3. Bei >1 Kunde: Queue-basierte Verarbeitung
4. Bei 1 Kunde: Sofortige Verarbeitung
5. Status-Updates in Echtzeit

## Konfiguration

### Batch-Größe

Standard: 20 Einträge pro Durchlauf (anpassbar in `pqw_process_queue_handler()`)

### Cleanup-Intervall

Standard: Täglich, löscht Queue-Einträge älter als 7 Tage

### Berechtigugen

- `manage_woocommerce` - Vollzugriff auf alle Funktionen
- `manage_options` - Alternative Berechtigung für Administratoren

## Sicherheit

- **Nonce-Verification** für alle Formulare
- **Capability-Checks** für alle Aktionen
- **Input-Sanitization** für alle Benutzereingaben
- **SQL-Prepared-Statements** für Datenbankzugriffe

## Performance

- **Non-blocking AJAX** für Queue-Verarbeitung
- **Batch-Processing** verhindert Timeouts
- **Automatische Bereinigung** verhindert Datenbank-Aufblähung
- **Conditional Loading** von CSS/JS nur auf Plugin-Seiten

## Debugging

Queue-Status prüfen:

```sql
SELECT * FROM wp_pqw_order_queue WHERE status = 'pending';
SELECT * FROM wp_pqw_order_complete_queue WHERE status = 'pending';
```

Cron-Jobs prüfen:

```php
wp_next_scheduled('pqw_process_queue');
wp_next_scheduled('pqw_cleanup_queue');
```

## Lizenz

Dieses Plugin wird unter der GPL v2+ Lizenz bereitgestellt.

## Abhängigkeiten

- **WordPress** 5.0+
- **WooCommerce** 3.0+
- **PHP** 7.4+
- **Bootstrap** (gebundelt)
- **xlsx-sheetjs** (gebundelt)

## Beitrag

Bei Fehlern oder Verbesserungsvorschlägen bitte Issue erstellen oder Pull Request einreichen.
