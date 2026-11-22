# Arbeitsdienst im Plugin Mitgliedsbeitrag (ab Version 6.4.0)

Der Arbeitsdienst (AD) ist ein eigener **Buchungskreis** neben dem Mitgliedsbeitrag. Er nutzt dieselbe
Buchungshistorie, hat aber eigene Profilfelder, eigene Läufe und eine eigene SEPA-Datei.

## Regeln

- Leistungsjahr = Kalenderjahr, Stichtag 31.12. (Alter und Rollenzugehörigkeit).
- Sollstunden je Rolle sind Einstellungen (z. B. Aktiv 10 h, Probezeit 15 h, Jugend 0 h). Bei mehreren Rollen gilt der höchste Wert.
- Befreit sind Mitglieder in Ausnahme-Rollen oder Rollen aus Ausnahme-Kategorien, ausser die Rolle ist unter
  „Trotz Kategorie pflichtig“ eingetragen. Befreit ist auch, wer am Stichtag die Altersgrenze (60) erreicht hat.
- Kein Stundenübertrag ins Folgejahr.
- Jeder Eintrag hat einen **Status** (ab Version 6.10.0): `angemeldet`, `teilgenommen`, `entschuldigt`,
  `unentschuldigt`. Nur `teilgenommen` hat Stunden (> 0) und zählt als geleistet (Ist, Fehlstunden, Ersatzbeitrag,
  Rechnung); die anderen Status haben 0 Stunden. Einträge aus der Zeit vor 6.10.0 gelten als `teilgenommen`.
- Ersatzbeitrag = fehlende Stunden x Stundensatz (20,00 EUR).

## Profilfelder (Kategorie „Arbeitsdienst“)

| Feld | Zweck |
|---|---|
| ADTARGET | Sollstunden des Leistungsjahres; im eigenen Profil sichtbar |
| ADFEE, ADTEXT, ADDUEDATE, ADSEQUENCETYPE, ADPAID | Arbeitsfelder des AD-Einzugs (wie FEE, CONTRIBUTORY_TEXT, DUEDATE, SEQUENCETYPE, PAID beim Beitrag) |

Die Felder werden beim ersten Aufruf automatisch angelegt und bei der Deinstallation entfernt.

## Ablauf (Reiter „Arbeitsdienst“)

1. **Sollstunden abgleichen**: setzt ADTARGET für alle Mitglieder nach den Regeln (Vorschau, dann übernehmen).
   Zeigt zugleich je Mitglied Ist, Fehlstunden, Ersatzbeitrag und Stand (Filter nach Stand, Suche, CSV-Export);
   die frühere Jahresübersicht ist darin aufgegangen.
2. **Arbeitsdienst erfassen**: Einsätze (Datum, Bezeichnung, Status, Teilnehmer, Stunden). Eigene Seite mit eigenem
   Menüpunkt; Berechtigung über „Sichtbar für“ des Menüpunkts (z. B. Arbeitsdienstleiter). Der Status gilt für alle
   ausgewählten Teilnehmer; Stunden werden nur bei „teilgenommen“ abgefragt. Je Eintrag lassen sich Status und
   Stunden einzeln ändern; die Einsatzliste zeigt den Status und im Kopf die Anzahl je Status.
4. **Arbeitsdienst abrechnen**: legt je Mitglied eine offene Position „Ersatzleistung Arbeitsdienst“ (Quelle `ad`)
   an, passt sie an oder entfernt sie. Bereits exportierte oder eingezogene Positionen bleiben unverändert.
5. **Rechnungen erstellen** (2.0): Rechnungen mit Nummer `JAHR-AD-NNNN`; Lastschrift-Zahler ohne Fälligkeit erhalten
   dabei das Einzugsdatum aus dem Dialog (ADDUEDATE, Sequenztyp).
6. **Rechnungen versenden** (2.1): Lastschrift mit der Vorlage „Vorabinformation Arbeitsdienst“, Überweisung mit
   „Rechnung Arbeitsdienst“ (Einstellungen → E-Mail-Benachrichtigungen).
7. **SEPA-Datei erzeugen** (oben in 3.0 Lastschrifteinzug): eigene Datei `sepa_arbeitsdienst_<datum>-<typ>.xml`, Message-ID `AD-…`,
   End-to-End-ID `BFV-AD-<usr>-<datum>`.
8. **Einzugsübersicht** (3.0, Spalte „Art“) und 3.1 Manuelle Zahlungen.

Sobald eine AD-Position eines Jahres exportiert ist, ist die Erfassung für dieses Jahr gesperrt.

## Anzeige beim Mitglied

Eigene Registerkarte „Arbeitsdienst“ im Profil (wird mit der Registerkarte „Beiträge“ eingebaut; eine ältere
Fassung ergänzt das Plugin automatisch, wenn ein Administrator es öffnet):
- Stand des laufenden Jahres (Soll, geleistet, noch offen),
- je Leistungsjahr Soll, Ist, Fehlstunden und Ersatzbeitrag mit Status (Soll vergangener Jahre aus dem Positionstext),
- Liste aller geleisteten Einsätze, nach Jahr gruppiert.

Sichtbar für das Mitglied selbst, die Zugriffsrolle und alle, die Arbeitsdienst erfassen dürfen (mit Button
„Arbeitsdienst erfassen“). In der Buchungshistorie steht weiterhin eine kurze Zusammenfassung.

## Technik

- `classes/Bookings/CollectionScope.php`: Buchungskreise `mf` und `ad` (Felder, Quellen, IDs).
- `classes/Work/*`: Regeln (WorkRules), Einsätze (WorkHoursStore, Tabelle `adm_plugin_membershipfee_workhours`),
  Profilfelder (WorkProfileFields), Abrechnung (WorkBillingPlanner), Jahressperre (WorkYearLock).
- `classes/Work/WorkHoursApi.php`: Schnittstelle für andere Plugins (z. B. WpUserSync): Stunden eines
  Mitglieds per UUID lesen (`read`), anlegen/ändern (`write`) und löschen (`delete`), Sollstunden setzen
  (`writeTarget`, Profilfeld ADTARGET). Eintrag mit `status` (Standard `teilgenommen`); Ändern nur des Status
  (z. B. `angemeldet` → `teilgenommen` mit `hours`) per `id`. Mit Status prüft `check_duplicates` nur Datum und
  Einsatz, damit ein Statuswechsel keinen zweiten Eintrag erzeugt. Es gelten dieselben
  Regeln wie auf „Arbeitsdienst erfassen“: Recht über die Zugriffsrolle oder den Menüpunkt, Mitglied im
  Leistungsjahr. Gesperrte Jahre ändert die Schnittstelle nie (Fehler `year_locked`). Beträge werden nicht
  ausgegeben. Fehler kommen als `WorkApiException` mit Code und HTTP-Status.
- Läufe tragen `mfr_kind` (`mf`/`ad`).
- Tests: `php phpunit.phar -c phpunit.xml`, statische Analyse mit PHPStan (Level 6).
