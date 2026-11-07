# Datenstruktur des Plugins Mitgliedsbeitrag

Stand: Version 6.9.1 (06.11.2026). Beschreibt, welche Tabellen, Profilfelder, Einstellungen und Dateien das Plugin
anlegt und was darin steht.

Allgemeines:

- Tabellennamen beginnen mit dem Admidio-Präfix (`TABLE_PREFIX`, beim BFV `adm`), also z. B.
  `adm_plugin_membershipfee_bookings`.
- Die Tabellen entstehen beim ersten Zugriff (`ensureTables()` / `ensureTable()`), nicht bei der Installation. Neue
  Spalten ergänzt das Plugin bei Bedarf per `ALTER TABLE`.
- Alle Tabellen außer dem IBAN-Zwischenspeicher sind mandantenfähig: Spalte `…_org_id` = Organisation.
- Spalten `…_usr_id_create`, `…_timestamp_create`, `…_usr_id_change`, `…_timestamp_change` halten fest, wer einen
  Datensatz wann angelegt bzw. geändert hat. Sie werden unten nicht einzeln aufgeführt.
- **Buchungskreis**: `mf` = Mitgliedsbeitrag, `ad` = Arbeitsdienst (`CollectionScope`).
- Beträge sind `decimal(10,2)` in Euro, Datumswerte `date` (Y-m-d).

## Überblick

| Tabelle | Präfix | Inhalt | Klasse |
| --- | --- | --- | --- |
| `…_plugin_membershipfee_bookings` | `mfb_` | Buchungshistorie: jede Beitragsposition | `Bookings\BookingStore` |
| `…_plugin_membershipfee_runs` | `mfr_` | Einzüge (SEPA-Läufe, historische Läufe aus dem Import) | `Bookings\BookingStore` |
| `…_plugin_membershipfee_files` | `mff_` | SEPA-Dateien und Kontrolldateien eines Einzugs, Download-Links | `Bookings\BookingStore` |
| `…_plugin_membershipfee_invoices` | `mfi_` | Rechnungsköpfe | `Invoices\InvoiceStore` |
| `…_plugin_membershipfee_paymethods` | `mfm_` | abweichende Zahlart je Mitglied und Beitragsjahr | `Invoices\PaymentMethodStore` |
| `…_plugin_membershipfee_workhours` | `mfw_` | geleistete Arbeitsdienst-Stunden | `Work\WorkHoursStore` |
| `…_plugin_membershipfee_ibancache` | `mfi_` | Zwischenspeicher der IBAN-Prüfung (openiban.com) | `mf_iban_cache_table()` |
| `…_plugin_preferences` | `plp_` | Einstellungen des Plugins (gemeinsame Tabelle mehrerer Plugins) | `Config\ConfigTable` |

Beziehungen:

```text
adm_users ──< bookings >── runs ──< files
     │           │ mfb_invoice_no
     │           └──────────────> invoices (mfi_invoice_no)
     ├──< invoices
     ├──< paymethods
     └──< workhours
```

## `…_plugin_membershipfee_bookings` – Buchungshistorie

Eine Zeile je Beitragsposition eines Mitglieds. Die Positionen sind die Grundlage für Rechnungen, SEPA-Dateien,
Zahlungen und Auswertungen; die Profilfelder „Beitrag“ und „Bezahlt“ werden daraus abgeleitet.

| Spalte | Typ | Inhalt |
| --- | --- | --- |
| `mfb_id` | int, PK | laufende Nummer |
| `mfb_org_id` | int | Organisation |
| `mfb_usr_id` | int | Mitglied (`adm_users.usr_id`) |
| `mfb_year` | int | Beitragsjahr |
| `mfb_source` | varchar(10) | Herkunft: `rolle` (Neuberechnung), `individual` (Individualbeitrag), `manuell` (manuelle Position, Gebühr, Nachlass), `ad` (Arbeitsdienst-Abrechnung), `ad_manuell` (manuelle Position Arbeitsdienst) |
| `mfb_rol_id` | int, null | Beitragsrolle (bei `rolle`) |
| `mfb_rol_name` | varchar(100) | Bezeichnung, erscheint als Zeile auf der Rechnung (z. B. „Aktivbeitrag“, „Rücklastschriftgebühr“, „Nachlass auf …“) |
| `mfb_amount` | decimal | Betrag; negativ bei Gutschriften und Nachlässen |
| `mfb_text` | varchar(140) | Verwendungszweck |
| `mfb_status` | varchar(20) | `offen` → `exportiert` (in einer SEPA-Datei) → `eingezogen`; oder `storniert` |
| `mfb_method` | varchar(10), null | Zahlart beim Einzug: `SEPA` oder `MAN` (Überweisung, bar); leer solange offen |
| `mfb_run_id` | int, null | Einzug (`runs.mfr_id`), in dem die Position steht |
| `mfb_due_date` | date, null | Fälligkeit beim Export |
| `mfb_booking_date` | date, null | Buchungsdatum: Einzugs- bzw. Zahlungsdatum |
| `mfb_reference` | varchar(35), null | End-to-End-ID der Lastschrift bzw. Referenz der Zahlung |
| `mfb_mandate_id` | varchar(35), null | Mandatsreferenz beim Export |
| `mfb_sequence_type` | varchar(4), null | Sequenztyp beim Export: `FRST`, `RCUR`, `FNAL`, `OOFF` |
| `mfb_orig_mandate_id`, `mfb_orig_iban`, `mfb_orig_debtor_agent` | varchar, null | Mandatsänderung (SEPA-Amendment) beim Export |
| `mfb_storno_date` | date, null | Stornodatum |
| `mfb_storno_reason` | varchar(20), null | Stornogrund, z. B. `Rücklastschrift`, `Korrektur`, `Sonstiges`; `Nachlass <Grund>` = ganz erlassen (die Rechnung zeigt Betrag und Nachlass) |
| `mfb_role_revoked` | smallint | 1 = beim Storno wurde die Rolle entzogen (ältere Stornos) |
| `mfb_comment` | text, null | Notizen (Storno, Nachlass, Import) |
| `mfb_import_ref` | varchar(50), null | Herkunftskennung: `WINNER-<ID>` (Import des Buchungsjournals), `NACHLASS-<mfb_id>` (Nachlass zur Position mit dieser ID) |
| `mfb_invoice_no` | varchar(20), null | Rechnungsnummer, sobald die Position auf einer Rechnung steht; danach nicht mehr bearbeitbar |

Indizes: `(org_id, usr_id, year, rol_id)`, `(run_id)`, `(import_ref)`, `(org_id, invoice_no)`.

## `…_plugin_membershipfee_runs` – Einzüge

Ein Einzug fasst die Positionen zusammen, die gemeinsam exportiert wurden (eine oder mehrere SEPA-Dateien).

| Spalte | Typ | Inhalt |
| --- | --- | --- |
| `mfr_id` | int, PK | Nummer des Einzugs |
| `mfr_org_id` | int | Organisation |
| `mfr_kind` | varchar(10) | Buchungskreis `mf` / `ad` |
| `mfr_file_name` | varchar(255) | Dateiname (bei mehreren Dateien der erste) |
| `mfr_msg_id` | varchar(35) | SEPA-Nachrichten-ID (`AD-…` beim Arbeitsdienst, `WINNER-LS-…` bei historischen Läufen) |
| `mfr_format` | varchar(20) | SEPA-Format (pain-Version) oder `import` |
| `mfr_due_dates` | varchar(255) | enthaltene Fälligkeiten |
| `mfr_count` | int | Anzahl Lastschriften |
| `mfr_amount` | decimal | Summe |
| `mfr_status` | varchar(20) | `erstellt` → `eingezogen` oder `verworfen` |
| `mfr_paid_date` | date, null | Datum „Als eingezogen markieren“ |

## `…_plugin_membershipfee_files` – SEPA-Dateien

Die Dateien liegen in der Datenbank (nicht im Dateisystem), damit sie mit dem Backup gesichert sind.

| Spalte | Typ | Inhalt |
| --- | --- | --- |
| `mff_id` | int, PK | |
| `mff_org_id`, `mff_run_id` | int | Organisation, Einzug |
| `mff_kind` | varchar(10) | `xml` (SEPA-Datei) oder `ctl` (Kontrolldatei) |
| `mff_year` | int | Beitragsjahr |
| `mff_file_name` | varchar(255) | Dateiname beim Download |
| `mff_content` | text | Dateiinhalt |
| `mff_size` | int | Größe in Byte |
| `mff_share_hash` | varchar(64), null | Schlüssel des Download-Links „SEPA-Freigabe“ |
| `mff_share_expires` | timestamp, null | Ablauf des Links |
| `mff_share_email`, `mff_share_usr_id`, `mff_share_timestamp` | | an wen, von wem, wann freigegeben |
| `mff_downloads`, `mff_last_download` | | Anzahl und Zeitpunkt der Downloads |

## `…_plugin_membershipfee_invoices` – Rechnungen

Der Rechnungskopf. Die Positionen einer Rechnung sind die Buchungen mit derselben `mfb_invoice_no`. Der **Status wird
nicht gespeichert**, sondern aus den Positionen berechnet: `offen`, `im_einzug` (Positionen exportiert), `bezahlt`,
`storniert`; dazu „überfällig“ nach dem Fälligkeitsdatum.

| Spalte | Typ | Inhalt |
| --- | --- | --- |
| `mfi_id` | int, PK | |
| `mfi_org_id`, `mfi_usr_id` | int | Organisation, Mitglied |
| `mfi_year` | int | Beitragsjahr |
| `mfi_kind` | varchar(2) | Buchungskreis `mf` / `ad` |
| `mfi_invoice_no` | varchar(20), unique je Organisation | Rechnungsnummer `JAHR-B-NNNN` (Beitrag) bzw. `JAHR-AD-NNNN` (Arbeitsdienst) |
| `mfi_seq` | int | laufende Nummer je Jahr und Buchungskreis |
| `mfi_invoice_date` | date | Rechnungsdatum |
| `mfi_due_date` | date, null | Einzugstag (SEPA) bzw. Zahlungsziel (Überweisung) |
| `mfi_amount` | decimal | Rechnungsbetrag: Summe der nicht stornierten Positionen, wird bei jedem neu erzeugten PDF aktualisiert |
| `mfi_method` | varchar(4) | `SEPA` oder `MAN` |
| `mfi_mandate_id` | varchar(35), null | Mandatsreferenz (nur SEPA) |
| `mfi_iban_masked` | varchar(34), null | IBAN maskiert, z. B. `DE67xxxx…3001` (nur SEPA) |
| `mfi_dunning_date`, `mfi_dunning_due` | date, null | Mahnung (vorbereitet) |
| `mfi_sent_mail`, `mfi_sent_link`, `mfi_sent_print` | timestamp, null | zuletzt per E-Mail, als Link bzw. gedruckt versandt (nicht im Testbetrieb) |
| `mfi_pdf_file` | varchar(100), null | Dateiname des PDF |
| `mfi_dunning_file` | varchar(100), null | PDF der Mahnung (vorbereitet) |
| `mfi_cancel_file` | varchar(100), null | PDF der stornierten Fassung |

Index: `(org_id, usr_id)`.

## `…_plugin_membershipfee_paymethods` – abweichende Zahlart

Entsteht durch „Verschieben“ in 2.0 / 2.1. Ohne Eintrag gilt die Zahlart aus dem Profil (IBAN und Mandatsreferenz
vorhanden = SEPA, sonst Überweisung). Gespeichert wird nur `MAN`; „nach SEPA-Einzug“ löscht den Eintrag.

| Spalte | Typ | Inhalt |
| --- | --- | --- |
| `mfm_id` | int, PK | |
| `mfm_org_id`, `mfm_usr_id` | int | Organisation, Mitglied |
| `mfm_year` | int | Beitragsjahr, für das die Abweichung gilt |
| `mfm_kind` | varchar(2) | Buchungskreis `mf` / `ad` |
| `mfm_method` | varchar(4) | `MAN` |

Eindeutig: `(org_id, usr_id, year, kind)`.

## `…_plugin_membershipfee_workhours` – Arbeitsdienst

| Spalte | Typ | Inhalt |
| --- | --- | --- |
| `mfw_id` | int, PK | |
| `mfw_org_id`, `mfw_usr_id` | int | Organisation, Mitglied |
| `mfw_year` | int | Leistungsjahr |
| `mfw_date` | date | Tag des Einsatzes |
| `mfw_hours` | decimal(6,2) | Stunden |
| `mfw_activity` | varchar(255) | Tätigkeit |
| `mfw_comment` | text, null | Notiz |

Index: `(org_id, year, usr_id)`. Die Sollstunden stehen im Profilfeld `ADTARGET<org>`.

## `…_plugin_membershipfee_ibancache` – IBAN-Prüfung

Zwischenspeicher für Abfragen bei openiban.com (30 Tage gültig). Ohne Organisation und **ohne IBAN im Klartext**.

| Spalte | Typ | Inhalt |
| --- | --- | --- |
| `mfi_hash` | char(64), PK | SHA-256 der IBAN |
| `mfi_valid` | tinyint | 1 = gültig |
| `mfi_bic`, `mfi_bank` | varchar | BIC und Bankname laut Abfrage |
| `mfi_checked` | datetime | Zeitpunkt der Abfrage |

## `…_plugin_preferences` – Einstellungen

Gemeinsame Tabelle der Admidio-Plugins: `plp_id`, `plp_org_id`, `plp_name`, `plp_value`. Das Plugin speichert seine
Einstellungen als `plp_name = 'PMB__<Bereich>__<Schlüssel>'`, Listen als Zeichenkette mit Trennzeichen. Standardwerte
in `system/configdata.php`. Bereiche:

| Bereich | Inhalt |
| --- | --- |
| `Beitrag`, `Beitragsgruppen` | Beitragsberechnung, Rollen mit Differenzberechnung |
| `individual_contributions` | Individualbeiträge |
| `Arbeitsdienst` | Stundensatz, Rollen, Regeln |
| `Positionsvorlagen` | Vorlagen für manuelle Positionen (z. B. Rücklastschriftgebühr) |
| `Kontodaten` | Bankverbindung des Vereins, Gläubiger-ID |
| `Mandatsreferenz` | Aufbau der Mandatsreferenz |
| `Rollenpruefung`, `tests_enable` | Prüfungen unter 1.0 |
| `Rechnung` | Zahlungsziel in Tagen |
| `Rechnungs-Export` | Dateiname und Dateityp des Exports |
| `SEPA`, `SEPA-Freigabe` | SEPA-Format, Zusammenfassen der Sequenztypen, Download-Links |
| `Testbetrieb` | Testempfänger für alle E-Mails |
| `columnconfig` | Spalten der Listen |
| `access`, `install` | Zugriffsrolle, Menüeintrag |
| `Plugininformationen` | installierte Version und Stand |

## Admidio-Tabellen, die das Plugin mitbenutzt

### Profilfelder (`adm_user_fields`, Werte in `adm_user_data`)

Legt das Plugin bei der Installation an (`system/install_db.php`, `Work\WorkProfileFields`). `<org>` = Organisations-ID,
beim BFV `1`.

| Kategorie | Felder (`usf_name_intern`) |
| --- | --- |
| Mitgliedschaft (`MEMBERSHIP<org>`) | `MEMBERNUMBER<org>` Mitgliedsnummer, `ACCESSION<org>` Beitritt |
| Mitgliedsbeitrag (`MEMBERSHIP_FEE<org>`) | `FEE<org>` Beitrag (Summe offener Positionen), `CONTRIBUTORY_TEXT<org>` Beitragstext, `PAID<org>` Bezahlt, `DUEDATE<org>` Fälligkeit (Einzugstag), `SEQUENCETYPE<org>` Sequenztyp |
| Mandat (`MANDATE<org>`) | `MANDATEID<org>` Mandatsreferenz, `MANDATEDATE<org>` Mandatsdatum, `ORIG_MANDATEID<org>` alte Mandatsreferenz |
| Kontodaten (`ACCOUNT_DATA`) | `IBAN`, `BIC`, `BANK`, `DEBTOR` Kontoinhaber, `DEBTOR_STREET`, `DEBTOR_POSTCODE`, `DEBTOR_CITY`, `DEBTOR_EMAIL`, `ORIG_IBAN`, `ORIG_DEBTOR_AGENT` |
| Arbeitsdienst (`WORK_SERVICE<org>`) | `ADTARGET<org>` Sollstunden, `ADFEE<org>`, `ADTEXT<org>`, `ADDUEDATE<org>`, `ADSEQUENCETYPE<org>`, `ADPAID<org>` (Gegenstücke zu Beitrag, Text, Fälligkeit, Sequenztyp, Bezahlt) |

Die Felder `FEE`, `PAID` (bzw. `ADFEE`, `ADPAID`) gleicht das Plugin mit der Buchungshistorie ab. `DUEDATE` und
`SEQUENCETYPE` setzt „Rechnungen erstellen“ (Einzugsdatum), „Erneut einziehen“ beim Storno oder „Verschieben“.

### E-Mail-Vorlagen (`adm_texts`)

Je Organisation, bearbeitbar unter Einstellungen → E-Mail-Benachrichtigungen. Ohne Eintrag gilt der Text aus
`languages/de.xml` (`PLG_MEMBERSHIPFEE_<Name>`).

| `txt_name` | Verwendung |
| --- | --- |
| `PMBMAIL_PRE_NOTIFICATION` / `…_AD` | Vorabinformation (2.1, Tabelle SEPA-Einzug) |
| `PMBMAIL_BILL` / `…_AD` | Rechnungs-E-Mail (2.1, Tabelle Überweisung) |
| `PMBMAIL_CONTRIBUTION_PAYMENTS` | Zahlungsbestätigung (3.1) |

### Rollen und Mitgliedschaften

Beitragsrollen sind normale Admidio-Rollen (`adm_roles`) mit Beitrag (`rol_cost`) und Beitragszeitraum
(`rol_cost_period`, `-1` = einmalig). Das Plugin liest sie bei der Neuberechnung und ändert sie nur über die
erweiterte Rollenbearbeitung bzw. die Prüfung „einmalige Rollen“ (Ende der Mitgliedschaft in `adm_members`).

## Dateien außerhalb der Datenbank

| Ort | Inhalt |
| --- | --- |
| `adm_my_files/plugins/wp-tc-lib-pdf/config.php` | Konfiguration der PDF-Bibliothek (Secret für signierte Links, Vereinsdaten); Rechte 600 |
| `adm_my_files/plugins/wp-tc-lib-pdf/beitrag/` | Rechnungs-PDFs Mitgliedsbeitrag: `rechnung_<nr>.pdf`, `rechnung_<nr>_storniert.pdf` |
| `adm_my_files/plugins/wp-tc-lib-pdf/arbeitsdienst/` | Rechnungs-PDFs Arbeitsdienst |

`adm_my_files` ist per `.htaccess` gesperrt. Die PDFs liefert `system/invoice_file.php` an die Zugriffsrolle aus, nach
außen nur über signierte Links (`pdf.php` der Bibliothek).

## Löschen und Deinstallation

- **Einstellungen → Löschen** (Jahreswechsel): setzt Profilfelder zurück; offene Positionen mit Rechnung werden
  storniert statt gelöscht.
- **Deinstallation** (`system/uninstall.php`): löscht wahlweise die Daten der aktuellen Organisation aus den
  Plugin-Tabellen (`removeDataOfCurrentOrganization()`: Buchungen, Einzüge, Dateien, Rechnungen, Zahlarten,
  Arbeitsdienst; leere Tabellen werden entfernt), die Einstellungen und die Profilfelder. Nicht gelöscht werden der
  IBAN-Zwischenspeicher, die E-Mail-Vorlagen in `adm_texts` und die PDFs in `adm_my_files`.
- Das Backup-Plugin löscht beim Einspielen in die Backup-Instanz alle Tabellen mit dem Admidio-Präfix, also auch die
  Plugin-Tabellen; die PDFs in `adm_my_files` bleiben liegen und gehören dann zu nicht mehr vorhandenen Rechnungen.
