# Konzept: Buchungshistorie im Plugin Mitgliedsbeitrag

Stand: 30.10.2026 · umgesetzt in Version 6.1.0, SEPA-Dateien speichern und teilen in Version 6.2.0

Jede Beitragsforderung wird als **Buchungsposition je Mitglied, Beitragsrolle und Beitragsjahr** in einer eigenen Tabelle gespeichert. Diese Tabelle ist zugleich die Historie und die Grundlage für eine Neuberechnung, die bereits eingezogene Rollen überspringt. Die bisherigen Profilfelder (Beitrag, Beitragstext, Fälligkeitsdatum, Bezahlt, Sequenztyp) bleiben als Arbeitsfelder für den laufenden Einzug erhalten; SEPA-Export, Vorabinformation und Rechnung lesen sie wie bisher.

## Ziel und Anforderungen

- Buchungshistorie je Mitglied als Tabelle: Einzugsdatum, Fälligkeitsdatum, Einzug (SEPA/MAN), SEPA-Datei, Beitragsrolle, Betrag, Buchungstext, Referenz, Status.
- Die Historie bleibt über Jahre erhalten; das Löschen am Jahresende leert nur die Arbeitsfelder.
- Die Neuberechnung berücksichtigt nur Rollen, für die im Beitragsjahr noch kein Einzug erfolgt ist.
- Globale Einzugsübersicht: eine ganze SEPA-Datei mit einem Klick als eingezogen markieren.
- Rücklastschriften: Zeile in der Historie stornieren, optional mit Rollenentzug und Gebühr.

## Die frühere History (system/history.php)

Sie las das Änderungsprotokoll der Profilfelder (`adm_user_log`) und gab je Bezahlt-Datum eine Zeile aus. Admidio 5 hat diese Tabelle durch `adm_log_changes` ersetzt, `TBL_USER_LOG` gibt es nicht mehr. Sie kannte außerdem keine Aufteilung nach Rollen, keine Referenz und keine Stornos. `history.php` leitet jetzt auf die Buchungsübersicht weiter.

## Datenmodell

### Tabelle `<prefix>_plugin_membershipfee_bookings` (eine Zeile je Position)

| Spalte | Inhalt |
| --- | --- |
| `mfb_id` | laufende Nummer |
| `mfb_org_id`, `mfb_usr_id`, `mfb_year` | Organisation, Mitglied, Beitragsjahr |
| `mfb_source` | `rolle` (Neuberechnung, auch Differenz einer Beitragsgruppe), `individual` (Individualbeitrag), `manuell` |
| `mfb_rol_id`, `mfb_rol_name` | Beitragsrolle bzw. Bezeichnung (Name bleibt lesbar, wenn die Rolle umbenannt/gelöscht wird) |
| `mfb_amount`, `mfb_text` | Betrag (negativ = Gutschrift), Buchungstext (max. 140 Zeichen) |
| `mfb_status` | `offen`, `exportiert`, `eingezogen`, `storniert` |
| `mfb_method` | `SEPA` oder `MAN` |
| `mfb_run_id` | Einzug (SEPA-Datei) |
| `mfb_due_date`, `mfb_booking_date` | Fälligkeitsdatum beim Export, Einzugsdatum (bei MAN Bezahlt-Datum) |
| `mfb_reference` | EndToEnd-ID bzw. freie Angabe bei MAN |
| `mfb_mandate_id`, `mfb_sequence_type` | Mandatsreferenz und Sequenztyp beim Export |
| `mfb_orig_mandate_id`, `mfb_orig_iban`, `mfb_orig_debtor_agent` | Mandatsänderungsdaten beim Export (für „Datum löschen“) |
| `mfb_storno_date`, `mfb_storno_reason`, `mfb_role_revoked` | Storno; `mfb_role_revoked = 1`: Rolle entzogen, keine Neuberechnung dieser Rolle im Jahr |
| `mfb_comment` | Notiz |
| `mfb_import_ref` | Herkunft importierter Positionen (ab 6.3.0), z. B. `WINNER-1140/1200` (Soll-/Haben-ID aus dem Winner-Buchungsjournal); importierte Einzüge haben `mfr_format = import` |
| `mfb_usr_id_create/change`, `mfb_timestamp_create/change` | Protokoll |

### Tabelle `<prefix>_plugin_membershipfee_runs` (eine Zeile je SEPA-Datei)

`mfr_file_name`, `mfr_msg_id` (je Datei eindeutig: `MF-<Kürzel>-<id>-<Zeitstempel>`), `mfr_format`, `mfr_due_dates`, `mfr_count`, `mfr_amount`, `mfr_status` (`erstellt`, `eingezogen`, `verworfen`), `mfr_paid_date`.

### Tabelle `<prefix>_plugin_membershipfee_files` (gespeicherte SEPA-Datei je Einzug, ab 6.2.0)

`mff_run_id`, `mff_kind` (`xml` = SEPA-Datei, `ctl` = Kontrolldatei, Base64-kodiert), `mff_year` (Beitragsjahr), `mff_file_name`, `mff_content` (XML, MySQL `mediumtext`), `mff_size`, Teilen: `mff_share_hash` (SHA-256 des Tokens, das Token selbst wird nicht gespeichert), `mff_share_expires`, `mff_share_email`, `mff_share_usr_id`, `mff_share_timestamp`, Abrufe: `mff_downloads`, `mff_last_download`.

Die Tabellen werden beim Aufruf des Plugins automatisch angelegt (MySQL/MariaDB und PostgreSQL) und bei der Deinstallation mit entfernt.

## Lebenszyklus einer Position

offen → exportiert (SEPA-Datei) → eingezogen; jederzeit storniert. Ohne SEPA-Datei: offen → eingezogen mit Einzug MAN.

| Aktion | Positionen und Einzüge | Profilfelder |
| --- | --- | --- |
| Neuberechnung | offene Positionen anlegen, aktualisieren, löschen | Beitrag, Beitragstext; ggf. Bezahlt leeren |
| SEPA-Export (XML) | neuer Einzug; offen → exportiert mit Fälligkeit, EndToEnd-ID, Mandat, Sequenztyp | keine |
| Einzug als eingezogen markieren | exportiert → eingezogen | wie „Datum speichern“ |
| Datum speichern (Beitragszahlungen) | exportiert → eingezogen; ohne Export offen → eingezogen (MAN) | Bezahlt, RCUR, Fälligkeit leeren |
| Datum löschen (je Mitglied) | eingezogen → exportiert bzw. offen | Bezahlt leer; Fälligkeit, Sequenztyp, Mandatsänderung zurück |
| Einzug verwerfen | exportiert → offen; Einzug verworfen | keine |
| Stornieren | → storniert (optional ganze Lastschrift, Rollenentzug, Gebühr) | Beitrag neu; bei laufendem Einzug Fälligkeit leeren; zurückgegangene Erstlastschrift → wieder FRST |
| Löschen am Jahresende | offene Positionen gelöschter Beiträge entfallen, Historie bleibt | wie bisher |

## Neuberechnung

Je Mitglied und Beitragsrolle (mindestens ein Tag Mitglied im Beitragsjahr):

| Vorhandene Position | Aktion |
| --- | --- |
| keine | neue Position „offen“ |
| offen | auf aktuellen Rollenbeitrag bringen |
| exportiert/eingezogen | überspringen |
| storniert ohne Rollenentzug | neue Position „offen“ |
| storniert mit Rollenentzug | überspringen |

- Mitglieder mit laufendem Einzug (Position „exportiert“) werden übersprungen.
- Offene Rollenpositionen ohne Rollenmitgliedschaft entfallen.
- Manuelle Positionen und Individualbeiträge werden nicht verändert.
- Positionen mit 0 € entfallen; Mindestbetrag gilt für die Summe der neu berechneten Rollenpositionen; „Abrunden“ je Position.
- Die Modi „Überschreiben“ und „Aufsummieren“ wurden entfernt.

### Beitragsgruppen mit Differenzberechnung

Einstellung: Name + Beitragsrollen je Gruppe (eine Rolle nur in einer Gruppe). Gespeichert im Abschnitt `Beitragsgruppen` (`name`, `roles` als Komma-Liste der rol_ids).

Soll = höchster Rollenbeitrag der Gruppe im Jahr (entzogene Rollen zählen nicht); Gebucht = exportierte + eingezogene Positionen der Gruppe im Jahr; nur eine positive Differenz wird offene Position („… (Differenz zu Passiv)“). Beispiel: Passiv 50 € bezahlt, Wechsel nach Aktiv 310 € → offene Position 260 €.

### Manuelle Positionen

Rücklastschriftgebühren und Kleinbeträge: „Position hinzufügen“ in der Historie, für markierte Mitglieder in den Beitragszahlungen oder aus dem Storno-Dialog. Vorlagen im Abschnitt `Positionsvorlagen` (`name`, `amount`, `text`). Offene manuelle Positionen sind bearbeitbar/löschbar, danach nur stornierbar. Die Rechnung hat den Filter „Offene manuelle Positionen (auch mit IBAN)“.

### Abweichungen im Profilfeld Beitrag

Wird das Profilfeld Beitrag von Hand geändert, legt der SEPA-Export bzw. „Datum speichern“ automatisch eine manuelle Position „Korrektur Profilfeld Beitrag“ (bzw. „Beitrag (Profilfeld)“ ohne Positionen) über die Differenz an. So zeigt die Historie immer genau den eingezogenen Betrag.

## Anzeige

- `system/bookings.php?user_uuid=…`: Historie eines Mitglieds mit Aktionen (Stornieren, Referenz/Einzugsdatum bei MAN, manuelle Position bearbeiten/löschen, Position hinzufügen).
- `system/booking_overview.php`: Buchungsübersicht mit Filtern und Export (XLSX, CSV).
- `system/runs.php`: Einzugsübersicht, Detail je SEPA-Datei mit Markieren und Verwerfen.
- Registerkarte „Beiträge“ im Profil: Einstellungen → Registerkarte im Mitgliedsprofil (Administratoren). Ergänzt wie Awards `modules/profile/profile.php` und `profile.view.tpl`, mit eigenen Markierungen (`PMB-BOOKINGS-ADDIN`), damit Ergänzungen anderer Plugins erhalten bleiben. Nach einem Admidio-Update erneut einbauen. Sichtbar nur für Mitglieder der Zugriffsrolle des Plugins.

## SEPA-Datei speichern und teilen (ab 6.2.0)

- Beim Erzeugen der XML-Datei werden ihr Inhalt und die passende Kontrolldatei (Excel bzw. CSV laut Einstellung, mit der Message-ID des Einzugs) beim Einzug gespeichert. Ein erneuter Export desselben Einzugs ändert die gespeicherte Datei nicht, damit immer die zuerst erzeugte (an die Bank gegebene) Datei erhalten bleibt.
- Aufbewahrung: nur das aktuelle Beitragsjahr. Beim Export werden Dateien früherer Beitragsjahre gelöscht; die Einzüge und Positionen bleiben. Beim Verwerfen eines Einzugs wird seine Datei gelöscht.
- Einzugsübersicht: Download-Symbol in der Liste, im Detail „Herunterladen“, „Teilen“, „Link widerrufen“ und die Zahl der Abrufe.
- Teilen: zufälliges Token (32 Byte, hex), in der Datenbank nur der Hash; Link `system/sepa_download.php?token=…` (Kontrolldatei: `&file=ctl`, gleicher Token) funktioniert ohne Anmeldung bis zum Ablauf (Standard 14 Tage, 1–365). Ein neuer Link ersetzt den alten. Ungültige und abgelaufene Links liefern dieselbe Meldung (HTTP 404).
- E-Mail beim Klick auf „Link erzeugen und senden“ an die feste Adresse aus Einstellungen → Export → SEPA-Datei teilen (vorbelegt, im Dialog änderbar, mehrere durch Komma). Absender ist der angemeldete Benutzer (bzw. die Systemadresse laut Admidio-Einstellungen). Schlägt der Versand fehl, bleibt der Link gültig und wird zum Kopieren angezeigt.

## Entscheidungen

- Keine Migration: Mit dem Plugin wurde noch kein Beitrag eingezogen.
- Positionen werden schon beim SEPA-Export auf „exportiert“ gesetzt.
- EndToEnd-ID = Vereinskürzel-usr_id-Fälligkeitsdatum (max. 35 Zeichen); ein erneuter Export erzeugt dieselbe Referenz und keinen neuen Einzug.
- Rücklastschriftgebühr = manuelle Position aus einer Vorlage.
- „Datum löschen“ setzt je Mitglied zurück; ein Rückgängig für eine ganze SEPA-Datei gibt es nicht.
- Die Historie sehen nur Mitglieder der Plugin-Zugriffsrolle.

## Code-Übersicht

| Datei | Inhalt |
| --- | --- |
| `classes/Bookings/BookingStore.php` | Tabellen anlegen, Lesen/Schreiben von Positionen und Einzügen |
| `classes/Bookings/ContributionPlanner.php` | Rechenlogik der Neuberechnung ohne Datenbank (Regeln oben) |
| `classes/Bookings/BookingService.php` | Abläufe: Neuberechnung anwenden, Profilfelder abgleichen, SEPA-Export registrieren, eingezogen/zurücksetzen, Storno, manuelle Positionen |
| `classes/Bookings/ProfileAddin.php` | Registerkarte im Profil ein-/ausbauen |
| `system/recalculation.php`, `individualcontributions.php` | Voransicht und Speichern auf Basis von Positionen |
| `system/sepa_export*.php` | Einzug anlegen, Message-ID, EndToEnd-ID |
| `system/payments.php` | Datum speichern/löschen mit Positionen, Historien-Link, Position hinzufügen |
| `system/bookings.php`, `booking_edit.php`, `booking_overview.php`, `runs.php`, `bookings_function.php` | neue Seiten |
| `system/profile_addin.php`, `profile_addin_install.php` | Registerkarte im Profil |
| `system/sepa_download.php` | öffentlicher Download einer geteilten SEPA-Datei (Token-Link) |

## Datenübernahme aus Winner (ab 6.3.0)

Das Buchungsjournal (BJOURNAL) wird im Plugin **winner-import** übernommen (`journal.php`, Menü „Buchungsjournal“). Es nutzt `BookingStore::insertImported()`, `insertImportedRun()`, `getImportRefs()` und `deleteImported()`. Rollenpositionen werden mit Quelle `rolle` angelegt, damit die Neuberechnung bereits eingezogene Beiträge überspringt. Die Profilfelder bleiben unverändert.

## Ideen für spätere Erweiterungen

- Rücklastschriften aus einer Bankdatei (camt.054) einlesen und automatisch stornieren.
- Kontoauszug (camt.053) abgleichen und Überweisungen (MAN) automatisch als eingezogen markieren.
- Anteilige (monatsgenaue) Beiträge als zusätzliche Regel im Planer.
- Erstattung bei Wechsel in eine günstigere Rolle als Gutschrift-Position statt manuell.
- Historie für das Mitglied selbst (nur lesend) anzeigen, falls gewünscht.
- Rückgängig für einen ganzen Einzug.
- SEPA-Datei teilen: optional Kennwort zusätzlich zum Link oder Link nur einmal abrufbar.
- Mahnwesen: offene Positionen nach Alter, Mahnstufe als Status.
