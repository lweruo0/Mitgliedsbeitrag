# Mitgliedsbeitrag / Membership fee
Membership fee calculates membership fees based on a member's role affiliations.

Membership fee is a plugin for the online membership management system Admidio (https://www.admidio.org) and cannot be used without it.

Contribution recalculation uses the full configured role fee for every member of a contribution role in the contribution year. Family roles and prorated fees are not supported.

Since version 6.4.0 the plugin includes the work service (Arbeitsdienst): target hours per member (profile field), recording of work entries with its own access right, a yearly overview and a separate billing and SEPA collection run for the replacement fee. See `docs/arbeitsdienst.md` (German).

Since version 6.5.0 invoices get fixed numbers (`2026-B-0012`, `2026-AD-0003`) under 3.0 Rechnungen erstellen; PDF output via wp-tc-lib-pdf is planned, see `docs/konzept-rechnungen-pdf.md` (German).

Since version 6.2.0 the generated SEPA XML file is stored with its collection run (current contribution year only), can be downloaded again and shared with the treasurer via a protected, expiring download link sent by email.

Since version 6.1.0 every contribution is stored as a booking item per member, role and contribution year
(booking history). The recalculation skips roles that have already been collected in the contribution year,
charges only the difference within contribution groups (e.g. passive → active) and supports manual items
(e.g. return debit fees). SEPA files are recorded as collections that can be marked as collected with one click;
returned debits are cancelled in the member's history. See `docs/konzept-buchungshistorie.md` (German).

Online documentation is available here: https://www.admidio.org/dokuwiki/doku.php?id=de:plugins:mitgliedsbeitrag#mitgliedsbeitrag

## Change history
The [change history](https://www.admidio.org/dokuwiki/doku.php?id=de:plugins:mitgliedsbeitrag#aenderungshistorie) provides detailed information about the bug fixes and improvements in each version.
