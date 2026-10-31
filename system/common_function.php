<?php
/**
 ***********************************************************************************************
 * Gemeinsame Funktionen fuer das Admidio-Plugin Mitgliedsbeitrag
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Utils\StringUtils;
use Admidio\Roles\Entity\RolesRights;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Config\ConfigTable;

if (basename($_SERVER['SCRIPT_FILENAME']) === 'common_function.php') {
    exit('This page may not be called directly!');
}

require_once (__DIR__ . '/../../../system/common.php');

if (! defined('PLUGIN_FOLDER')) {
    define('PLUGIN_FOLDER', '/' . substr(dirname(__DIR__), strrpos(dirname(__DIR__), DIRECTORY_SEPARATOR) + 1));
}

spl_autoload_register('myAutoloader');

/**
 * Mein Autoloader
 * Script aus dem Netz
 * https://www.marcosimbuerger.ch/tech-blog/php-autoloader.html
 *
 * @param string $className
 *            Die übergebene Klasse
 * @return string Der überprüfte Klassenname
 */
function myAutoloader($className)
{
    // Projekt spezifischer Namespace-Prefix.
    $prefix = 'Plugins\\';

    // Base-Directory für den Namespace-Prefix.
    $baseDir = __DIR__ . '/../../';

    // Check, ob die Klasse den Namespace-Prefix verwendet.
    $len = strlen($prefix);

    if (strncmp($prefix, $className, $len) !== 0) {
        // Wenn der Namespace-Prefix nicht verwendet wird, wird abgebrochen.
        return;
    }
    // Den relativen Klassennamen ermitteln.
    $relativeClassName = substr($className, $len);

    // Den Namespace-Präfix mit dem Base-Directory ergänzen,
    // Namespace-Trennzeichen durch Verzeichnis-Trennzeichen im relativen Klassennamen ersetzen,
    // .php anhängen.
    $file = $baseDir . str_replace('\\', '/', $relativeClassName) . '.php';
    // Pfad zur Klassen-Datei zurückgeben.
    if (file_exists($file)) {
        require $file;
    }
}

/**
 * Funktion prueft, ob der Nutzer berechtigt ist das Plugin auszuführen.
 *
 * In Admidio im Modul Menü kann über 'Sichtbar für' die Sichtbarkeit eines Menüpunkts eingeschränkt werden.
 * Der Zugriff auf die darunter liegende Seite ist von dieser Berechtigung jedoch nicht betroffen.
 *
 * Mit Admidio 5 werden alle Startcripte meiner Plugins umbenannt zu index.php
 * Um die index.php auszuführen, kann die bei einem Menüpunkt angegebene URL wie folgt angegeben sein:
 * /adm_plugins/<Installationsordner des Plugins>
 * oder
 * /adm_plugins/<Installationsordner des Plugins>/
 * oder
 * /adm_plugins/<Installationsordner des Plugins>/<Dateiname.php>
 *
 * Das Installationsscript des Plugins erstellt automatisch einen Menüpunkt in der Form: /adm_plugins/<Installationsordner des Plugins>/index.php
 * Standardmäßig wird deshalb für die Prüfung index.php als <Dateiname.php> verwendet, alternativ die übergebene Datei ($scriptname).
 *
 * Diese Funktion ermittelt nur die Menüpunkte, die einen Dateinamen am Ende (index.php oder $scriptname) aufweisen, liest bei diesen Menüpunkten
 * die unter 'Sichtbar für' eingetragenen Rollen ein und prüft, ob der angemeldete Benutzer Mitglied mindestens einer dieser Rollen ist.
 * Wenn ja, ist der Benutzer berechtigt, das Plugin auszuführen (auch, wenn es weitere Menüpunkte ohne Dateinamen am Ende gibt).
 * Wichtiger Hinweis: Sind unter 'Sichtbar für' keine Rollen angegeben, so darf jeder Benutzer das Plugin ausführen
 *
 * @param string $scriptName
 *            Der Scriptname des Plugins (default: 'index.php')
 * @return bool true, wenn der User berechtigt ist
 */
function isUserAuthorized(string $scriptname = '')
{
    global $gDb, $gCurrentUser;

    $userIsAuthorized = false;
    $menIds = array();

    $menuItemURL = FOLDER_PLUGINS . PLUGIN_FOLDER . '/' . ((strlen($scriptname) === 0) ? 'index.php' : $scriptname);

    $sql = 'SELECT men_id
              FROM ' . TBL_MENU . '
             WHERE men_url = ? -- $menuItemURL';

    $menuStatement = $gDb->queryPrepared($sql, array(
        $menuItemURL
    ));

    if ($menuStatement->rowCount() !== 0) {
        while ($row = $menuStatement->fetch()) {
            $menIds[] = (int) $row['men_id'];
        }

        foreach ($menIds as $menId) {
            // read current roles rights of the menu
            $displayMenu = new RolesRights($gDb, 'menu_view', $menId);

            // check for right to show the menu
            if (count($displayMenu->getRolesIds()) === 0 || $displayMenu->hasRight($gCurrentUser->getRoleMemberships())) {
                $userIsAuthorized = true;
            }
        }
    }
    return $userIsAuthorized;
}

/**
 * Funktion prueft, ob der Nutzer berechtigt ist, das Modul Preferences aufzurufen.
 *
 * @param
 *            none
 * @return bool true, wenn der User berechtigt ist
 */
function isUserAuthorizedForPreferences()
{
    // Konfiguration einlesen
    $pPreferences = new ConfigTable();
    $pPreferences->read();

    $userIsAuthorized = false;

    if ($GLOBALS['gCurrentUser']->isAdministrator()) // Mitglieder der Rolle Administrator dürfen "Preferences" immer aufrufen
    {
        $userIsAuthorized = true;
    } else {
        foreach ($pPreferences->config['access']['preferences'] as $roleId) {
            if ($GLOBALS['gCurrentUser']->isMemberOfRole((int) $roleId)) {
                $userIsAuthorized = true;
                continue;
            }
        }
    }
    return $userIsAuthorized;
}

/**
 * Funktion um alle beitragsbezogenen Rollen einzulesen
 *
 * @param string $rollenwahl
 *            [optional] 'fix' fuer alle Fixbeitragsrollen,
 *            leer fuer alle Beitragsrollen
 * @param array $with_members
 *            [optional] Um die Rollen mit Mitgliedern einzulesen ist hier
 *            ein Array mit den einzulesenden usf_name_intern anzugeben,
 *            z.B. array('FIRST_NAME','LAST_NAME');
 *            ohne uebergebenen Parameter werden die Rollen ohne Mitglieder eingelesen
 * @return array $rollen Array mit Rollennamen im Format:<br/>
 *         $rollen[rol_id]['rolle'] =Rollenname ('rol_name')<br/>
 *         $rollen[rol_id]['rol_cost'] =Beitrag der Rollen ('rol_cost')<br/>
 *         $rollen[rol_id]['rol_cost_period'] =Beitragszeitraum ('rol_cost_period')<br/>
 *         $rollen[rol_id]['rol_timestamp_create'] =Erzeugungsdatum der Rolle ('rol_timestamp_create')<br/>
 *         $rollen[rol_id]['rol_description'] =Beschreibung ('rol_description')<br/>
 *         $rollen[rol_id]['rollentyp'] =Rollentyp ('fix')
 */
function beitragsrollen_einlesen($rollenwahl = '', $with_members = array())
{
    $rollen = array();

    // alle Rollen einlesen
    $sql = 'SELECT rol_id, rol_name, rol_cost, rol_cost_period, rol_timestamp_create, rol_description
              FROM ' . TBL_ROLES . ', ' . TBL_CATEGORIES . '
             WHERE rol_valid  = true
               AND rol_cost IS NOT NULL
               AND rol_cost_period IS NOT NULL
               AND rol_cat_id = cat_id
               AND cat_name_intern <> ?
               AND ( cat_org_id = ?
                OR cat_org_id IS NULL ) ';

    $statement = $GLOBALS['gDb']->queryPrepared($sql, array(
        'EVENTS',
        $GLOBALS['gCurrentOrgId']
    ));

    while ($row = $statement->fetch()) {
        $rollen[$row['rol_id']] = array(
            'rolle' => $row['rol_name'],
            'rol_cost' => $row['rol_cost'],
            'rol_cost_period' => $row['rol_cost_period'],
            'rol_timestamp_create' => $row['rol_timestamp_create'],
            'rol_description' => (empty($row['rol_description']) ? '' : $row['rol_description']),
            // alle Beitragsrollen sind Fixbeitragsrollen
            'rollentyp' => 'fix'
        );
    }

    // ggf. die Mitglieder der Rollen einlesen
    if (is_array($with_members) && count($with_members) > 0) {
        foreach ($rollen as $key => $data) {
            $rollen[$key]['members'] = list_members($with_members, array(
                $data['rolle'] => 0
            ));
        }
    }
    return $rollen;
}

/**
 * Liest alle Mitglieder von Rollen einer oder mehrerer Kategorie(n) ein.
 * Die cat_ids der einzulesenden Kategorien werden direkt aus der $config_ini gelesen
 *
 * @return array $members Array mit den user_ids der Mitglieder
 */
function bezugskategorie_einlesen()
{
    global $pPreferences;

    $members = array();

    // Hinweis: die Ueberpruefung, ob $config_ini['Rollenpruefung']['bezugskategorie']
    // befuellt und ein Array ist, ist in der aufrufenden Routine erfolgt

    $sql = 'SELECT DISTINCT mem_usr_id
            FROM ' . TBL_MEMBERS . ', ' . TBL_ROLES . ', ' . TBL_CATEGORIES . ' ';

    $firstpass = true;
    foreach ($pPreferences->config['Rollenpruefung']['bezugskategorie'] as $cat => $cat_id) {
        if ($cat_id == ' ') {
            return $members;
        }
        if ($firstpass) {
            $sql .= ' WHERE ( ';
        } else {
            $sql .= ' OR ( ';
        }

        $sql .= 'cat_id = ' . $cat_id . ' ';
        $sql .= ' AND mem_rol_id = rol_id
                  AND rol_valid  = true

                  AND ' . getContributionYearCondition() . '

                  AND rol_cat_id = cat_id ';
        $sql .= ' ) ';
        $firstpass = false;
    }

    $sql .= ' AND (  cat_org_id = ? -- $GLOBALS[\'gCurrentOrgId\']
              OR cat_org_id IS NULL )
              ORDER BY mem_usr_id ASC ';

    $statement = $GLOBALS['gDb']->queryPrepared($sql, array(
        $GLOBALS['gCurrentOrgId']
    ));
    while ($row = $statement->fetch()) {
        $members[] = $row['mem_usr_id'];
    }

    return $members;
}

/**
 * Liefert das eingestellte Beitragsjahr (ohne Einstellung das aktuelle Jahr).
 *
 * @return int
 */
function getContributionYear()
{
    global $pPreferences;

    // Skripte ohne eigene Konfiguration (z.B. delete_function.php) laden sie bei Bedarf
    if (!($pPreferences instanceof \Plugins\MembershipFee\classes\Config\ConfigTable)) {
        $pPreferences = new \Plugins\MembershipFee\classes\Config\ConfigTable();
        $pPreferences->read();
    }

    $year = (int) ($pPreferences->config['Beitrag']['beitrag_jahr'] ?? 0);
    return ($year > 0) ? $year : (int) substr(DATE_NOW, 0, 4);
}

/**
 * Liefert eine SQL-Bedingung (ohne fuehrendes AND), die zutrifft, wenn sich eine Mitgliedschaft
 * (mem_begin/mem_end) mit dem Beitragsjahr ueberschneidet.
 *
 * @param string $prefix
 *            optionaler Tabellenalias inkl. Punkt, z.B. 'mem.'
 * @return string
 */
function getContributionYearCondition($prefix = '')
{
    $year = getContributionYear();
    return $prefix . 'mem_begin <= \'' . sprintf('%04d-12-31', $year) . '\' AND ' . $prefix . 'mem_end >= \'' . sprintf('%04d-01-01', $year) . '\'';
}

/**
 * Liefert die user_ids der Mitglieder, die im angegebenen Beitragsjahr mindestens einen Tag
 * Mitglied waren (auch bei inzwischen beendeter Mitgliedschaft).
 *
 * @param int|null $roleId
 *            rol_id der Rolle; null = beliebige Rolle
 * @param int $year
 *            Beitragsjahr
 * @return array user_ids als Schluessel
 */
function list_members_in_year($roleId, $year)
{
    $sql = 'SELECT DISTINCT mem_usr_id
              FROM ' . TBL_MEMBERS . '
             WHERE mem_begin <= ?
               AND mem_end >= ?';
    $params = array(
        sprintf('%04d-12-31', $year),
        sprintf('%04d-01-01', $year)
    );
    if ($roleId !== null) {
        $sql .= ' AND mem_rol_id = ?';
        $params[] = (int) $roleId;
    }

    $members = array();
    $statement = $GLOBALS['gDb']->queryPrepared($sql, $params);
    while ($row = $statement->fetch()) {
        $members[$row['mem_usr_id']] = $row['mem_usr_id'];
    }

    return $members;
}

/**
 * Diese Funktion liefert als Rueckgabe die usr_ids von Rollenangehoerigen.<br/>
 * moegliche Aufrufe:<br/>
 * list_members(array('usf_name_intern1','usf_name_intern2'), array('Rollenname1' => Schalter aktiv/ehem) )<br/>
 * oder list_members(array('usf_name_intern1','usf_name_intern2'), 'Rollenname' )<br/>
 * oder list_members(array('usf_name_intern1','usf_name_intern2'), Schalter aktiv/ehem )<br/>
 * oder list_members(array('p1','p2'), Schalter aktiv/ehem )<br/>
 *
 * Schalter aktiv/ehem: 0 = Mitglieder im Beitragsjahr (Mitgliedschaft ueberschneidet das Beitragsjahr), 1 = alle anderen Mitglieder, ungleich 1 oder 0: alle Mitglieder <br/>
 *
 * Aufruf: z.B. list_members(array('FIRST_NAME','LAST_NAME'), array('Mitglied' => 0,'Administrator' => 0));
 *
 * @param array $fields
 *            Array mit usf_name_intern oder p+usfID, z.B. array('FIRST_NAME','p2')
 * @param array/string/bool $rols
 *            Array mit Rollen, z.B. <br/>
 *            array('Rollenname1' => Schalter aktiv/ehem) )<br/>
 *            oder 'Rollenname' <br/>
 *            oder Schalter aktiv/ehem <br/>
 * @param string $conditions
 *            SQL-String als zusaetzlicher Filter von $members, z.B. 'AND usd_usf_id = 78'
 * @return array $members
 */
function list_members($fields, $rols = array(), $conditions = '')
{
    global $gProfileFields;

    $members = array();
    $rowArray = array();
    $selectString = '';
    $joinString = '';
    $nameRow = '';
    $nameIntern = '';
    $inString = '';
    $timeString = '';
    $startString = 'SELECT DISTINCT mem_usr_id ';
    $mainString = '';
    $addString = '';
    $sql = '';

    foreach ($fields as $field => $data) {
        $nameRow = $data;
        $nameIntern = $data;

        if (substr($data, 0, 1) == 'p') {
            $usfID = substr($data, 1);
            $nameRow = $usfID;
            $nameIntern = $gProfileFields->getPropertyById($usfID, 'usf_name_intern');

            if ($nameIntern === '') // prüfen, ob ein 'usf_name_intern' mit der angegebenen $usfID existiert; wenn nicht, zum nächsten Feld
            {
                continue;
            }
        }

        $rowArray[] = $nameRow;
        $selectString .= ', ' . $nameIntern . '.usd_value AS "' . $nameRow . '"';

        $joinString .= 'LEFT JOIN ' . TBL_USER_DATA . ' AS ' . $nameIntern . '
                               ON ' . $nameIntern . '.usd_usr_id = mem_usr_id
                              AND ' . $nameIntern . '.usd_usf_id = ' . $gProfileFields->getProperty($nameIntern, 'usf_id') . '  ';
    }

    $mainString .= $selectString;
    $mainString .= ' FROM ' . TBL_MEMBERS . ' ';
    $mainString .= $joinString;

    $inString .= 'WHERE mem_usr_id IN (SELECT DISTINCT mem_usr_id
                   FROM ' . TBL_MEMBERS . ', ' . TBL_ROLES . ', ' . TBL_CATEGORIES . ' ';

    if (is_string($rols)) {
        $inString .= ' WHERE mem_rol_id = ' . getRoleId($rols) . ' AND ';
    } elseif (is_int($rols) && ($rols == 0)) {
        // aktive Mitglieder
        $inString .= ' WHERE ' . getContributionYearCondition() . ' AND ';
    } elseif (is_int($rols) && ($rols == 1)) {
        // nicht-aktive Mitglieder (ehemalige Mitglieder)
        $inString .= ' WHERE NOT (' . getContributionYearCondition() . ') AND ';
    } elseif (is_int($rols) && ($rols > 1 || $rols < 0)) {
        // alle Mitglieder (aktiv und nicht-aktiv)
        $inString .= ' WHERE ';
    } elseif (is_array($rols)) {
        if (sizeof($rols) == 1) {
            $timeString = ', mem_begin, mem_end ';
            $rowArray[] = 'mem_begin';
            $rowArray[] = 'mem_end';

            reset($rols); // nur zur Sicherheit, falls eine Funktion vorher den Array-Zeiger verändert hat
            $roleKey = key($rols);
            $roleValue = current($rols);

            $addString .= ' ) AND mem_rol_id = ' . getRoleId($roleKey) . ' ';
            if ($roleValue == 0) {
                // aktive Mitglieder
                $addString .= ' AND ' . getContributionYearCondition();
            } elseif ($roleValue == 1) {
                // nicht aktive Mitglieder (ehemalige Mitglieder)
                $addString .= ' AND NOT (' . getContributionYearCondition() . ') ';
            }
            $inString .= ' WHERE (';
        } else {
            $firstpass = true;
            foreach ($rols as $rol => $rol_switch) {
                if ($firstpass) {
                    $inString .= ' WHERE (( ';
                } else {
                    $inString .= ' OR ( ';
                }
                $inString .= 'mem_rol_id = ' . getRoleId($rol) . ' ';

                if ($rol_switch == 0) {
                    // aktive Mitglieder
                    $inString .= ' AND ' . getContributionYearCondition();
                } elseif ($rol_switch == 1) {
                    // nicht aktive Mitglieder (ehemalige Mitglieder)
                    $inString .= ' AND NOT (' . getContributionYearCondition() . ')';
                }
                $inString .= ' ) ';
                $firstpass = false;
            }
            $inString .= ' ) AND ';
        }
    }

    $inString .= '  mem_rol_id = rol_id
                   AND rol_valid  = true
                   AND rol_cat_id = cat_id
                   AND ( cat_org_id = ' . $GLOBALS['gCurrentOrgId'] . '
                    OR cat_org_id IS NULL ) ) ';

    $mainString .= $inString;

    $sql .= $startString . $timeString . $mainString . $addString . $conditions;
    $sql .= ' ORDER BY mem_usr_id ASC ';

    $statement = $GLOBALS['gDb']->queryPrepared($sql);
    while ($row = $statement->fetch()) {
        $members[$row['mem_usr_id']] = array();
        foreach ($rowArray as $key) {
            if ($key === 'FEE' . $GLOBALS['gCurrentOrgId']) {
                $members[$row['mem_usr_id']][$key] = normalize_fee($row[$key]);
            } else {
                $members[$row['mem_usr_id']][$key] = (string) $row[$key];
            }
        }
    }
    return $members;
}

/**
 * Normalisiert verschiedene Zahlenformate in einen Float-Wert.
 * Akzeptiert z.B. "1.234,56 €", "1234.56", "1,234.56" etc.
 * Beitrag von tt2g89 (GitHub #163)
 */
function normalize_fee($value)
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_array($value)) {
        return 0.0;
    }
    $v = (string) $value;
    $v = trim($v);
    // Entfernt alles außer Ziffern, Minus, Punkt und Komma
    $v = preg_replace('/[^\d\-\.,]/u', '', $v);
    // Falls sowohl Punkt als auch Komma vorkommen, annehmen: '.' Tausender, ',' Dezimal
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        if (strpos($v, ',') !== false) {
            $v = str_replace(',', '.', $v);
        }
    }
    if ($v === '' || ! is_numeric($v)) {
        return 0.0;
    }
    return (float) $v;
}

/**
 * Callbackfunktion fuer array_filter ,
 * die globale Variable $delete_NULL_field muß in der uebergebenden Routine definiert sein
 *
 * @param string $wert
 * @return bool
 */
function delete_NULL($wert)
{
    global $delete_NULL_field;

    return $wert[$delete_NULL_field] != NULL;
}

/**
 * Funktion liest die Rollen-ID einer Rolle aus
 *
 * @param string $role_name
 *            Name der zu pruefenden Rolle
 * @return int rol_id Rol_id der Rolle, 0, wenn nicht gefunden
 */
function getRoleId($role_name)
{
    $sql = 'SELECT rol_id
              FROM ' . TBL_ROLES . ', ' . TBL_CATEGORIES . '
             WHERE rol_name  = ? -- $role_name
               AND rol_valid  = true
               AND rol_cat_id = cat_id
               AND ( cat_org_id = ? -- $GLOBALS[\'gCurrentOrgId\']
                OR cat_org_id IS NULL ) ';

    $queryParams = array(
        $role_name,
        $GLOBALS['gCurrentOrgId']
    );

    $statement = $GLOBALS['gDb']->queryPrepared($sql, $queryParams);

    $row = $statement->fetchObject();
    if (isset($row->rol_id) && strlen($row->rol_id) > 0) {
        return $row->rol_id;
    } else {
        return 0;
    }
}

/**
 * Konvertiert einen numerischen Beitragszeitraum in einen String
 *
 * @param int $my_rol_cost_period
 *            Beitragszeitraum als int
 * @return string Beitragszeitraum als String
 */
function getCostPeriod($my_rol_cost_period)
{
    if ($my_rol_cost_period == - 1) {
        return $GLOBALS['gL10n']->get('ROL_UNIQUELY');
    } elseif ($my_rol_cost_period == 1) {
        return $GLOBALS['gL10n']->get('ROL_ANNUALLY');
    } elseif ($my_rol_cost_period == 2) {
        return $GLOBALS['gL10n']->get('ROL_SEMIYEARLY');
    } elseif ($my_rol_cost_period == 4) {
        return $GLOBALS['gL10n']->get('ROL_QUARTERLY');
    } elseif ($my_rol_cost_period == 12) {
        return $GLOBALS['gL10n']->get('ROL_MONTHLY');
    } else {
        return '--';
    }
}

/**
 * Erzeugt Array mit Daten fuer die Analyse
 *
 * @return array $ret Array im Format:<br/>
 *         $ret['BEITRAG_kto'] = Gesamtsumme der Beitraege mit Kto-Verbindung<br/>
 *         $ret['BEITRAG_kto_anzahl'] = Anzahl mit Kto-Verbindung<br/>
 *         $ret['BEZAHLT_kto'] = Gesamtsumme mit Bezahlt und mit Kto-Verbindung<br/>
 *         $ret['BEZAHLT_kto_anzahl'] = Anzahl mit Bezahlt und mit Kto-Verbindung<br/>
 *         $ret['BEITRAG_rech'] = Gesamtsumme der Beitraege ohne Kto-Verbindung<br/>
 *         $ret['BEITRAG_rech_anzahl'] = Anzahl ohne Kto-Verbindung<br/>
 *         $ret['BEZAHLT_rech'] = Gesamtsumme mit Bezahlt und ohne Kto-Verbindung<br/>
 *         $ret['BEZAHLT_rech_anzahl'] = Anzahl mit Bezahlt und ohne Kto-Verbindung
 */
function analyse_mem()
{
    $members = list_members(array(
        'FEE' . $GLOBALS['gCurrentOrgId'],
        'CONTRIBUTORY_TEXT' . $GLOBALS['gCurrentOrgId'],
        'PAID' . $GLOBALS['gCurrentOrgId'],
        'IBAN',
        'DEBTOR'
    ), 2); // alle Mitglieder: berechnete Beitraege gelten auch fuer inzwischen ausgetretene Mitglieder
    $ret = array(
        'data' => $members,
        'BEITRAG_kto' => 0,
        'BEITRAG_kto_anzahl' => 0,
        'BEITRAG_rech' => 0,
        'BEITRAG_rech_anzahl' => 0,
        'BEZAHLT_kto' => 0,
        'BEZAHLT_kto_anzahl' => 0,
        'BEZAHLT_rech' => 0,
        'BEZAHLT_rech_anzahl' => 0
    );

    // alle Mitglieder durchlaufen und im ersten Schritt alle Mitglieder,
    // bei denen kein Beitrag berechnet wurde, herausfiltern
    foreach ($members as $member => $memberdata) {
        if (empty($memberdata['FEE' . $GLOBALS['gCurrentOrgId']])) {
            unset($members[$member]);
        }
    }

    // jetzt wird gezaehlt
    foreach ($members as $member => $memberdata) {
        if (! empty($memberdata['IBAN'])) {
            $ret['BEITRAG_kto'] += $memberdata['FEE' . $GLOBALS['gCurrentOrgId']];
            $ret['BEITRAG_kto_anzahl'] += 1;
        }
        if ((! empty($memberdata['IBAN'])) && ! empty($memberdata['PAID' . $GLOBALS['gCurrentOrgId']])) {
            $ret['BEZAHLT_kto'] += $memberdata['FEE' . $GLOBALS['gCurrentOrgId']];
            $ret['BEZAHLT_kto_anzahl'] += 1;
        }
        if (empty($memberdata['IBAN'])) {
            $ret['BEITRAG_rech'] += $memberdata['FEE' . $GLOBALS['gCurrentOrgId']];
            $ret['BEITRAG_rech_anzahl'] += 1;
        }
        if (empty($memberdata['IBAN']) && ! empty($memberdata['PAID' . $GLOBALS['gCurrentOrgId']])) {
            $ret['BEZAHLT_rech'] += $memberdata['FEE' . $GLOBALS['gCurrentOrgId']];
            $ret['BEZAHLT_rech_anzahl'] += 1;
        }
    }
    return $ret;
}

/**
 * Erzeugt Array mit Daten fuer die Analyse
 *
 * @return array $ret
 */
function analyse_rol()
{

    $ret = beitragsrollen_einlesen('fix');
    foreach ($ret as $rol => $roldata) {
        $ret[$rol]['members'] = list_members_in_year($rol, getContributionYear());
    }

    return $ret;
}

/**
 * Prueft die Pflicht-Rollenmitgliedschaften
 *
 * @return array $ret
 */
function check_rollenmitgliedschaft_pflicht()
{
    global $pPreferences, $gProfileFields;
    $ret = array();
    $user = new User($GLOBALS['gDb'], $gProfileFields);

    // alle Beitragsrollen einlesen; die Rollenmitglieder werden anhand des Beitragsjahres bestimmt
    $beitragsrollen = beitragsrollen_einlesen();
    foreach ($beitragsrollen as $rol => $roldata) {
        $beitragsrollen[$rol]['members'] = list_members_in_year($rol, getContributionYear());
    }
    // geprueft werden alle Mitglieder, die im Beitragsjahr in irgendeiner Rolle Mitglied waren
    $yearMembers = list_members_in_year(null, getContributionYear());
    $members = list_members(array(
        'FIRST_NAME',
        'LAST_NAME'
    ), 2);
    foreach ($members as $member => $memberdata) {
        if (! isset($yearMembers[$member])) {
            unset($members[$member]);
        }
    }

    // alle Beitragsrollen durchlaufen und diejenigen Rollen loeschen, die nicht als Pflichtrolle definiert sind
    foreach ($beitragsrollen as $rol => $roldata) {
        // alle if und elseif koennte man in einer Zeile schreiben und mit || verknuepfen, aber so ist es uebersichtlicher
        if (($roldata['rollentyp'] == 'fix') && (! is_array($pPreferences->config['Rollenpruefung']['fixrollenpflicht']))) {
            unset($beitragsrollen[$rol]);
        } elseif (($roldata['rollentyp'] == 'fix') && (is_array($pPreferences->config['Rollenpruefung']['fixrollenpflicht'])) && ! (in_array($rol, $pPreferences->config['Rollenpruefung']['fixrollenpflicht']))) {
            unset($beitragsrollen[$rol]);
        }
    }
    // in $beitragsrollen sind jetzt nur noch Pflicht-Beitragsrollen

    // Feature-Wunsch von joesch
    if ($pPreferences->config['Rollenpruefung']['bezugskategorie'][0] != ' ') {
        // zuerst alle Member der Bezugskategorien einlesen
        $bezugskategorieMembers = bezugskategorie_einlesen();

        foreach ($members as $member => $memberdata) {
            // alle usr_ids loeschen, wenn sie nicht innerhalb der Bezugskategorie sind
            if (! in_array($member, $bezugskategorieMembers)) {
                unset($members[$member]);
            }
        }
    }

    // alle Mitglieder durchlaufen und pruefen, ob sie in mind. einer Pflicht-Beitragsrolle sind
    foreach ($members as $member => $memberdata) {
        $marker = false;
        foreach ($beitragsrollen as $rol => $roldata) {
            if (array_key_exists($member, $roldata['members'])) {
                $marker = true;
            }
        }
        if (! $marker) {
            $user->readDataById($member);
            $ret[] .= '- <a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $memberdata['LAST_NAME'] . ', ' . $memberdata['FIRST_NAME'] . '</a>';
        }
    }

    if (count($ret) === 0) {
        $ret = array(
            $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_DUTY_RESULT_OK')
        );
    } else {
        $ret[] = '<br/><strong>=> ' . $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_DUTY_RESULT_ERROR') . '</strong>';
    }
    return $ret;
}

/**
 * Prueft die Auschluss-Rollenmitgliedschaften
 *
 * @return array $ret
 */
function check_rollenmitgliedschaft_ausschluss()
{
    global $pPreferences, $gProfileFields;
    $ret = array();
    $user = new User($GLOBALS['gDb'], $gProfileFields);

    // alle Beitragsrollen einlesen; die Rollenmitglieder werden anhand des Beitragsjahres bestimmt
    $beitragsrollen = beitragsrollen_einlesen();
    foreach ($beitragsrollen as $rol => $roldata) {
        $beitragsrollen[$rol]['members'] = list_members_in_year($rol, getContributionYear());
    }
    // alle Mitglieder (auch inzwischen ausgetretene), geprueft werden nur Mitglieder von Beitragsrollen
    $members = list_members(array(
        'FIRST_NAME',
        'LAST_NAME'
    ), 2);

    // alle Beitragsrollen durchlaufen und fuer jedes Mitglied seine Rollenzugehoerigkeiten bestimmen
    foreach ($beitragsrollen as $rol => $roldata) {
        foreach ($roldata['members'] as $key => $dummy) {
            if (! isset($members[$key]['rollen'])) {
                $members[$key]['rollen'] = array();
            }

            $members[$key]['rollen'][] = $rol;
        }
    }

    // Feature-Wunsch von joesch
    if ($pPreferences->config['Rollenpruefung']['bezugskategorie'][0] != ' ') {
        // zuerst alle Member der Bezugskategorien einlesen
        $bezugskategorieMembers = bezugskategorie_einlesen();

        foreach ($members as $member => $memberdata) {
            // alle usr_ids loeschen, wenn sie nicht innerhalb der Bezugskategorie sind
            if (! in_array($member, $bezugskategorieMembers)) {
                unset($members[$member]);
            }
        }
    }

    // alle Mitglieder durchlaufen und pruefen, ob sie in Ausschluss-Beitragsrollen sind
    foreach ($members as $member => $memberdata) {
        // falls das Mitglied kein Angehoeriger einer Beitragsrolle ist: abbrechen und zum naechsten Datensatz gehen
        if (! isset($memberdata['rollen'])) {
            continue;
        }

        $marker = false;
        if (is_array($pPreferences->config['Rollenpruefung']['fixrollenfixrollen'])) {
            foreach ($pPreferences->config['Rollenpruefung']['fixrollenfixrollen'] as $roldata) {
                $fixRols = explode('_', $roldata);
                if ((in_array($fixRols[0], $memberdata['rollen'])) && (in_array($fixRols[1], $memberdata['rollen']))) {
                    $marker = true;
                }
            }
        }

        if ($marker) {
            $user->readDataById($member);
            $ret[] .= '- <a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $memberdata['LAST_NAME'] . ', ' . $memberdata['FIRST_NAME'] . '</a>';
        }
    }

    if (count($ret) === 0) {
        $ret = array(
            $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_EXCLUSION_RESULT_OK')
        );
    } else {
        $ret[] = '<br/><strong>=> ' . $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_EXCLUSION_RESULT_ERROR') . '</strong>';
    }
    return $ret;
}

/**
 * Prueft, ob Mandats-ID und Mandatsdatum vorhanden sind
 * (Voraussetzung: IBAN und Mitgliedsbeitrag dürfen nicht leer)
 *
 * @return array $ret
 */
function check_mandate_management()
{
    global $gProfileFields;
    $ret = array();
    $user = new User($GLOBALS['gDb'], $gProfileFields);

    $members = list_members(array(
        'FIRST_NAME',
        'LAST_NAME',
        'IBAN',
        'FEE' . $GLOBALS['gCurrentOrgId'],
        'MANDATEID' . $GLOBALS['gCurrentOrgId'],
        'MANDATEDATE' . $GLOBALS['gCurrentOrgId']
    ), 0);

    foreach ($members as $member => $memberdata) {
        if ((strlen($memberdata['IBAN']) !== 0) && (strlen($memberdata['FEE' . $GLOBALS['gCurrentOrgId']]) !== 0) && ((strlen($memberdata['MANDATEID' . $GLOBALS['gCurrentOrgId']]) === 0) || (strlen($memberdata['MANDATEDATE' . $GLOBALS['gCurrentOrgId']]) === 0))) {
            $user->readDataById($member);
            $ret[] = '- <a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $memberdata['LAST_NAME'] . ', ' . $memberdata['FIRST_NAME'] . '</a>';
        }
    }

    if (count($ret) === 0) {
        $ret = array(
            $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT_RESULT_OK')
        );
    } else {
        $ret[] = '<br/><strong>=> ' . $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT_RESULT_ERROR') . '</strong>';
    }
    return $ret;
}

/**
 * Prueft, ob bei Angabe eines Kontoinhabers auch KtoInh-Ort, KtoInh-PLZ und KtoInh-Straße vorhanden sind
 *
 * @return array $ret
 */
function check_account_details()
{
    global $gProfileFields, $gDb, $gL10n;
    $ret = array();
    $user = new User($gDb, $gProfileFields);

    $members = list_members(array(
        'FIRST_NAME',
        'LAST_NAME',
        'DEBTOR',
        'DEBTOR_POSTCODE',
        'DEBTOR_CITY',
        'DEBTOR_STREET'
    ), 0);

    foreach ($members as $member => $memberdata) {
        if ((strlen((string) $memberdata['DEBTOR']) !== 0) && ((strlen((string) $memberdata['DEBTOR_POSTCODE']) === 0) || (strlen($memberdata['DEBTOR_CITY']) === 0) || (strlen($memberdata['DEBTOR_STREET']) === 0))) {
            $user->readDataById($member);
            $ret[] = '- <a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $memberdata['LAST_NAME'] . ', ' . $memberdata['FIRST_NAME'] . '</a>';
        }
    }

    if (count($ret) === 0) {
        $ret = array(
            $gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA_TEST_RESULT_OK')
        );
    } else {
        $ret[] = '<br/><strong>=> ' . $gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA_TEST_RESULT_ERROR') . '</strong>';
    }
    return $ret;
}

/**
 * Durchlaeuft alle Mitglieder und prueft deren IBAN
 *
 * @return array $ret
 */
function check_iban()
{
    global $gProfileFields;
    $ret = array();
    $user = new User($GLOBALS['gDb'], $gProfileFields);

    $members = list_members(array(
        'FIRST_NAME',
        'LAST_NAME',
        'IBAN'
    ), 0);

    foreach ($members as $member => $memberdata) {
        if ((strlen($memberdata['IBAN']) === 1) || ((strlen($memberdata['IBAN']) > 1) && ! test_iban($memberdata['IBAN']))) {
            $user->readDataById($member);
            $ret[] = '- <a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $memberdata['LAST_NAME'] . ', ' . $memberdata['FIRST_NAME'] . '</a>';
        }
    }

    if (count($ret) === 0) {
        $ret = array(
            $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_IBANCHECK_RESULT_OK')
        );
    } else {
        $ret[] = '<br/><strong>=> ' . $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_IBANCHECK_RESULT_ERROR') . '</strong>';
    }
    return $ret;
}

/**
 * Prueft die uebergebene IBAN
 *
 * @param string $iban
 * @return bool
 */
function test_iban($iban)
{
    // von karelvh
    $iban = strtolower(str_replace(' ', '', $iban));
    $Countries = array(
        'al' => 28,
        'ad' => 24,
        'at' => 20,
        'az' => 28,
        'bh' => 22,
        'be' => 16,
        'ba' => 20,
        'br' => 29,
        'bg' => 22,
        'cr' => 21,
        'hr' => 21,
        'cy' => 28,
        'cz' => 24,
        'dk' => 18,
        'do' => 28,
        'ee' => 20,
        'fo' => 18,
        'fi' => 18,
        'fr' => 27,
        'ge' => 22,
        'de' => 22,
        'gi' => 23,
        'gr' => 27,
        'gl' => 18,
        'gt' => 28,
        'hu' => 28,
        'is' => 26,
        'ie' => 22,
        'il' => 23,
        'it' => 27,
        'jo' => 30,
        'kz' => 20,
        'kw' => 30,
        'lv' => 21,
        'lb' => 28,
        'li' => 21,
        'lt' => 20,
        'lu' => 20,
        'mk' => 19,
        'mt' => 31,
        'mr' => 27,
        'mu' => 30,
        'mc' => 27,
        'md' => 24,
        'me' => 22,
        'nl' => 18,
        'no' => 15,
        'pk' => 24,
        'ps' => 29,
        'pl' => 28,
        'pt' => 25,
        'qa' => 29,
        'ro' => 24,
        'sm' => 27,
        'sa' => 24,
        'rs' => 22,
        'sk' => 24,
        'si' => 19,
        'es' => 24,
        'se' => 24,
        'ch' => 21,
        'tn' => 24,
        'tr' => 26,
        'ae' => 23,
        'gb' => 22,
        'vg' => 24
    );
    $Chars = array(
        'a' => 10,
        'b' => 11,
        'c' => 12,
        'd' => 13,
        'e' => 14,
        'f' => 15,
        'g' => 16,
        'h' => 17,
        'i' => 18,
        'j' => 19,
        'k' => 20,
        'l' => 21,
        'm' => 22,
        'n' => 23,
        'o' => 24,
        'p' => 25,
        'q' => 26,
        'r' => 27,
        's' => 28,
        't' => 29,
        'u' => 30,
        'v' => 31,
        'w' => 32,
        'x' => 33,
        'y' => 34,
        'z' => 35
    );

    if (array_key_exists(substr($iban, 0, 2), $Countries) && strlen($iban) == $Countries[substr($iban, 0, 2)]) {
        $MovedChar = substr($iban, 4) . substr($iban, 0, 4);
        $MovedCharArray = str_split($MovedChar);
        $NewString = "";

        foreach ($MovedCharArray as $key => $value) {
            if (! is_numeric($MovedCharArray[$key])) {
                $MovedCharArray[$key] = $Chars[$MovedCharArray[$key]];
            }
            $NewString .= $MovedCharArray[$key];
        }

        if (bcmod($NewString, '97') == 1) {
            return TRUE;
        } else {
            return FALSE;
        }
    } else {
        return FALSE;
    }
}

/**
 * Durchläuft alle Mitglieder und prueft ob ein BIC vorhanden ist,
 * falls das Mitglied aus einem Land außerhalb EU/EWR stammt
 * Prueft die Kontodaten des Vereins ob ein BIC vorhanden ist, falls der Verein
 * aus einem Land außerhalb EU/EWR stammt
 * Überprüft die BIC der Mitglieder und des Vereins auf das gültige Format
 *
 * @return array $ret
 */
function check_bic()
{
    global $pPreferences, $gProfileFields, $gL10n, $gDb, $gCurrentOrganization;
    $ret = array();
    $ret2 = array();
    $retSum = array();
    $user = new User($gDb, $gProfileFields);

    // Prüf-Pattern für den BIC
    $pattern = '/^([a-zA-Z]{4})([a-zA-Z]{2})(([2-9a-zA-Z]{1})([0-9a-np-zA-NP-Z]{1}))((([0-9a-wy-zA-WY-Z]{1})([0-9a-zA-Z]{2}))|([xX]{3})|)$/';
    // $pattern = '/^[A-Z]{6,6}[A-Z2-9][A-NP-Z0-9]([A-Z0-9]{3,3}){0,1}?$/'; // auch möglich, aber nicht so detailliert
    // $pattern = '/^[A-Z0-9]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/'; // auch möglich, aber nicht so detailliert

    $members = list_members(array(
        'FIRST_NAME',
        'LAST_NAME',
        'IBAN',
        'BIC'
    ), 0);

    foreach ($members as $member => $memberdata) {
        if (isIbanNOT_EU_EWR($memberdata['IBAN']) && empty($memberdata['BIC'])) {
            $user->readDataById($member);
            $ret[] = '- <a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $memberdata['LAST_NAME'] . ', ' . $memberdata['FIRST_NAME'] . '</a>';
        }

        if (! empty($memberdata['BIC'])) {
            if (! preg_match($pattern, $memberdata['BIC'])) {
                $user->readDataById($member);
                $ret2[] = '- <a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                    'user_uuid' => $user->getValue('usr_uuid')
                )) . '">' . $memberdata['LAST_NAME'] . ', ' . $memberdata['FIRST_NAME'] . ' => ' . $memberdata['BIC'] . '</a>';
            }
        }
    }

    if (isIbanNOT_EU_EWR($pPreferences->config['Kontodaten']['iban']) && empty($pPreferences->config['Kontodaten']['bic'])) {
        $ret[] = '- ' . $gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA') . ' ' . $gCurrentOrganization->getValue('org_longname');
    }
    if (! empty($pPreferences->config['Kontodaten']['bic']) && ! preg_match($pattern, $pPreferences->config['Kontodaten']['bic'])) {
        $ret2[] = '- ' . $gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA') . ' ' . $gCurrentOrganization->getValue('org_longname');
    }

    $retSum[] = '<strong>1: ' . $gL10n->get('PLG_MEMBERSHIPFEE_BICCHECK_DESC') . '</strong><br/>';
    if (count($ret) === 0) {
        $retSum[] = $gL10n->get('PLG_MEMBERSHIPFEE_BICCHECK_RESULT_OK');
    } else {
        $retSum = array_merge($retSum, $ret);
        $retSum[] = '<br/><strong>=> ' . $gL10n->get('PLG_MEMBERSHIPFEE_BICCHECK_RESULT_ERROR') . '</strong>';
    }

    $retSum[] = '<br/><strong>2: ' . $gL10n->get('PLG_MEMBERSHIPFEE_BICCHECK2_DESC') . '</strong><br/>';

    if (count($ret2) === 0) {
        $retSum[] = $gL10n->get('PLG_MEMBERSHIPFEE_BICCHECK2_RESULT_OK');
    } else {
        $retSum = array_merge($retSum, $ret2);
        $retSum[] = '<br/><strong>=> ' . $gL10n->get('PLG_MEMBERSHIPFEE_BICCHECK2_RESULT_ERROR') . '</strong>';
    }

    return $retSum;
}

/**
 * Prueft, ob die uebergebene IBAN zu einem Land außerhalb EU/EWR gehoert
 *
 * @param string $iban
 * @return bool
 */
function isIbanNOT_EU_EWR($iban)
{
    $iban_land = strtoupper(substr(str_replace(' ', '', (string) $iban), 0, 2));

    $countries = array(
        'CH', // Schweiz
        'MC', // Monaco
        'SM', // San Marino
        'JE', // Jersey
        'GG', // Guernsey
        'IM', // Isle of Man
               // 'GB', //Großbritannien (je nach Brexit-Vereinbarung)
        'PM'
    ); // St. Pierre und Miquelon

    if (in_array($iban_land, $countries)) {
        return true;
    } else {
        return false;
    }
}

/**
 * Formatiert den uebergebenen Datumsstring fuer MySQL,
 * date_format2mysql ersetzt date_german2mysql (erstellt von eiseli)
 *
 * @param string $date
 *            Datumsstring
 * @return DateTime Datum im Format Y-m-d
 */
function date_format2mysql($date)
{
    return date('Y-m-d', strtotime($date));
}

/**
 * Callbackfunktion fuer array_filter
 *
 * @param string $wert
 * @return bool true, wenn Beitrag != NULL ist
 */
function delete_without_BEITRAG($wert)
{
    return $wert['FEE' . $GLOBALS['gCurrentOrgId']] != NULL;
}

/**
 * Callbackfunktion fuer array_filter
 *
 * @param string $wert
 * @return bool true, wenn IBAN != NULL ist
 */
function delete_without_IBAN($wert)
{
    return $wert['IBAN'] != NULL;
}

/**
 * Callbackfunktion fuer array_filter
 *
 * @param string $wert
 * @return bool true, wenn BIC != NULL ist
 */
function delete_without_BIC($wert)
{
    return $wert['BIC'] != NULL;
}

/**
 * Callbackfunktion fuer array_filter
 *
 * @param string $wert
 * @return bool true, wenn MandateID == NULL ist
 */
function delete_with_MANDATEID($wert)
{
    return ! ($wert['MANDATEID' . $GLOBALS['gCurrentOrgId']] != NULL);
}

/**
 * Callbackfunktion fuer array_filter
 *
 * @param string $wert
 * @return bool true, wenn Bezahlt == NULL ist
 */
function delete_with_BEZAHLT($wert)
{
    return ! ($wert['PAID' . $GLOBALS['gCurrentOrgId']] != NULL);
}

/**
 * Callbackfunktion fuer array_filter
 *
 * @param string $wert
 * @return bool true, wenn MandateID != NULL ist
 */
function delete_without_MANDATEID($wert)
{
    return $wert['MANDATEID' . $GLOBALS['gCurrentOrgId']] != NULL;
}

/**
 * Callbackfunktion fuer array_filter
 *
 * @param string $wert
 * @return bool true, wenn MandateID != NULL ist
 */
function delete_without_MANDATEDATE($wert)
{
    return $wert['MANDATEDATE' . $GLOBALS['gCurrentOrgId']] != NULL;
}

/**
 * Ersetzt Umlaute
 *
 * @param string $tmptext
 * @return string $tmptext
 */
function umlaute($tmptext)
{
    // Autor: guenter47
    // angepasst wegen einem Fehler bei der Umsetzung von ß
    $tmptext = htmlentities($tmptext);
    $tmptext = str_replace('&uuml;', 'ue', $tmptext);
    $tmptext = str_replace('&auml;', 'ae', $tmptext);
    $tmptext = str_replace('&ouml;', 'oe', $tmptext);
    $tmptext = str_replace('&szlig;', 'ss', $tmptext);
    $tmptext = str_replace('&Uuml;', 'Ue', $tmptext);
    $tmptext = str_replace('&Auml;', 'Ae', $tmptext);
    $tmptext = str_replace('&Ouml;', 'Oe', $tmptext);
    return $tmptext;
}

/**
 * Ersetzt und entfernt unzulaessige Zeichen in der SEPA-XML-Datei
 *
 * @param string $tmptext
 * @return string $ret
 */
function replace_sepadaten($tmptext)
{
    /*
     * Zulaessige Zeichen
     * Fuer die Erstellung von SEPA-Nachrichten sind die folgenden Zeichen in der
     * Kodierung gemaess UTF-8 bzw. ISO-885933 zugelassen.
     * ---------------------------------------------------
     * Zugelassener Zeichencode| Zeichen | Hexcode
     * Numerische Zeichen | 0 bis 9 | X'30' bis X'39'
     * Großbuchstaben | A bis Z | X'41' bis X'5A'
     * Kleinbuchstaben | a bis z | X'61' bis 'X'7A'
     * Apostroph | ' | X'27
     * Doppelpunkt | : | X'3A
     * Fragezeichen | ? | X'3F
     * Komma | , | X'2C
     * Minus | - | X'2D
     * Leerzeichen | | X'20
     * Linke Klammer | ( | X'28
     * Pluszeichen | + | X'2B
     * Punkt | . | X'2E
     * Rechte Klammer | ) | X'29
     * Schraegstrich | / | X'2F
     */
    $charMap = array(
        'Ä' => 'Ae',
        'ä' => 'ae',
        'À' => 'A',
        'à' => 'a',
        'Á' => 'A',
        'á' => 'a',
        'Â' => 'A',
        'â' => 'a',
        'Æ' => 'AE',
        'æ' => 'ae',
        'Ã' => 'A',
        'ã' => 'a',
        'Å' => 'A',
        'å' => 'a',
        'Ç' => 'C',
        'ç' => 'c',
        'Ë' => 'E',
        'ë' => 'e',
        'È' => 'E',
        'è' => 'e',
        'É' => 'E',
        'é' => 'e',
        'Ê' => 'E',
        'ê' => 'e',
        'Ï' => 'I',
        'ï' => 'i',
        'Ì' => 'I',
        'ì' => 'i',
        'Í' => 'I',
        'í' => 'i',
        'Î' => 'I',
        'î' => 'i',
        'ß' => 'ss',
        'Ñ' => 'N',
        'ñ' => 'n',
        'Œ' => 'OE',
        'œ' => 'oe',
        'Ö' => 'Oe',
        'ö' => 'oe',
        'Ò' => 'O',
        'ò' => 'o',
        'Ó' => 'O',
        'ó' => 'o',
        'Ô' => 'O',
        'ô' => 'o',
        'Õ' => 'O',
        'õ' => 'o',
        'Ø' => 'O',
        'ø' => 'o',
        'ß' => 'ss',
        'Ü' => 'Ue',
        'ü' => 'ue',
        'Ù' => 'U',
        'ù' => 'u',
        'Ú' => 'U',
        'ú' => 'u',
        'Û' => 'U',
        'û' => 'u',
        'ÿ' => 'y',
        'Ý' => 'Y',
        'ý' => 'y',
        '€' => 'EUR',
        '*' => '.',
        '$' => '.',
        '%' => '.',
        '&' => '+'
    );

    $ret = str_replace(array_keys($charMap), array_values($charMap), $tmptext);

    for ($i = 0; $i < strlen($ret); $i ++) {
        if (preg_match('/[^A-Za-z0-9\'\:\?\,\-\(\+\.\)\/]/', substr($ret, $i, 1))) {
            $ret = substr_replace($ret, ' ', $i, 1);
        }
    }
    return $ret;
}

/**
 * Ersetzt Parameter im E-Mail-Text
 *
 * @param string $text
 *            Die E-Mail-Nachricht mit Parametern
 * @param Database $user
 *            Object of the class User
 * @return string $text Die E-Mail-Nachricht mit ersetzten Werten
 */
function replace_emailparameter($text, $user)
{
    global $pPreferences, $gCurrentOrgId, $gCurrentOrganization;

    // now replace all parameters in email text
    $text = preg_replace('/#user_first_name#/', $user->getValue('FIRST_NAME'), $text);
    $text = preg_replace('/#user_last_name#/', $user->getValue('LAST_NAME'), $text);
    $text = preg_replace('/#street#/', $user->getValue('STREET'), $text);
    $text = preg_replace('/#postcode#/', $user->getValue('POSTCODE'), $text);
    $text = preg_replace('/#city#/', $user->getValue('CITY'), $text);
    $text = preg_replace('/#email#/', $user->getValue('EMAIL'), $text);
    $text = preg_replace('/#phone#/', $user->getValue('PHONE'), $text);
    $text = preg_replace('/#mobile#/', $user->getValue('MOBILE'), $text);
    $text = preg_replace('/#birthday#/', $user->getValue('BIRTHDAY'), $text);
    $text = preg_replace('/#organization_long_name#/', $gCurrentOrganization->getValue('org_longname'), $text);
    $text = preg_replace('/#fee#/', $user->getValue('FEE' . $gCurrentOrgId), $text);
    $text = preg_replace('/#due_day#/', $user->getValue('DUEDATE' . $gCurrentOrgId), $text);
    $text = preg_replace('/#mandate_id#/', $user->getValue('MANDATEID' . $gCurrentOrgId), $text);
    $text = preg_replace('/#mandate_date#/', $user->getValue('MANDATEDATE' . $gCurrentOrgId), $text);
    $text = preg_replace('/#creditor_id#/', $pPreferences->config['Kontodaten']['ci'], $text);
    $text = preg_replace('/#iban#/', $user->getValue('IBAN'), $text);
    $text = preg_replace('/#bic#/', $user->getValue('BIC'), $text);
    $text = preg_replace('/#bank#/', $user->getValue('BANK'), $text);
    if ($user->getValue('DEBTOR') != '') {
        $text = preg_replace('/#debtor#/', $user->getValue('DEBTOR'), $text);
    } else {
        $text = preg_replace('/#debtor#/', $user->getValue('FIRST_NAME') . ' ' . $user->getValue('LAST_NAME'), $text);
    }
    $text = preg_replace('/#membership_fee_text#/', $user->getValue('CONTRIBUTORY_TEXT' . $gCurrentOrgId), $text);
    $text = preg_replace('/#iban_obfuscated#/', obfuscate_iban($user->getValue('IBAN')), $text);
    $text = preg_replace('/#membernumber#/', $user->getValue('MEMBERNUMBER' . $gCurrentOrgId), $text);

    return $text;
}

/**
 * Wandelt Rollentyp von Kurzform in Langform um
 *
 * @param string $rollentyp
 *            Rollentyp in Kurzform ('fix')
 * @return string $ret Rollentyp in Langform
 */
function expand_rollentyp($rollentyp = '')
{
    return $GLOBALS['gL10n']->get('PLG_MEMBERSHIPFEE_OTHER_CONTRIBUTION_ROLES');
}

/**
 * Verschleiert die IBAN für den Versand durch Aus-X-en der 8.
 * bis 17. Ziffer
 *
 * @param string $iban
 *            IBAN des Users
 * @return string verschleierte IBAN (z.B. 'DE1234567xxxxxxxxxx123', 'DE12 3456 7xxx xxxx xxx1 23', ...)
 */
function obfuscate_iban($iban)
{
    $pos = 0;
    return preg_replace_callback('/\d/', function ($matches) use (&$pos) {
        $pos ++;
        if ($pos > 7) {
            return 'x';
        }
        return $matches[0];
    }, $iban, 17);
}

/**
 * Returns the value with a html link to the mail module
 *
 * @param string $value
 *            The value that should be formated
 * @param string $user_uuid
 *            The user uuid
 * @param string $usf_uuid
 *            The usf uuid
 * @return string The formated string
 */
function getEmailLink($value, $user_uuid, $usf_uuid)
{
    $htmlValue = '';

    if (StringUtils::strValidCharacters((string) $value, 'email')) {
        if (! $GLOBALS['gSettingsManager']->getInt('mail_module_enabled')) {
            $emailLink = 'mailto:' . $value;
        } else {
            $emailLink = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/message_write.php', array(
                'user_uuid' => $user_uuid,
                'usf_uuid' => $usf_uuid
            ));
        }
        if (strlen($value) > 30) {
            $htmlValue = '<a href="' . $emailLink . '" title="' . $value . '">' . substr($value, 0, 30) . '...</a>';
        } else {
            $htmlValue = '<a href="' . $emailLink . '" title="' . $value . '" style="overflow: visible; display: inline;">' . $value . '</a>';
        }
    }
    return $htmlValue;
}

/**
 *
 * @param string $group
 * @param string $id
 * @param string $title
 * @param string $icon
 * @param string $body
 * @return string
 */
function getMenuePanel($group, $id, $parentId, $title, $icon, $body)
{
    $html = '
        <div class="card" id="panel_' . $id . '">
            <div class="card-header">
                <a type="button" data-bs-toggle="collapse" data-bs-target="#collapse_' . $id . '">
                    <i class="' . $icon . ' bi-fw"></i>' . $title . '
                </a>
            </div>
            <div id="collapse_' . $id . '" class="collapse" aria-labelledby="headingOne" data-bs-parent="#' . $parentId . '">
                <div class="card-body">
                    ' . $body . '
                </div>
            </div>
        </div>
    ';
    return $html;
}

/**
 *
 * @param string $group
 * @param string $id
 * @param string $title
 * @param string $icon
 * @return string
 */
function getMenuePanelHeaderOnly($group, $id, $parentId, $title, $icon)
{
    $html = '
        <div class="card" id="panel_' . $id . '">
            <div class="card-header">
                <a type="button" data-bs-toggle="collapse" data-bs-target="#collapse_' . $id . '">
                    <i class="' . $icon . ' bi-fw"></i>' . $title . '
                </a>
            </div>
            <div id="collapse_' . $id . '" class="collapse" aria-labelledby="headingOne" data-bs-parent="#' . $parentId . '">
                <div class="card-body">
    ';
    return $html;
}

/**
 *
 * @param
 *            none
 * @return string
 */
function getMenuePanelFooterOnly()
{
    return '</div></div></div>';
}

/**
 *
 * @param string $group
 * @return string
 */
function openMenueTab($group, $parentId)
{
    $html = '
        <div class="tab-pane fade" id="tabs-' . $group . '" role="tabpanel">
            <div class="accordion" id="' . $parentId . '">
    ';
    return $html;
}

/**
 *
 * @param
 *            none
 * @return string
 */
function closeMenueTab()
{
    return '</div></div>';
}

/**
 * Add a new groupbox to the page.
 * This could be used to group some elements
 * together. There is also the option to set a headline to this group box.
 *
 * @param string $id
 *            Id the the groupbox.
 * @param string $headline
 *            (optional) A headline that will be shown to the user.
 * @param string $class
 *            (optional) An additional css classname for the row. The class **admFieldRow**
 *            is set as default and need not set with this parameter.
 */
function openGroupBox($id, $headline = null, $class = '')
{
    $html = '<div id="' . $id . '" class="card admidio-field-group ' . $class . '">';
    // add headline to groupbox
    if ($headline !== null) {
        $html .= '<div class="card-header">' . $headline . '</div>';
    }
    $html .= '<div class="card-body">';
    return $html;
}

/**
 * Close the groupbox that was created before.
 */
function closeGroupBox()
{
    return '</div></div>';
}

/**
 * Shows a test result and, depending an the size, a scroll bar
 *
 * @param array $testResult
 *            array with test result
 * @return string
 */
function showTestResultWithScrollbar($testResult)
{
    $size = sizeof($testResult);
    $html = '';

    if ($size > 8) {
        $html .= '<div style="width:100%; height:200px; overflow:auto; border:20px;">';
    }
    foreach ($testResult as $data) {
        $html .= $data . '<br />';
    }
    if ($size > 8) {
        $html .= '</div>';
    }
    return $html;
}

/**
 * Formatiert einen Betrag fuer die Anzeige, z.B. 1.234,50 EUR
 *
 * @param float|string|null $amount
 * @return string
 */
function mf_format_amount($amount): string
{
    global $gSettingsManager;

    if ($amount === null || $amount === '') {
        return '';
    }
    return number_format((float) $amount, 2, ',', '.') . ' ' . $gSettingsManager->getString('system_currency');
}

/**
 * Formatiert ein Datum (Y-m-d) im Datumsformat von Admidio
 *
 * @param string|null $date
 * @return string
 */
function mf_format_date(?string $date): string
{
    global $gSettingsManager;

    if ($date === null || $date === '') {
        return '';
    }
    $dateTime = \DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));
    return $dateTime ? $dateTime->format($gSettingsManager->getString('system_date')) : $date;
}

/**
 * Formatiert einen Zeitstempel (Y-m-d H:i:s) im Systemformat, z. B. 13.11.2026 20:15
 *
 * @param string|null $dateTime
 * @return string
 */
function mf_format_datetime(?string $dateTime): string
{
    global $gSettingsManager;

    if ($dateTime === null || $dateTime === '') {
        return '';
    }
    $value = \DateTime::createFromFormat('Y-m-d H:i:s', substr($dateTime, 0, 19));
    return $value ? $value->format($gSettingsManager->getString('system_date') . ' ' . $gSettingsManager->getString('system_time')) : $dateTime;
}

/**
 * Liefert einen Sprachtext als reinen Text (z. B. fuer E-Mails): Zeilenumbrueche statt <br />,
 * HTML-Entities aufgeloest
 *
 * @param string $textId
 * @param array<int, mixed> $params
 * @return string
 */
function mf_language_plain_text(string $textId, array $params = array()): string
{
    global $gL10n;

    $text = str_replace(array('<br />', '<br/>', '<br>'), "\n", $gL10n->get($textId, $params));
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Liefert eine gespeicherte Datei eines Einzugs (SEPA-Datei oder Kontrolldatei) als Download aus und beendet das Skript
 *
 * @param array<string, mixed> $file Zeile aus BookingStore::getRunFile() (Inhalt dekodiert)
 * @return void
 */
function mf_send_stored_file(array $file): void
{
    $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file['mff_file_name']);
    $content = (string) $file['mff_content'];
    switch (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
        case 'xlsx':
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            break;
        case 'csv':
            $mime = 'text/comma-separated-values';
            break;
        default:
            $mime = 'text/xml; charset=utf-8';
            break;
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    echo $content;
    exit();
}

/**
 * Prueft, ob fuer eine gespeicherte SEPA-Datei ein gueltiger geteilter Link besteht
 *
 * @param array<string, mixed> $fileMeta Zeile aus BookingStore::findRunFileMeta()
 * @return bool
 */
function mf_share_active(array $fileMeta): bool
{
    if (empty($fileMeta['mff_share_hash']) || empty($fileMeta['mff_share_expires'])) {
        return false;
    }
    $expires = strtotime((string) $fileMeta['mff_share_expires']);
    return $expires !== false && $expires >= time();
}

/**
 * Wandelt eine Datumseingabe (Systemformat oder Y-m-d) in Y-m-d um
 *
 * @param string $input
 * @return string leer bei ungueltiger Eingabe
 */
function mf_parse_date(string $input): string
{
    global $gSettingsManager;

    $input = trim($input);
    foreach (array('Y-m-d', $gSettingsManager->getString('system_date')) as $format) {
        $dateTime = \DateTime::createFromFormat('!' . $format, $input);
        if ($dateTime !== false && $dateTime->format($format) === $input) {
            return $dateTime->format('Y-m-d');
        }
    }
    return '';
}

/**
 * Wandelt eine Betragseingabe (Komma oder Punkt) in eine Zahl um
 *
 * @param string $input
 * @return float|null null bei ungueltiger Eingabe
 */
function mf_parse_amount(string $input): ?float
{
    $input = trim(str_replace(' ', '', $input));
    if ($input === '') {
        return null;
    }
    if (strpos($input, ',') !== false) {
        $input = str_replace(array('.', ','), array('', '.'), $input);
    }
    return is_numeric($input) ? round((float) $input, 2) : null;
}

/**
 * Uebersetzt einen Status oder eine Quelle der Buchungshistorie
 *
 * @param string $value
 * @return string
 */
function mf_booking_label(string $value): string
{
    global $gL10n;

    $map = array(
        'offen' => 'PLG_MEMBERSHIPFEE_BOOKING_STATUS_OPEN',
        'exportiert' => 'PLG_MEMBERSHIPFEE_BOOKING_STATUS_EXPORTED',
        'eingezogen' => 'PLG_MEMBERSHIPFEE_BOOKING_STATUS_COLLECTED',
        'storniert' => 'PLG_MEMBERSHIPFEE_BOOKING_STATUS_CANCELLED',
        'erstellt' => 'PLG_MEMBERSHIPFEE_RUN_STATUS_CREATED',
        'verworfen' => 'PLG_MEMBERSHIPFEE_RUN_STATUS_DISCARDED'
    );
    return isset($map[$value]) ? $gL10n->get($map[$value]) : $value;
}

/**
 * Bankfeiertage, an denen keine Lastschrift faellig sein soll: bundesweite Feiertage, die
 * Feiertage in Baden-Wuerttemberg (Hl. Drei Koenige, Fronleichnam, Allerheiligen) sowie
 * Heiligabend und Silvester (keine Bankarbeitstage).
 *
 * @param int $year
 * @return array<string, string> Datum (Y-m-d) => Name
 */
function mf_bank_holidays(int $year): array
{
    // Ostersonntag nach der Gaussschen Osterformel (ohne calendar-Erweiterung)
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    $easter = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    $relative = static function (int $days) use ($easter): string {
        return $easter->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    };

    $holidays = array(
        sprintf('%04d-01-01', $year) => 'Neujahr',
        sprintf('%04d-01-06', $year) => 'Heilige Drei Könige',
        $relative(-2) => 'Karfreitag',
        $relative(1) => 'Ostermontag',
        sprintf('%04d-05-01', $year) => 'Tag der Arbeit',
        $relative(39) => 'Christi Himmelfahrt',
        $relative(50) => 'Pfingstmontag',
        $relative(60) => 'Fronleichnam',
        sprintf('%04d-10-03', $year) => 'Tag der Deutschen Einheit',
        sprintf('%04d-11-01', $year) => 'Allerheiligen',
        sprintf('%04d-12-24', $year) => 'Heiligabend',
        sprintf('%04d-12-25', $year) => '1. Weihnachtstag',
        sprintf('%04d-12-26', $year) => '2. Weihnachtstag',
        sprintf('%04d-12-31', $year) => 'Silvester'
    );
    ksort($holidays);
    return $holidays;
}

/**
 * Prueft, ob ein Datum als Faelligkeit einer Lastschrift taugt (kein Samstag, Sonntag oder Bankfeiertag).
 *
 * @param string $date Datum im Format Y-m-d
 * @return string|null Grund, warum das Datum nicht moeglich ist, sonst null
 */
function mf_duedate_problem(string $date): ?string
{
    $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($value === false) {
        return 'ungültiges Datum';
    }
    $weekday = (int) $value->format('N');
    if ($weekday === 6) {
        return 'Samstag';
    }
    if ($weekday === 7) {
        return 'Sonntag';
    }
    $holidays = mf_bank_holidays((int) $value->format('Y'));
    return $holidays[$date] ?? null;
}

/**
 * Naechster moeglicher Faelligkeitstag ab dem angegebenen Datum (einschliesslich).
 *
 * @param string $date Datum im Format Y-m-d
 * @return string Y-m-d
 */
function mf_next_valid_duedate(string $date): string
{
    $value = new \DateTimeImmutable($date);
    for ($i = 0; $i < 30 && mf_duedate_problem($value->format('Y-m-d')) !== null; $i++) {
        $value = $value->modify('+1 day');
    }
    return $value->format('Y-m-d');
}

/**
 * Ermittelt den Sequenztyp fuer die automatische Auswahl: RCUR, wenn fuer das Mitglied schon einmal
 * per SEPA eingezogen wurde (mit dem aktuellen Mandat oder ohne gespeicherte Mandatsreferenz,
 * z. B. bei aus Winner uebernommenen Buchungen), sonst FRST.
 *
 * @param array<int, int> $userIds
 * @return array<int, string> usr_id => 'FRST' oder 'RCUR'
 */
function mf_auto_sequence_types(array $userIds): array
{
    global $gDb, $gProfileFields, $gCurrentOrgId;

    $result = array();
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    foreach ($userIds as $userId) {
        $result[$userId] = 'FRST';
    }
    if (count($userIds) === 0) {
        return $result;
    }

    $usfMandate = (int) $gProfileFields->getProperty('MANDATEID' . $gCurrentOrgId, 'usf_id');
    $bookingsTable = TABLE_PREFIX . '_plugin_membershipfee_bookings';
    foreach (array_chunk($userIds, 500) as $chunk) {
        $sql = 'SELECT DISTINCT b.mfb_usr_id
                  FROM ' . $bookingsTable . ' b
             LEFT JOIN ' . TBL_USER_DATA . ' mandate
                    ON mandate.usd_usr_id = b.mfb_usr_id
                   AND mandate.usd_usf_id = ?
                 WHERE b.mfb_org_id = ?
                   AND b.mfb_status = ?
                   AND b.mfb_method = ?
                   AND (b.mfb_mandate_id IS NULL OR b.mfb_mandate_id = \'\' OR b.mfb_mandate_id = mandate.usd_value)
                   AND b.mfb_usr_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
        $statement = $gDb->queryPrepared($sql, array_merge(array($usfMandate, (int) $gCurrentOrgId, 'eingezogen', 'SEPA'), $chunk));
        while ($row = $statement->fetch()) {
            $result[(int) $row['mfb_usr_id']] = 'RCUR';
        }
    }
    return $result;
}
