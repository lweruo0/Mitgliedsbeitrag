<?php
/**
 ***********************************************************************************************
 * Konfigurationsdaten fuer das Admidio-Plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 ***********************************************************************************************
 */
global $gProfileFields;

$config_default = array();

// Standardwerte einer Neuinstallation

// Beitrag
$config_default['Beitrag'] = array(
    'beitrag_prefix' => 'Mitgliedsbeitrag 2026',
    'beitrag_textmitnam' => 1,
    'beitrag_text_token' => '#',
    'beitrag_role_separator' => '',
    'beitrag_abrunden' => 1,
    'beitrag_mindestbetrag' => 0,
    'beitrag_jahr' => 0
);

// Beitragsgruppen mit Differenzberechnung (je Gruppe die rol_ids, durch Komma getrennt)
$config_default['Beitragsgruppen'] = array(
    'name' => array(
        ' '
    ),
    'roles' => array(
        ' '
    )
);

// Arbeitsdienst: Sollstunden je Rolle (parallele Listen), Ausnahmerollen und -kategorien (rol_ids bzw.
// cat_ids, durch Komma getrennt), Altersgrenze (ab diesem Alter am 31.12. befreit, leer = keine) und
// Ersatzbeitrag je Fehlstunde; siehe classes/Work/WorkRules.php
$config_default['Arbeitsdienst'] = array(
    'soll_rollen' => array(
        ' '
    ),
    'soll_stunden' => array(
        ' '
    ),
    'ausnahme_rollen' => ' ',
    'ausnahme_kategorien' => ' ',
    'einschluss_rollen' => ' ',
    'altersgrenze' => 60,
    'stundensatz' => 20
);

// Vorlagen fuer manuelle Positionen (z. B. Ruecklastschriftgebuehr)
$config_default['Positionsvorlagen'] = array(
    'name' => array(
        'Rücklastschriftgebühr'
    ),
    'amount' => array(
        ' '
    ),
    'text' => array(
        'Rücklastschriftgebühr'
    )
);

// Kontodaten
$config_default['Kontodaten'] = array(
    'bank' => 'Sparkasse Musterstadt',
    'inhaber' => 'Musterverein e.V.',
    'iban' => 'DE123456789',
    'bic' => 'ABCDEFGH',
    'ci' => 'DE98ZZZ09999999999',
    'origcreditor' => '',
    'origci' => ''
);

// Mandatsreferenz
$config_default['Mandatsreferenz'] = array(
    'prefix_mem' => 'MIT',
    'prefix_pay' => 'ZAL',
    'min_length' => 15,
    'data_field' => '-- User_ID --'
);

// Rollenpruefung
$config_default['Rollenpruefung'] = array(
    'fixrollenpflicht' => array(
        ' '
    ),
    'bezugskategorie' => array(
        ' '
    ),
    'fixrollenfixrollen' => array(
        ' '
    )
);

$config_default['tests_enable'] = array(
    'role_membership_duty_and_exclusion' => 1,
    'account_details' => 1,
    'mandate_management' => 1,
    'iban_check' => 1,
    'bic_check' => 1,
    'onetime_roles' => 1
);

// Testbetrieb: alle E-Mails des Plugins an diese Adresse statt an die Mitglieder (leer = aus)
$config_default['Testbetrieb'] = array(
    'test_email' => ''
);

// Rechnungen (Zahlungsziel in Tagen fuer Ueberweiser und fuer die Mahnfrist)
$config_default['Rechnung'] = array(
    'zahlungsziel' => 14
);

// Rechnungs-Export
$config_default['Rechnungs-Export'] = array(
    'rechnung_dateiname' => 'rechnung',
    'rechnung_dateityp' => 'xlsx'
);

// SEPA
$config_default['SEPA'] = array(
    'dateiname' => 'sepa',
    'direct_debit_format' => 'v8',
    'combine_sequence_types' => 1,
    'kontroll_dateiname' => 'sepa',
    'kontroll_dateityp' => 'xlsx',
    'vorabinformation_dateiname' => 'export',
    'vorabinformation_dateityp' => 'xlsx',
    'duedate_for_all' => 0
);

// Teilen einer SEPA-Datei per Download-Link (Einzugsuebersicht)
$config_default['SEPA-Freigabe'] = array(
    'email' => '',
    'tage' => 14
);

// Plugininformationen
$config_default['Plugininformationen']['version'] = '';
$config_default['Plugininformationen']['stand'] = '';

// Spalten fuer die Ansichtsdefinitionen
$config_default['columnconfig'] = array(
    'payments_fields' => array(
        'p' . $gProfileFields->getProperty('PAID' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('DUEDATE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('SEQUENCETYPE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('FEE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('BIRTHDAY', 'usf_id')
    ),
    'mandates_fields' => array(
        'p' . $gProfileFields->getProperty('MANDATEDATE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('MANDATEID' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('BIRTHDAY', 'usf_id')
    ),
    'bill_fields' => array(
        'p' . $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('BIRTHDAY', 'usf_id')
    ),
    'duedates_fields' => array(
        'p' . $gProfileFields->getProperty('DUEDATE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('SEQUENCETYPE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('FEE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('BIRTHDAY', 'usf_id')
    )
);

// Zugriffsberechtigung für das Modul preferences
$config_default['access']['preferences'] = array();

// Infos für Uninstall
$config_default['install']['access_role_id'] = 0;
$config_default['install']['menu_item_id'] = 0;

// Individualbeiträge
$config_default['individual_contributions'] = array(
    'access_to_module' => '0',
    'desc' => array(
        ' '
    ),
    'short_desc' => array(
        ' '
    ),
    'role' => array(
        ' '
    ),
    'amount' => array(
        ' '
    ),
    'profilefield' => array(
        ' '
    )
);

/*
 * Mittels dieser Zeichenkombination werden Konfigurationsdaten, die zur Laufzeit als Array verwaltet werden,
 * zu einem String zusammengefasst und in der Admidiodatenbank gespeichert.
 * Muessen die vorgegebenen Zeichenkombinationen (#_#) jedoch ebenfalls, z.B. in der Beschreibung
 * einer Konfiguration, verwendet werden, so kann das Plugin gespeicherte Konfigurationsdaten
 * nicht mehr richtig einlesen. In diesem Fall ist die vorgegebene Zeichenkombination abzuaendern (z.B. in !-!)
 *
 * Achtung: Vor einer Aenderung muss eine Deinstallation durchgefuehrt werden!
 * Bereits gespeicherte Werte in der Datenbank koennen nach einer Aenderung nicht mehr eingelesen werden!
 */
$dbtoken = '#_#';
