# Konzept: Rechnungen als PDF im Plugin Mitgliedsbeitrag (3.0 Rechnungen erstellen)

Stand 05.11.2026 · abgestimmt · P1–P5 und P7 umgesetzt (Version 6.5.0, Abschnitt 15)

Dieses Konzept beschreibt die Seite des Plugins **Mitgliedsbeitrag** (Server-Ordner `MembershipFee`). Es baut auf
`KONZEPT-ADMIDIO-RECHNUNGEN.md` und `KONZEPT-PLATTFORM.md` im Repo **wp-tc-lib-pdf** auf und übernimmt deren
Entscheidungen. Wo es davon abweicht, steht das ausdrücklich in Abschnitt 12 („Rückwirkung auf wp-tc-lib-pdf“).

Grundlage ist der aktuelle Stand des Plugins (nach dem Menü-Umbau, Storno mit „Erneut einziehen“ und ohne
„Rolle entziehen“), nicht mehr der Stand `a693cb1`, gegen den das Bibliothekskonzept geprüft wurde.

Alle Vorschläge wurden am 05.11.2026 übernommen. Die Markierung **[Vorschlag]** zeigt nur noch, wo das Konzept über
das Bibliothekskonzept hinausgeht oder davon abweicht. Alle Fragen sind entschieden.

---

## 1. Ziel und Abgrenzung

- Unter **3.0 Rechnungen erstellen** (bisher 4.1) entstehen Rechnungen mit fester Rechnungsnummer, je Mitglied, Beitragsjahr
  und Buchungskreis (Mitgliedsbeitrag `mf` / Arbeitsdienst `ad`).
- Das PDF erzeugt die Bibliothek **wp-tc-lib-pdf**. Das Plugin vergibt Nummern, speichert den Rechnungskopf,
  liefert die Daten für das PDF, versendet und protokolliert.
- Versand: **Mail mit PDF-Anhang**, **signierter Link** in der Mail, **Druckstapel** (ZIP oder ein Druck-PDF),
  **Download im Profil**.
- **Eine Mahnstufe**, Mahngebühr als manuelle Position.
- Ohne installierte Bibliothek (oder ohne PHP 8.5) funktionieren Nummernvergabe, Liste und CSV/XLSX-Export
  weiter; alle PDF-Aktionen sind dann ausgeblendet.

Nicht Teil dieses Konzepts: Gutschriften (gibt es nicht), mehrere Mahnstufen, Inkasso, die WordPress-Seite.

## 2. Ist-Stand des Plugins (geprüft)

| Bereich | Stand | Bedeutung für die Rechnungen |
|---|---|---|
| Buchungspositionen (`BookingStore`) | Status `offen` / `exportiert` / `eingezogen` / `storniert`, Quelle, Rolle, Jahr, Fälligkeit, SEPA-Daten | Grundlage der Rechnung |
| Buchungskreise (`CollectionScope`) | `mf`: rolle, individual, manuell · `ad`: ad, ad_manuell | getrennte Nummernkreise B / AD |
| 4.1 Rechnungen erstellen (`bill.php`, künftig 3.0) | Liste der Mitglieder mit offenen Positionen, Filter E-Mail ja/nein, Markierung (Session `checkedArray`), CSV/XLSX-Export (`bill_export.php`), Massenmail | wird zur Rechnungsseite erweitert |
| Mail (`message_write.php` / `message_send.php`) | Vorlage `PMBMAIL_BILL` für Rechnungen, Platzhalter über `replace_emailparameter()`, Upload-Anhänge | Anhang und Platzhalter ergänzen |
| Storno (`BookingService::cancel`) | Einzelauswahl der Positionen einer Lastschrift, „Erneut einziehen“ legt neue offene Position an, Zusatzposition aus Vorlage | Nummer weitergeben (4.6) |
| Profil (`ProfileAddin`) | Karten „Beiträge“ (Zugriffsrolle) und „Arbeitsdienst“ (auch das Mitglied selbst) | Rechnungsliste (Abschnitt 9) |
| Neuberechnung (`ContributionPlanner::planMember`) | wertet `rol_cost_period` **nicht** aus | bleibt so; einmalige Rollen enden am 31.12. (Abschnitt 3) |
| 1.0 Prüfungen (`tests.php`) | Rollenpflicht/-ausschluss, Kontodaten, Mandate, IBAN, BIC; OpenIBAN-Korrektur per Button | neue Prüfung „Einmalige Beitragsrollen“ |
| Einstellungen (`ConfigTable`) | Abschnitte `Kontodaten` (inhaber, iban, bic, bank, ci), `Rechnungs-Export` | neuer Abschnitt `Rechnung` |
| Server | Ordner `adm_plugins/MembershipFee`; PDF-Bibliothek verlangt PHP ≥ 8.5 | Pfad konfigurierbar, Versionsprüfung |

## 3. Voraussetzung: einmalige Rollen nur einmal berechnen

Aufnahmegebühr und Erstbesatz sind Rollen mit Beitragszeitraum „einmalig“ (`rol_cost_period = -1`). Die
Neuberechnung legt eine Rolle in jedem Beitragsjahr an, in dem das Mitglied ihr angehört.

**Entschieden (05.11.2026): organisatorisch.** Die Mitgliedschaft in einer einmaligen Beitragsrolle endet immer am
**31.12. des Jahres, in dem sie beginnt**. Damit fällt die Rolle nur im Aufnahmejahr in die Neuberechnung. Der Planer
bleibt unverändert.

Abgesichert wird das durch eine neue Prüfung unter **1.0 Prüfungen**:

- **„Einmalige Beitragsrollen“** (Schalter `tests_enable['onetime_roles']`, Standard an, wie die übrigen Prüfungen).
- Gemeldet wird jede Mitgliedschaft in einer Beitragsrolle mit Zeitraum „einmalig“, deren Ende **nach** dem 31.12.
  des Beginnjahres liegt (also auch das offene Ende 9999-12-31). Ein früheres Ende (z. B. Austritt) ist in Ordnung.
- Anzeige: Mitglied, Rolle, Beginn, Ende, Soll-Ende (31.12. des Beginnjahres), Hinweis, wenn für die Rolle in einem
  späteren Jahr schon eine offene Position angelegt wurde.
- Behebung per Button für die ausgewählten Zeilen: **„Mitgliedschaft zum 31.12.&lt;Jahr&gt; beenden“** (wie die
  OpenIBAN-Korrektur: POST mit CSRF-Token, Rollenmitgliedschaft über `Role::setMembership()` mit gleichem Beginn).
  Bereits angelegte offene Positionen späterer Jahre entfernt die nächste Neuberechnung; die Prüfung weist darauf hin.
- Zusätzlich meldet die Prüfung offene oder eingezogene Positionen einer einmaligen Rolle in **mehr als einem Jahr**
  (Hinweis auf doppelt berechnete Aufnahmegebühr bzw. doppelten Erstbesatz aus der Vergangenheit).

**Entschieden (05.11.2026): Mitgliedschaften beginnen immer am 01.01.**, auch die Rollen der einmaligen Beiträge.
Eine einmalige Rolle läuft damit genau ein Kalenderjahr (01.01. bis 31.12.) und fällt in die Neuberechnung dieses
Beitragsjahres. Auch bei einer Aufnahme kurz vor dem Jahreswechsel (Beitragsjahr schon umgestellt) beginnt sie am
01.01. des neuen Jahres und wird dort berechnet.

Die Prüfung meldet deshalb zusätzlich einmalige Rollen, deren Beginn **nicht** der 01.01. ist (nur Hinweis, ohne
Button: Beginn und Ende von Hand korrigieren, damit das richtige Beitragsjahr getroffen wird).

Das Anschreiben der Rechnung erkennt eine Aufnahme daran, dass eine Position zu einer einmaligen Rolle gehört
(Abschnitt 11).

## 4. Datenmodell

### 4.1 Neue Spalte an den Positionen

| Spalte | Typ | Inhalt |
|---|---|---|
| `mfb_invoice_no` | varchar(20) NULL | Rechnungsnummer, z. B. `2026-B-0012`, `2026-AD-0003` |

- Index (`mfb_org_id`, `mfb_invoice_no`), Aufnahme in `BOOKING_COLUMNS`.
- `BookingStore::search()` bekommt den Filter `invoice_no`.
- Anlegen der Spalte wie die bisherigen Erweiterungen über `ensureTables()` (Spalte prüfen, `ALTER TABLE`).

### 4.2 Neue Tabelle Rechnungskopf `<prefix>_plugin_membershipfee_invoices`

| Spalte | Typ | Inhalt |
|---|---|---|
| `mfi_id` | int, PK | |
| `mfi_org_id` | int | Organisation |
| `mfi_usr_id` | int | Mitglied |
| `mfi_year` | int | Beitrags- bzw. Leistungsjahr (`mfb_year`) |
| `mfi_kind` | varchar(2) | `mf` / `ad` |
| `mfi_invoice_no` | varchar(20) | **unique** je Organisation |
| `mfi_seq` | int | Laufnummer (für die Vergabe, siehe 4.3) |
| `mfi_invoice_date` | date | Rechnungsdatum |
| `mfi_due_date` | date | Zahlungsziel |
| `mfi_amount` | decimal(10,2) | Summe bei Erstellung (bleibt fest; das PDF zeigt diesen Stand) |
| `mfi_method` | varchar(4) | `SEPA` / `MAN` bei Erstellung |
| `mfi_mandate_id`, `mfi_iban_masked` | varchar | Mandat und verkürzte IBAN bei Erstellung (Text im PDF bleibt stabil) |
| `mfi_dunning_date`, `mfi_dunning_due` | date NULL | Mahnung (NULL = nicht gemahnt) |
| `mfi_sent_mail`, `mfi_sent_link`, `mfi_sent_print` | datetime NULL | letzter Versand je Weg |
| `mfi_pdf_file`, `mfi_dunning_file` | varchar(100) NULL | Dateinamen der abgelegten PDFs |
| `mfi_cancel_file` | varchar(100) NULL | Fassung mit Storno-Vermerk |
| `mfi_usr_id_create`, `mfi_timestamp_create` | | Protokoll |

Unique-Indizes: (`mfi_org_id`, `mfi_invoice_no`) und (`mfi_org_id`, `mfi_year`, `mfi_kind`, `mfi_seq`).
Löschen über `removeDataOfCurrentOrganization()` und `uninstall.php` (Abschnitt 13: Aufbewahrung beachten).

**Abweichung vom Bibliothekskonzept:** kein gespeichertes `mfi_status` **[Vorschlag]**. Der Status wird beim Lesen
aus den Positionen berechnet (4.4). Dadurch muss nicht jede Stelle, die Positionen ändert (Einzug markieren,
Bezahlt zurücknehmen, Storno, Einzug verwerfen, Neuberechnung, Winner-Import), den Kopf mitpflegen.

### 4.3 Nummernvergabe

- Format: `JAHR-B-NNNN` (Mitgliedsbeitrag) bzw. `JAHR-AD-NNNN` (Arbeitsdienst), Jahr = Beitragsjahr der Positionen.
- Laufnummer = `MAX(mfi_seq)` für Organisation, Jahr und Kreis + 1, in einer Transaktion; der Unique-Index auf
  `mfi_seq` verhindert Doppelvergabe, bei Kollision neu lesen (höchstens 3 Versuche).
- Eine Nummer wird nie wiederverwendet.
- Prüfmuster überall gleich: `/^\d{4}-(B|AD)-\d{4}$/`, Dateinamen `/^(rechnung|mahnung)_\d{4}-(B|AD)-\d{4}(_storniert)?\.pdf$/`.
  (Das Muster im Bibliothekskonzept 5.3 `\d{4}-[A-Z]-\d{4}` passt nicht auf `AD` und ist dort zu korrigieren.)

### 4.4 Status einer Rechnung (berechnet)

| Positionen mit dieser Nummer | Status |
|---|---|
| alle `storniert` | `storniert` |
| alle nicht stornierten `eingezogen` | `bezahlt` |
| mindestens eine `exportiert`, keine `offen` | `im Einzug` |
| sonst | `offen` |
| zusätzlich: offen, Zahlungsziel überschritten, nicht gemahnt | `überfällig` (nur Anzeige/Filter) |

Eine SQL-Abfrage je Liste (GROUP BY `mfb_invoice_no` mit Zählern je Status), keine Einzelabfragen je Zeile.

## 5. Ablauf „Rechnungen erstellen“

### 5.1 Welche Positionen kommen auf eine Rechnung?

- Positionen des gewählten Kreises **ohne** `mfb_invoice_no` mit Status `offen` (wie im Bibliothekskonzept).
- **Entschieden (05.11.2026): Rechnungen werden vor der Vorabinformation erstellt.** Der Ablauf lautet damit
  Fälligkeit → Rechnungen → Vorabinformation → SEPA-Datei. Positionen, die schon in einer SEPA-Datei stehen,
  bekommen keine Rechnung mehr.
- Absicherung im Ablauf **[Vorschlag]**: Vorabinformation und SEPA-Datei zeigen einen Hinweis „N Mitglieder mit
  offenen Positionen ohne Rechnung“ mit Link auf die Rechnungsseite (nur wenn die PDF-Bibliothek aktiv ist; kein
  Sperren, damit Einzüge ohne Rechnung möglich bleiben).
- Gruppiert je Mitglied und Beitragsjahr: Hat ein Mitglied offene Positionen aus zwei Jahren, entstehen zwei
  Rechnungen.
- Summe ≤ 0,00 €: keine Rechnung, Meldung in der Ergebnisliste („Summe 0 oder negativ – bitte Positionen prüfen“).
- Idempotent: ein zweiter Lauf findet keine Positionen ohne Nummer mehr.

### 5.2 Kopfdaten

- Rechnungsdatum = heute in der Vereinszeitzone (`$gTimezone`).
- Zahlart: `SEPA`, wenn IBAN und Mandats-ID vorhanden sind (die SEPA-Erstellung nimmt jedes Mitglied mit IBAN,
  Beitrag und Fälligkeit), sonst `MAN`.
- **Entschieden (05.11.2026): Lastschrift-Rechnungen haben kein Zahlungsziel.** Sie kündigen den Einzug an
  („Der Betrag von … wird am <Fälligkeit> von Ihrem Konto … (Mandat …) per Lastschrift eingezogen. Eine Überweisung
  ist nicht notwendig.“). `mfi_due_date` speichert intern den Einzugstag = Profilfeld Fälligkeit des Kreises
  (`DUEDATE<org>` / `ADDUEDATE<org>`, gesetzt unter 2.0 Fälligkeit).
  Fehlt die Fälligkeit, warnt die Vorschau **[Vorschlag]**; die Rechnung nennt dann keinen Tag („wird zum nächsten
  Einzugstermin eingezogen“).
- Überweisungs-Rechnungen: Zahlungsziel = Profilfeld Fälligkeit des Kreises, sonst Rechnungsdatum + Einstellung
  „Zahlungsziel (Tage)“ (Standard **14 Tage**, gilt auch für die Mahnfrist).

### 5.3 Ausführung in Paketen

Rund 450 Mitglieder → 450 PDFs. Das überschreitet die Laufzeitgrenze bei IONOS in einem Aufruf.
**[Vorschlag]** „Rechnungen erstellen“ läuft per AJAX in Paketen von **20 Mitgliedern**:

1. Vorschau: Anzahl Rechnungen, Summe je Jahr, Fehlerfälle (Summe ≤ 0, fehlende Adresse).
2. „Erstellen“: Schleife über die Pakete mit Fortschrittsbalken. Jedes Paket: Nummern vergeben und Kopf
   speichern (Transaktion), danach PDFs erzeugen und Dateinamen speichern.
3. Schlägt ein PDF fehl, bleibt die Rechnung mit Nummer bestehen (Status „PDF fehlt“); „PDF erzeugen“ holt es
   nach. Nummern bleiben damit lückenlos und unabhängig vom PDF.
4. Ergebnisliste: erstellt, übersprungen (mit Grund), PDF-Fehler.

### 5.4 Änderungen nach der Erstellung

Eine ausgestellte Rechnung wird nicht verändert (das PDF bleibt, `mfi_amount` bleibt).

| Ereignis | Wirkung |
|---|---|
| Neuberechnung ändert eine **offene** Position mit Nummer (Rollenbeitrag geändert) | **Entschieden:** Neuberechnung ändert und entfernt Positionen mit Rechnungsnummer nicht, sondern meldet sie („Position gehört zu Rechnung 2026-B-0012 – erst stornieren“). Sonst passt der Einzugsbetrag nicht mehr zum Beleg. |
| Neue Position desselben Jahres (Rollenwechsel, Nachberechnung) | neue Rechnung |
| Storno einzelner Positionen | Position bleibt mit Nummer, PDF bleibt; Status ändert sich erst, wenn alle storniert sind |
| Storno aller Positionen | Status `storniert`, beim nächsten Abruf entsteht `rechnung_<nr>_storniert.pdf` (Vermerk) |
| Storno mit **„Erneut einziehen“** | **Entschieden (Abweichung):** die neu angelegte Position übernimmt die Nummer der stornierten. Die Rechnung bleibt `offen`, kein Storno-Vermerk, keine neue Rechnung. |
| Zusatzposition beim Storno (Rücklastschriftgebühr) | **Entschieden:** bekommt ebenfalls die Nummer der stornierten Rechnung (wie die Mahngebühr). Erscheint auf einer Mahnung, nicht auf der ursprünglichen Rechnung. |
| Einzug verwerfen | Positionen wieder `offen`, Nummer bleibt |
| Als eingezogen markieren / Zahlung | Status `bezahlt` (berechnet) |
| Bezahlt zurücknehmen | Status wieder `offen`/`im Einzug` (berechnet) |
| Löschen am Jahresende (Arbeitsfelder leeren) | keine Wirkung auf Rechnungen |
| Position mit Nummer löschen (manuelle offene Position) | **[Vorschlag]** nicht erlaubt; stattdessen stornieren |

## 6. Oberfläche 3.0 Rechnungen erstellen

**Entschieden (05.11.2026): neue Reihenfolge im Menü (Gruppe Einzug)**

| bisher | neu |
|---|---|
| 2.0 Fälligkeit | 2.0 Fälligkeit |
| 3.0 Vorabinformation | **3.0 Rechnungen erstellen** |
| 4.0 SEPA-Datei erstellen | **3.1 Vorabinformation** |
| 4.1 Rechnungen erstellen | 4.0 SEPA-Datei erstellen |
| 5.0 Lastschrifteinzug, 5.1 Manuelle Zahlungen, 6.0–8.0 | unverändert |

Seite bleibt `bill.php` mit Umschaltung Mitgliedsbeitrag/Arbeitsdienst (Jahr im Button). Titel, Dokumentation und
Kurzanleitung werden angepasst.

**Liste** (wie bisher Mitglieder, neu je Rechnung eine Zeile **[Vorschlag]**):

| Spalte | Inhalt |
|---|---|
| Auswahl | Markierung (vorhandene Session-Logik) |
| Name, E-Mail | wie bisher (Spalten aus `bill_fields`) |
| Rechnung | Nummer bzw. „– noch keine –“ (offene Positionen ohne Nummer, mit Summe) |
| Datum, Fällig | Rechnungsdatum, Zahlungsziel |
| Betrag | `mfi_amount` |
| Zahlart | Lastschrift / Überweisung |
| Status | Badge offen / im Einzug / bezahlt / storniert / überfällig / gemahnt |
| Versand | Symbole Mail / Link / Druck mit Datum |
| PDF | Ansehen (Rechnung, Mahnung) |

**Filter:** E-Mail vorhanden / fehlt (wie bisher) · Rechnung: ohne Rechnung / offen / überfällig / gemahnt / alle ·
Beitragsjahr · Suche.

**Aktionen** (für die markierten Zeilen):

1. **Rechnungen erstellen** (5.3) – nur für Zeilen „ohne Rechnung“.
2. **Per E-Mail senden** (7.1) – öffnet die Mail mit Vorlage, Anhang und Platzhaltern.
3. **Druckstapel** – ZIP oder ein Druck-PDF (7.3).
4. **Mahnungen erstellen** – nur überfällige (Abschnitt 8).
5. **Export CSV/XLSX** – wie bisher, ergänzt um Rechnungsnummer, Datum, Fällig, Status.
6. Je Zeile: PDF neu erzeugen (nur Administratoren, siehe 5.4 des Bibliothekskonzepts), Rechnung anzeigen.

Ohne PDF-Bibliothek: Aktionen 2 (ohne Anhang), 3 und PDF-Spalte entfallen; Hinweis mit Grund („PDF-Bibliothek
nicht installiert“ bzw. „benötigt PHP 8.5“).

## 7. Versand

### 7.1 Mail mit Anhang und Platzhaltern

- Vorlagen in Einstellungen → E-Mail-Benachrichtigungen:
  - „Rechnung Mitgliedsbeitrag“ = vorhandene Vorlage `PMBMAIL_BILL` (bleibt),
  - neu „Rechnung Arbeitsdienst“, „Mahnung Mitgliedsbeitrag“, „Mahnung Arbeitsdienst“
    (wie die Vorlage „Vorabinformation Arbeitsdienst“).
- `message_write.php` erkennt den Aufruf aus 3.0 (Kreis, Rechnung/Mahnung) und wählt die Vorlage.
- **Vorabinformation (3.1) mit Rechnung (entschieden):** Die Vorabinformation hängt bei vorhandener Rechnung das
  PDF an (dieselbe Checkbox, Standard an). SEPA-Zahler bekommen damit **eine** Mail mit Einzugsankündigung und
  Rechnung. Überweiser bekommen ihre Rechnung über „Per E-Mail senden“ in 3.0. Versand setzt `mfi_sent_mail`.
  Neue Platzhalter stehen auch in der Vorlage der Vorabinformation zur Verfügung.
- Checkbox **„Rechnung als PDF anhängen“** (Standard an, wenn die Bibliothek da ist). Je Empfänger wird die
  zuletzt erstellte, nicht stornierte Rechnung des Kreises angehängt (bei Mahnungsversand die Mahnung).
  Fehlt die Datei, wird sie neu erzeugt.
- Neue Platzhalter in `replace_emailparameter()`:
  `#invoice_no#`, `#invoice_date#`, `#invoice_due#`, `#invoice_amount#`, `#invoice_link#`,
  `#dunning_date#`, `#dunning_due#`.
- Ergebnisliste in `message_send.php`: „nicht gesendet: keine Rechnung“ bzw. „keine E-Mail-Adresse“.
- Nach Erfolg: `mfi_sent_mail` (und `mfi_sent_link`, wenn `#invoice_link#` im Text stand).
- Empfänger: wie bisher `DEBTOR_EMAIL`, sonst `EMAIL`.

### 7.2 Signierter Link

`#invoice_link#` = signierter Link der Bibliothek, gültig 1 Jahr (Mahnung: eigener Link). Funktioniert ohne
Admidio-Konto. Erzeugt über die Brücke (Abschnitt 10).

### 7.3 Druckstapel

Wie Bibliothekskonzept 5.3, mit diesen Festlegungen im Plugin:

- Nur Zeilen mit vorhandener Datei; andere in der Ergebnisliste („kein PDF vorhanden“).
- Auswahl „Rechnungen“ oder „Mahnungen“, Format „ZIP“ oder „ein Druck-PDF (beidseitig)“.
- Nur POST mit CSRF-Token, Recht = Zugriffsrolle des Plugins, Pfade nur aus der Datenbank, Dateiname gegen das
  Muster aus 4.3, `root` = Unterordner des Kreises.
- Ausgabe als Download (`attachment`, `no-store`, `nosniff`), nie als Link; nicht abgelegt.
- `mfi_sent_print` setzen; im Protokoll (Kommentar am Kopf oder Plugin-Log) wer/wann.

### 7.4 Anzeige und Download im Plugin

**Entschieden (Abweichung):** Abgelegte PDFs liefert das Plugin selbst aus (`invoice_file.php?nr=…&type=rechnung|mahnung`),
geprüft mit `isUserAuthorized()` (Zugriffsrolle). Der Dispatcher der Bibliothek erlaubt in Admidio ohne Link nur
Administratoren – der Kassier ist meist keiner. So bleibt der Dispatcher unverändert und das Recht liegt dort,
wo es auch für Beträge und Bankdaten gilt.

## 8. Mahnung

- Überfällig: Status `offen` (nicht `im Einzug`), `mfi_due_date` < heute, `mfi_dunning_date` leer. Bei
  Lastschrift-Rechnungen ist das nur nach einer Rücklastschrift ohne neuen Einzug der Fall; die Mahnung fordert dann
  zur Überweisung auf.
- Ablauf: Filter „überfällig“ → markieren → **Mahnungen erstellen**:
  1. Vorschau je Rechnung: offener Betrag und zugeordnete Mahngebühr (falls angelegt).
  2. `mfi_dunning_date` = heute, `mfi_dunning_due` = heute + Zahlungsziel.
  3. Offene manuelle Positionen des Mitglieds, Jahres und Kreises **ohne** Rechnungsnummer, deren Bezeichnung der
     Positionsvorlage „Mahngebühr“ entspricht, bekommen die Nummer der Rechnung.
  4. PDF `mahnung_<nr>.pdf` erzeugen (in Paketen wie 5.3).
- Positionsvorlage „Mahngebühr“ unter Einstellungen → Vorlagen für manuelle Positionen anlegen (Betrag vom Verein).
- Nur eine Mahnung je Rechnung; danach geht es außerhalb des Plugins weiter.
- **[Vorschlag]** Die Mahnung zeigt den **offenen** Betrag (nicht stornierte, nicht eingezogene Positionen der Rechnung
  einschließlich zugeordneter Gebühren), nicht `mfi_amount`.

## 9. Profil

- Neue Liste „Rechnungen“: Nummer, Datum, Betrag, Status, Links „PDF“ und „Mahnung“.
- Karte „Beiträge“ (nur Zugriffsrolle): alle Rechnungen des Mitglieds, Links über `invoice_file.php` (7.4).
- Eigenes Profil: Liste in einer schmalen Karte „Rechnungen“, auch für das Mitglied selbst sichtbar, Links als
  kurzlebige signierte Links (gültig bis morgen), wie im Bibliothekskonzept 5.5 entschieden.
- Arbeitsdienst-Rechnungen zusätzlich in der Karte „Arbeitsdienst“.

## 10. Brücke zur PDF-Bibliothek

Das Plugin greift nur über eine Klasse `classes/Invoices/PdfBridge.php` auf die Bibliothek zu:

| Methode | Zweck |
|---|---|
| `isAvailable(): bool` | `adm_plugins/wp-tc-lib-pdf/adm-include/bootstrap.php` vorhanden **und** `PHP_VERSION_ID >= 80500`; Grund für die Anzeige über `unavailableReason()` |
| `createInvoicePdf(string $nr, string $type): string` | lädt die Bibliothek im laufenden Request, erzeugt `rechnung`/`mahnung`/`storniert`, legt ab, gibt den Dateinamen zurück; wirft bei Fehlern |
| `filePath(string $kind, string $file): string` | absoluter Pfad im Ablageordner (für Anhang und Auslieferung) |
| `signedUrl(string $nr, string $type, string $expires): string` | signierter Link |
| `joinForPrint(array $files, string $kind): PdfJoinResult` | Druck-PDF über `PdfJoiner::join()` mit `root` = Unterordner |

Die Bibliothek bekommt ihre Daten **vom Plugin**, nicht direkt aus den Tabellen **[Vorschlag, Abweichung]**:
`InvoiceService::pdfData(string $nr): array` liefert ein neutrales Array (Empfänger, Positionen, Daten, Zahlart,
Mandat, Bankdaten des Vereins, Kreis, Aufnahme ja/nein, Arbeitsdienst-Stunden, Storno-Vermerk). Die Templates in
`adm-include/templates/` rufen nur diese Funktion auf. Damit bleiben Tabellen, Statuslogik und Profilfelder an
einer Stelle, und das Plugin kann seine Struktur ändern, ohne die Bibliothek anzupassen.

Ordnername: Die Bibliothek findet das Plugin über `config.php` (`'membershipfee_dir' => 'MembershipFee'`), nicht
über einen fest eingetragenen Ordner `Mitgliedsbeitrag`.

## 11. Inhalt der PDFs (vom Plugin geliefert)

| Bereich | Mitgliedsbeitrag | Arbeitsdienst |
|---|---|---|
| Empfänger | `DEBTOR`/`DEBTOR_*`, sonst Name und Adresse des Mitglieds (wie `bill_export.php`) | gleich |
| Kopf | Rechnungsnummer, Datum, Mitgliedsnummer, Beitragsjahr | Rechnungsnummer, Datum, Mitgliedsnummer, Leistungsjahr |
| Anschreiben | „für das Beitragsjahr 2026 berechnen wir Ihnen:“; bei Aufnahme „für Ihre Aufnahme in den Verein …“ | „für das Leistungsjahr 2025 berechnen wir Ihnen den Ersatzbeitrag Arbeitsdienst:“ |
| Posten | je Position Bezeichnung (`mfb_rol_name`) und Betrag; stornierte mit Vermerk und 0,00 € | Position(en) „Ersatzleistung Arbeitsdienst“; darunter Soll, Ist, Fehlstunden × Stundensatz und die Einsätze des Jahres (Datum, Tätigkeit, Stunden) |
| Zahlart SEPA | „Der Betrag von … wird am <Fälligkeit> von Ihrem Konto <IBAN gekürzt> (Mandat <ID>) per Lastschrift eingezogen. Eine Überweisung ist nicht notwendig.“ **Kein** Zahlungsziel, **kein** QR-Code, kein Verzugshinweis | gleich |
| Zahlart MAN | Zahlungsziel, Überweisungstext mit Vereins-IBAN, Verwendungszweck = Rechnungsnummer, EPC-QR-Code | gleich |
| Storno | Vermerk „Rechnung storniert am … (Grund)“ | gleich |
| Mahnung | Bezug auf Rechnung, „war am … fällig“, neue Frist, offener Betrag inkl. Gebühr | gleich |

Hinweis für die Bibliothek: Der QR-Code wird heute immer erzeugt, wenn eine Vereins-IBAN da ist; für SEPA muss
das Template `qr_content` leer setzen können (Abschnitt 12).

## 12. Rückwirkung auf wp-tc-lib-pdf

Abweichungen dieses Konzepts, die im Bibliothekskonzept nachzutragen sind:

1. Ordnername des Plugins aus `config.php` (`MembershipFee` auf dem Server).
2. Templates lesen ihre Daten über `InvoiceService::pdfData()` statt über `BookingStore` und Profilfelder.
3. Ablage-Auslieferung für eingeloggte Benutzer übernimmt das Plugin (7.4); der Dispatcher bleibt für signierte Links.
4. Dateinamen-Muster `\d{4}-(B|AD)-\d{4}` statt `\d{4}-[A-Z]-\d{4}`.
5. Kein gespeichertes `mfi_status` (berechnet); zusätzlicher Status `im Einzug`.
6. Rechnungen entstehen vor der Vorabinformation; abgerechnet werden nur offene Positionen (wie bisher im
   Bibliothekskonzept, keine Abweichung).
7. „Erneut einziehen“ und Rücklastschriftgebühr übernehmen die Nummer der stornierten Rechnung (5.4).
8. Lastschrift-Rechnung: QR-Code abschaltbar, `rechnungTextBelow()` ohne Zahlungsziel und Verzugshinweis, stattdessen
   Einzugsankündigung mit „Eine Überweisung ist nicht notwendig.“
9. Erzeugen im laufenden Request: `adm-include/bootstrap.php` muss laufen, wenn `common.php` schon geladen ist,
   und ein Template ohne HTTP-Parameter erzeugen können (`setUrldata(['nr' => …])`, dann `save()`).
10. PHP 8.5: Admidio-Seiten des Plugins laufen auch mit 8.4 weiter; die Bibliothek wird erst geladen, wenn
    `isAvailable()` zutrifft (sonst bricht `platform_check.php` den ganzen Request mit 500 ab).

## 13. Sicherheit, Datenschutz, Aufbewahrung

- Rechte: alle Aktionen nur Zugriffsrolle des Plugins; „PDF neu erzeugen“ nur Administratoren.
- Ablage in `adm_my_files/plugins/wp-tc-lib-pdf/{beitrag|arbeitsdienst}/` (nicht per HTTP erreichbar).
- Signierte Links enthalten nur die Rechnungsnummer; Secret in `adm_my_files/plugins/wp-tc-lib-pdf/config.php` (nicht im Repo; Rückfall `adm-include/config.php`).
- Druckstapel und ZIP nie als Link, keine Zwischendateien.
- Aufbewahrung **[Vorschlag]**: Rechnungen und Kopfdaten werden vom Plugin nie automatisch gelöscht, auch nicht
  beim Jahreswechsel. Beim Deinstallieren werden sie nur gelöscht, wenn der Haken „Rechnungen löschen“ gesetzt ist
  (Hinweis auf Aufbewahrungsfristen).
- Wird ein Mitglied in Admidio gelöscht, bleiben Kopf und PDF erhalten (die Buchungshistorie bleibt heute ebenfalls).

## 14. Offene Fragen

1. ~~**Nummer bei „Erneut einziehen“ und Rücklastschriftgebühr**~~ – entschieden: Nummer der stornierten Rechnung.
2. ~~**Exportierte Positionen**~~ – entschieden: Rechnungen vor der Vorabinformation, nur `offen`.
3. ~~**Neuberechnung und Positionen mit Rechnungsnummer**~~ – entschieden: sperren und melden.
4. ~~**Auslieferung für den Kassier**~~ – entschieden: Plugin liefert aus, Recht = Zugriffsrolle.
5. ~~**Datenzugriff der Templates**~~ – entschieden: über `InvoiceService::pdfData()`.
6. ~~**Zahlungsziel**~~ – entschieden: Lastschrift-Rechnungen ohne Zahlungsziel mit Einzugsankündigung (5.2);
   für Überweiser gilt die Einstellung „Zahlungsziel (Tage)“, Standard 14 Tage.
7. ~~**Aufnahme kurz vor dem Jahreswechsel**~~ – entschieden: Mitgliedschaften und einmalige Rollen beginnen
   immer am 01.01. (Abschnitt 3).
8. ~~**Liste je Rechnung oder je Mitglied**~~ – entschieden: je Rechnung.
9. ~~**Rechnungen für Mitglieder ohne Adresse**~~ – entschieden: erstellen, in der Vorschau warnen.
10. ~~**Löschen beim Deinstallieren**~~ – entschieden: nur mit ausdrücklichem Haken.
11. ~~**PHP-Version der Testinstanz**~~ – geklärt 05.11.2026: Testinstanz und Produktion laufen mit PHP 8.5.
12. ~~**Menü und Vorabinformation**~~ – entschieden: Menü 2.0 Fälligkeit, 3.0 Rechnungen erstellen,
    3.1 Vorabinformation, 4.0 SEPA-Datei erstellen; die Vorabinformation hängt die Rechnung als PDF an (6, 7.1).

## 15. Umsetzungsphasen

Jede Phase ist für sich lauffähig und auf der Testinstanz prüfbar.

| # | Wo | Inhalt | Ergebnis |
|---|---|---|---|
| P1 | Plugin | Prüfung „Einmalige Beitragsrollen“ mit Behebung (Abschnitt 3) | Aufnahmegebühr/Erstbesatz nur einmal |
| P2 | Plugin | Spalte `mfb_invoice_no`, Tabelle `invoices`, Einstellung Zahlungsziel, `InvoiceService` (Nummern, Status, Erstellen ohne PDF) + Tests | Rechnungsnummern ohne PDF |
| P3 | Plugin | Menü umstellen, 3.0 neu: Liste je Rechnung, Filter, „Rechnungen erstellen“ in Paketen, Export mit Nummer; Storno/„Erneut einziehen“ und Neuberechnung nach 5.4 | Rechnungen verwaltbar, Serienbrief über Export möglich |
| P4 | Bibliothek | `adm-include/` (Bootstrap im laufenden Request, Plattform, Dispatcher für signierte Links, `config_sample.php` mit Plugin-Ordner und Secret) | Bibliothek in Admidio ladbar |
| P5 | Bibliothek + Plugin | `PdfBridge`, `InvoiceService::pdfData()`, Template Beitragsrechnung, Ablage, `invoice_file.php` | Beitragsrechnungen als PDF |
| P6 | Bibliothek + Plugin | Template Arbeitsdienst, Storno-Fassung | Arbeitsdienst-Rechnungen |
| P7 | Plugin | Mail: Anhang, Platzhalter, Vorlagen, Versandprotokoll | Versand per Mail/Link |
| P8 | Plugin | Druckstapel (ZIP, Druck-PDF) | Postversand |
| P9 | Plugin + Bibliothek | Mahnungen (Liste, Gebühr zuordnen, Template, Vorlagen) | eine Mahnstufe |
| P10 | Plugin | Profil: Rechnungsliste, kurzlebige Links | Download im Profil |
| P11 | beide | Doku (`docs/`, Dokumentationsseite, Kurzanleitung im Projekt), Version 7.0.0 | |
| P12 | WordPress | `rechnung_antrag`/`mahnung_antrag` abschalten | nach der ersten Aufnahme über Admidio |

P1 bis P3 hängen nicht an der Bibliothek.

**Stand der Umsetzung (05.11.2026, Version 6.5.0, Testinstanz):** P1 bis P3 umgesetzt.
- P1: Prüfung „Einmalige Beitragsrollen“ (`classes/Checks/OneTimeRoleCheck.php`, 1.0 Prüfungen, Schalter in Einstellungen → Prüfungen).
- P2: Spalte `mfb_invoice_no`, Tabelle `…_invoices`, `classes/Invoices/` (InvoiceNumber, InvoiceStatus, InvoicePlanner, InvoiceStore,
  InvoiceService), Einstellung „Zahlungsziel (Tage)“ unter Einstellungen → Export → Rechnung.
- P3: Menü 2.0 Fälligkeit, 3.0 Rechnungen erstellen, 3.1 Vorabinformation, 4.0 SEPA-Datei; `bill.php` je Rechnung mit Filtern, Vorschau
  und Erstellung in Paketen; Export mit Rechnungsnummer; Neuberechnung sperrt Positionen mit Rechnungsnummer (op `locked`);
  Storno „Erneut einziehen“ und Zusatzposition übernehmen die Nummer; manuelle Positionen mit Nummer nicht mehr bearbeit-/löschbar;
  „Löschen“ am Jahresende storniert offene Positionen mit Rechnungsnummer statt sie zu löschen.
- Offen aus P3: Hinweis „N Mitglieder ohne Rechnung“ auf Vorabinformation und SEPA-Datei (kommt mit der PDF-Bibliothek, P5).

**Stand 05.11.2026 abends: P4 und P5 umgesetzt, auf der Testinstanz noch ohne PDF (Web-PHP dort 8.4.27).**
- P4 (wp-tc-lib-pdf): `adm-include/` mit Bootstrap (auch im laufenden Request), Plattform, Dispatcher `pdf.php`
  (signierte Links, sonst Administratoren), `config_sample.php`; auf der Testinstanz installiert, `config.php` mit
  zufälligem Secret auf dem Server angelegt (Vereinsdaten dort noch eintragen).
- P5: Template `adm_rechnung_beitrag` und gleich mit `adm_rechnung_arbeitsdienst` (Soll/Ist/Fehlstunden, Einsätze)
  und Storno-Fassung; im Plugin `InvoicePdfData` (Daten fürs PDF), `PdfBridge`, PDF beim Erstellen der Rechnung,
  Spalte PDF in 3.0, `system/invoice_file.php` (Anzeige für die Zugriffsrolle, fehlendes PDF wird erzeugt,
  „neu erzeugen“ nur Administratoren). Mit PHP 8.5 auf der Kommandozeile des Servers mit Testdaten gerendert
  (Lastschrift, Überweisung mit QR-Code, Aufnahme, Storno, Arbeitsdienst).
- Begrüßung ohne Geschlecht im Profil: „Guten Tag Vorname Name,“.
- Voraussetzung für PDFs auf der Testinstanz: PHP 8.5 für backup.bfv-ehingen.de in IONOS einstellen.

**P7 umgesetzt (05.11.2026):** Checkbox „Rechnung als PDF anhängen“ in der E-Mail aus 3.0 und 3.1 (Rechnungsmail ohne
Rechnung wird nicht verschickt, Vorabinformation geht auch ohne Anhang), Platzhalter `#invoice_no#`, `#invoice_date#`,
`#invoice_due#`, `#invoice_amount#`, `#invoice_link#`, `#dunning_date#`, `#dunning_due#`, Vorlage „Rechnung Arbeitsdienst“,
Versandvermerk `mfi_sent_mail`/`mfi_sent_link`, Hinweis auf fehlende Rechnungen in 3.1.
Zusätzlich **Testbetrieb**: Einstellung „Testempfänger“ (Einstellungen → E-Mail-Benachrichtigungen); ist sie gesetzt, gehen
alle E-Mails des Plugins (auch die SEPA-Freigabe) an diese Adresse, der Betreff nennt den eigentlichen Empfänger
(„[TEST an …]“), der Versand wird nicht an der Rechnung vermerkt, und die Mail-Seite zeigt einen Warnhinweis.

**Versand nur noch aus 3.1 (entschieden 06.11.2026, Version 6.5.1):** 3.0 erstellt nur Rechnungen, PDFs und den Export,
ohne E-Mail. 3.0 zeigt die E-Mail-Adresse nur noch an. 3.1 heißt „Rechnungen versenden“ und hat die Auswahl *Zahlart*:
*Lastschrift* (Mitglieder mit Mandat und Fälligkeitsdatum, Vorlage Vorabinformation, Export für Serienbriefe) und
*Überweisung* (Mitglieder mit offener Rechnung ohne Lastschrift, Vorlage Rechnung, Zahlungsziel als Fälligkeit). Die
Tabelle zeigt die aktuelle Rechnungsnummer; beim Wechsel der Zahlart wird die Auswahl geleert. Der Standardtext der
Rechnungs-E-Mail (`PMBMAIL_BILL`) nutzt jetzt die Rechnungs-Platzhalter.

**Fälligkeit in „Rechnungen erstellen“ (entschieden 06.11.2026, Version 6.6.0):** Der Schritt 2.0 Fälligkeit entfällt
ganz (`duedates.php` leitet nur noch auf `bill.php` um). Haben markierte Lastschrift-Zahler noch keine Fälligkeit,
fragt die Vorschau Einzugsdatum (Vorschlag: erster Bankarbeitstag nach 14 Tagen; Wochenende, Bankfeiertag und
Vergangenheit werden im Browser und auf dem Server abgelehnt) und Sequenztyp (AUTO/RCUR/FRST/OOFF/FNAL) ab;
`InvoiceService::create()` setzt beides im Profil und erstellt dann die Rechnung mit diesem Einzugstag. Ohne
Einzugsdatum werden solche Mitglieder übersprungen (Grund `duedate`). Vorhandene Fälligkeiten bleiben unverändert.
Neues Menü: 2.0 Rechnungen erstellen, 2.1 Rechnungen versenden, 3.0 SEPA-Datei, 4.0 Lastschrifteinzug,
4.1 Manuelle Zahlungen, 5.0 Beitragsanalyse, 6.0 Rollenübersicht, 7.0 Buchungsübersicht.

## 16. Tests

- PHPUnit (vorhandener Satz, ohne Datenbank):
  - Prüfung einmalige Rollen: Ende 31.12. des Beginnjahres, offenes Ende, Ende später, Ende früher (Austritt),
    Beginn nicht 01.01., Positionen in mehreren Jahren.
  - Nummernvergabe (Format, Laufnummer je Jahr/Kreis, Kollision).
  - Statusberechnung (alle Kombinationen aus 4.4).
  - Positionsauswahl (offen/exportiert, ohne Nummer, Summe ≤ 0, mehrere Jahre).
  - Zahlart, Einzugstag bzw. Zahlungsziel (SEPA ohne Zahlungsziel).
  - Nummernweitergabe bei „Erneut einziehen“.
- Testinstanz: Rechnungen für eine Rolle erstellen, Mail an Testadresse, Druckstapel, Storno mit „Erneut einziehen“,
  Mahnung, Profil-Link, Lauf ohne Bibliothek.

**Zwei Tabellen Überweisung / SEPA-Einzug (entschieden 06.11.2026, Version 6.7.0):** 2.0 und 2.1 zeigen je eine
Tabelle „Rechnungen Überweisung“ und „Rechnungen SEPA-Einzug“; 3.0/4.0 nur SEPA, 4.1 standardmäßig nur Überweiser.
„Verschieben“ gilt nur für das Beitragsjahr der Zeile und den Buchungskreis (Tabelle
`adm_plugin_membershipfee_paymethods`, gespeichert wird nur die Abweichung `MAN`; zurück nach SEPA löscht sie und geht
nur mit IBAN und Mandats-ID). Nach Überweisung wird die Fälligkeit im Profil geleert; die SEPA-Exporte schließen
verschobene Mitglieder zusätzlich aus. Eine offene Rechnung behält ihre Nummer (Entscheidung „Rechnung behalten“):
Zahlart, Fälligkeit, Mandat und IBAN werden umgestellt und das PDF neu erzeugt; nach SEPA ohne Fälligkeit wird das
Einzugsdatum abgefragt. Rechnungen im Einzug sind gesperrt. Endpunkt `system/paymethod.php` (POST, CSRF).

**SEPA-Dateien in 3.0 Lastschrifteinzug (06.11.2026, Version 6.8.0):** Der Menüpunkt „SEPA-Datei erstellen“
entfällt; `runs.php` zeigt oben den Block „SEPA-Dateien erzeugen“ je Buchungskreis (Arbeitsdienst nur mit offenen
Lastschriften), gemeinsame Funktion `mf_sepa_create_block()`. Der Button nennt die Anzahl der Dateien. Neues Menü:
2.0 Rechnungen erstellen, 2.1 Rechnungen versenden, 3.0 Lastschrifteinzug, 3.1 Manuelle Zahlungen,
4.0 Beitragsanalyse, 5.0 Rollenübersicht, 6.0 Buchungsübersicht. Version 6.8.1: Rollenübersicht entfernt
(`roleoverview.php` leitet nur noch um), Buchungsübersicht ist jetzt 5.0.

**Erlassen / Reduzieren offener Positionen (06.11.2026, Version 6.9.0):** Das Storno-Symbol einer offenen Position
öffnet ein eigenes Formular (`booking_edit.php`, mode cancel): Position wird storniert, ein Restbetrag entsteht als
neue offene Position mit gleicher Quelle, Rolle, Text und Rechnungsnummer (`BookingService::waive()`); danach wird
das PDF neu erzeugt. `InvoiceService::createPdf()` setzt `mfi_amount` seitdem auf die Summe der nicht stornierten
Positionen (gilt auch nach Storno mit erneutem Einzug und Gebühr). Gesperrt bei Rechnungen im Einzug.

**Nachlass statt 0,00-Zeilen (06.11.2026, Version 6.9.1):** Offene Rechnungen zeigen stornierte Positionen nicht mehr
(`InvoicePdfData::load()`); stornierte Rechnungen zeigen weiter alles. Reduzieren legt eine negative Position
„Nachlass auf … (Grund)“ an (`mfb_import_ref` = `NACHLASS-<mfb_id>`, Rücknahme über ihr Storno-Symbol); ganz erlassen
storniert die Position und ihre Nachlässe mit Stornogrund „Nachlass <Grund>“, das PDF zeigt dann Betrag und Nachlass
in voller Höhe.
