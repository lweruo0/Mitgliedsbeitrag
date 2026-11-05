# Arbeitsdienst im Plugin Mitgliedsbeitrag (ab Version 6.4.0)

Der Arbeitsdienst (AD) ist ein eigener **Buchungskreis** neben dem Mitgliedsbeitrag. Er nutzt dieselbe
Buchungshistorie, hat aber eigene Profilfelder, eigene Läufe und eine eigene SEPA-Datei.

## Regeln

- Leistungsjahr = Kalenderjahr, Stichtag 31.12. (Alter und Rollenzugehörigkeit).
- Sollstunden je Rolle sind Einstellungen (z. B. Aktiv 10 h, Probezeit 15 h, Jugend 0 h). Bei mehreren Rollen gilt der höchste Wert.
- Befreit sind Mitglieder in Ausnahme-Rollen oder Rollen aus Ausnahme-Kategorien, ausser die Rolle ist unter
  „Trotz Kategorie pflichtig“ eingetragen. Befreit ist auch, wer am Stichtag die Altersgrenze (60) erreicht hat.
- Kein Stundenübertrag ins Folgejahr.
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
2. **Arbeitsdienst erfassen**: Einsätze (Datum, Bezeichnung, Teilnehmer, Stunden). Eigene Seite mit eigenem
   Menüpunkt; Berechtigung über „Sichtbar für“ des Menüpunkts (z. B. Arbeitsdienstleiter).
4. **Arbeitsdienst abrechnen**: legt je Mitglied eine offene Position „Ersatzleistung Arbeitsdienst“ (Quelle `ad`)
   an, passt sie an oder entfernt sie. Bereits exportierte oder eingezogene Positionen bleiben unverändert.
5. **Fälligkeitsdatum** (ADDUEDATE, Sequenztyp).
6. **Vorabinformation**: E-Mail-Vorlage „Vorabinformation Arbeitsdienst“ (Einstellungen → E-Mail-Benachrichtigungen).
7. **SEPA-Datei erzeugen**: eigene Datei `sepa_arbeitsdienst_<datum>-<typ>.xml`, Message-ID `AD-…`,
   End-to-End-ID `BFV-AD-<usr>-<datum>`. 7b. Rechnungen erstellen.
8. **Einzugsübersicht** (Spalte „Art“) und 8b. Beitragszahlungen.

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
  Profilfelder (WorkProfileFields), Abrechnung (WorkBillingPlanner).
- Läufe tragen `mfr_kind` (`mf`/`ad`).
- Tests: `php phpunit.phar -c phpunit.xml`, statische Analyse mit PHPStan (Level 6).
