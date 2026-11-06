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
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;
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
/**
 * Buchungskreis der aktuellen Seite: "ad" = Arbeitsdienst, sonst (Standard) Mitgliedsbeitrag.
 *
 * Der Parameter "collection" (GET oder POST) legt ihn fest und wird in der Sitzung gemerkt, damit die
 * Folgeaufrufe einer Seite (AJAX, Filter, Export) ohne den Parameter im selben Buchungskreis bleiben.
 * membership_fee.php setzt die Sitzung auf den Mitgliedsbeitrag zurueck; die Schritte der Registerkarte
 * "Arbeitsdienst" uebergeben collection=ad. (Der Parameter "scope" ist in booking_edit.php schon belegt.)
 */
function mf_collection_scope(): CollectionScope
{
    global $gCurrentOrgId;

    $key = $_GET['collection'] ?? $_POST['collection'] ?? null;
    if (is_string($key) && in_array($key, CollectionScope::keys(), true)) {
        $_SESSION['pMembershipFee']['collection'] = $key;
    } else {
        $key = (string) ($_SESSION['pMembershipFee']['collection'] ?? CollectionScope::MEMBERSHIP);
    }
    return CollectionScope::fromKey($key, (int) $gCurrentOrgId);
}

/**
 * Offene Lastschriften je Faelligkeitsdatum und Sequenztyp in einem Buchungskreis
 * (Mitglieder mit Faelligkeit, Betrag, ohne Bezahlt-Datum und mit IBAN; wie in sepa_create.php).
 * @return array<string, array<string, int>> Y-m-d => (FRST|RCUR|FNAL|OOFF => Anzahl)
 */
function mf_sepa_duedate_overview(CollectionScope $scope): array
{
    $members = list_members(array(
        $scope->field(CollectionScope::FIELD_DUEDATE),
        $scope->field(CollectionScope::FIELD_SEQUENCETYPE),
        $scope->field(CollectionScope::FIELD_FEE),
        $scope->field(CollectionScope::FIELD_PAID),
        'IBAN'
    ), 0);
    $duedates = array();
    foreach ($members as $memberdata) {
        $dueDate = (string) ($memberdata[$scope->field(CollectionScope::FIELD_DUEDATE)] ?? '');
        if ($dueDate === '' || empty($memberdata[$scope->field(CollectionScope::FIELD_FEE)])
            || !empty($memberdata[$scope->field(CollectionScope::FIELD_PAID)]) || empty($memberdata['IBAN'])) {
            continue;
        }
        $sequenceType = (string) ($memberdata[$scope->field(CollectionScope::FIELD_SEQUENCETYPE)] ?? '');
        $sequenceType = in_array($sequenceType, array('RCUR', 'FNAL', 'OOFF'), true) ? $sequenceType : 'FRST';
        if (!isset($duedates[$dueDate])) {
            $duedates[$dueDate] = array('FRST' => 0, 'RCUR' => 0, 'FNAL' => 0, 'OOFF' => 0);
        }
        $duedates[$dueDate][$sequenceType]++;
    }
    ksort($duedates);
    return $duedates;
}

/**
 * Jahr, auf das sich ein Buchungskreis gerade bezieht (Anzeige in der Umschaltung Mitgliedsbeitrag/Arbeitsdienst).
 * Mitgliedsbeitrag: Beitragsjahr aus den Einstellungen. Arbeitsdienst: Leistungsjahr(e) der offenen
 * AD-Positionen, sonst das letzte Leistungsjahr mit AD-Positionen, sonst das Jahr, das "Arbeitsdienst
 * abrechnen" vorschlaegt (bis Juni das Vorjahr).
 */
function mf_collection_year_label(CollectionScope $scope): string
{
    global $gDb, $gCurrentOrgId;

    if ($scope->isMembership()) {
        return (string) getContributionYear();
    }
    try {
        $years = array();
        $statement = $gDb->queryPrepared('SELECT DISTINCT mfb_year FROM ' . BookingStore::bookingsTable()
            . ' WHERE mfb_org_id = ? AND mfb_status = ? AND ' . $scope->sourceSqlCondition() . ' ORDER BY mfb_year',
            array((int) $gCurrentOrgId, BookingStore::STATUS_OPEN));
        while ($row = $statement->fetch()) {
            $years[] = (int) $row['mfb_year'];
        }
        if (count($years) === 0) {
            $statement = $gDb->queryPrepared('SELECT MAX(mfb_year) FROM ' . BookingStore::bookingsTable()
                . ' WHERE mfb_org_id = ? AND ' . $scope->sourceSqlCondition(), array((int) $gCurrentOrgId));
            $last = (int) $statement->fetchColumn();
            if ($last > 0) {
                $years[] = $last;
            }
        }
    } catch (\Throwable $e) {
        $years = array();
    }
    if (count($years) === 0) {
        $years[] = ((int) date('n') <= 6) ? (int) date('Y') - 1 : (int) date('Y');
    }
    return implode('/', $years);
}

/** URL des Menuepunkts "Arbeitsdienst erfassen" (eigenes Recht ueber "Sichtbar für") */
function mf_work_menu_url(): string
{
    return FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/work_input.php';
}

/**
 * men_id des Menuepunkts "Arbeitsdienst erfassen" oder 0, wenn es ihn nicht gibt.
 */
function mf_work_menu_id(): int
{
    global $gDb;

    $row = $gDb->queryPrepared('SELECT men_id FROM ' . TBL_MENU . ' WHERE men_url = ?', array(mf_work_menu_url()))->fetch();
    return $row ? (int) $row['men_id'] : 0;
}

/**
 * Legt den Menuepunkt "Arbeitsdienst erfassen" unter "Erweiterungen" an (falls noch nicht vorhanden).
 * Sichtbar zunaechst nur fuer die Zugriffsrolle des Plugins; weitere Rollen (z. B. Arbeitsdienstleiter)
 * werden in der Admidio-Menueverwaltung unter "Sichtbar für" eingetragen.
 *
 * @return int men_id
 */
function mf_work_menu_create(int $accessRoleId): int
{
    global $gDb, $gCurrentSession;

    $menId = mf_work_menu_id();
    if ($menId > 0) {
        return $menId;
    }
    $menuParent = new \Admidio\Menu\Entity\MenuEntry($gDb);
    $menuParent->readDataByColumns(array('men_name_intern' => 'extensions'));

    $menu = new \Admidio\Menu\Entity\MenuEntry($gDb);
    $menu->setValue('men_men_id_parent', $menuParent->getValue('men_id'));
    $menu->setValue('men_url', mf_work_menu_url());
    $menu->setValue('men_icon', 'tools');
    $menu->setValue('men_name', 'Arbeitsdienst erfassen');
    $menu->setValue('men_description', 'Geleistete Arbeitsdienst-Stunden erfassen');
    $menu->save();
    $menId = (int) $menu->getValue('men_id');

    if ($accessRoleId > 0) {
        $rightMenuView = new RolesRights($gDb, 'menu_view', $menId);
        $rightMenuView->addRoles(array($accessRoleId));
    }
    $gCurrentSession->reloadAllSessions();
    return $menId;
}

/**
 * Darf der angemeldete Benutzer Arbeitsdienst-Stunden erfassen?
 * Ja fuer die Zugriffsrolle des Plugins und fuer alle, die den Menuepunkt "Arbeitsdienst erfassen"
 * (system/work_input.php) sehen duerfen.
 */
function mf_is_work_input_authorized(): bool
{
    return isUserAuthorized() || isUserAuthorized('system/work_input.php');
}

/**
 * Ist das Leistungsjahr im Arbeitsdienst schon abgerechnet (exportierte oder eingezogene Positionen)?
 * Dann duerfen nur noch Mitglieder der Zugriffsrolle Stunden aendern.
 */
function mf_work_year_locked(int $year): bool
{
    global $gDb, $gCurrentOrgId;

    \Plugins\MembershipFee\classes\Bookings\BookingStore::ensureTables();
    $scope = CollectionScope::work((int) $gCurrentOrgId);
    $sql = 'SELECT COUNT(*) FROM ' . \Plugins\MembershipFee\classes\Bookings\BookingStore::bookingsTable() . '
             WHERE mfb_org_id = ? AND mfb_year = ? AND ' . $scope->sourceSqlCondition() . '
               AND mfb_status IN (?, ?)';
    return (int) $gDb->queryPrepared($sql, array((int) $gCurrentOrgId, $year, 'exportiert', 'eingezogen'))->fetchColumn() > 0;
}

/**
 * Mitglieder der Organisation, die im Jahr in mindestens einer Rolle waren (Auswahl bei der Erfassung).
 * @return array<int, array{name:string, birthday:string}> usr_id => Daten, nach Namen sortiert
 */
function mf_work_members_of_year(int $year): array
{
    global $gDb, $gProfileFields, $gCurrentOrgId;

    $sql = 'SELECT DISTINCT mem.mem_usr_id, ln.usd_value AS last_name, fn.usd_value AS first_name, bd.usd_value AS birthday
              FROM ' . TBL_MEMBERS . ' AS mem
        INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = mem.mem_rol_id AND rol.rol_valid = true
        INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id AND (cat.cat_org_id = ? OR cat.cat_org_id IS NULL)
        INNER JOIN ' . TBL_USERS . ' AS usr ON usr.usr_id = mem.mem_usr_id AND usr.usr_valid = true
         LEFT JOIN ' . TBL_USER_DATA . ' AS ln ON ln.usd_usr_id = mem.mem_usr_id AND ln.usd_usf_id = ?
         LEFT JOIN ' . TBL_USER_DATA . ' AS fn ON fn.usd_usr_id = mem.mem_usr_id AND fn.usd_usf_id = ?
         LEFT JOIN ' . TBL_USER_DATA . ' AS bd ON bd.usd_usr_id = mem.mem_usr_id AND bd.usd_usf_id = ?
             WHERE mem.mem_begin <= ? AND mem.mem_end >= ?
               AND cat.cat_type = \'ROL\'';
    $statement = $gDb->queryPrepared($sql, array(
        (int) $gCurrentOrgId,
        (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        (int) $gProfileFields->getProperty('BIRTHDAY', 'usf_id'),
        sprintf('%04d-12-31', $year),
        sprintf('%04d-01-01', $year)
    ));
    $members = array();
    while ($row = $statement->fetch()) {
        $members[(int) $row['mem_usr_id']] = array(
            'name' => trim((string) $row['last_name'] . ', ' . (string) $row['first_name'], ', '),
            'birthday' => (string) $row['birthday']
        );
    }
    uasort($members, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return $members;
}

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
function analyse_mem(?CollectionScope $scope = null)
{
    // Buchungskreis: Mitgliedsbeitrag (FEE/PAID) oder Arbeitsdienst (ADFEE/ADPAID)
    $scope ??= CollectionScope::membership((int) $GLOBALS['gCurrentOrgId']);
    $feeField = $scope->field(CollectionScope::FIELD_FEE);
    $paidField = $scope->field(CollectionScope::FIELD_PAID);

    $members = list_members(array(
        $feeField,
        $scope->field(CollectionScope::FIELD_TEXT),
        $paidField,
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
        if (empty($memberdata[$feeField])) {
            unset($members[$member]);
        }
    }

    // jetzt wird gezaehlt
    foreach ($members as $member => $memberdata) {
        if (! empty($memberdata['IBAN'])) {
            $ret['BEITRAG_kto'] += $memberdata[$feeField];
            $ret['BEITRAG_kto_anzahl'] += 1;
        }
        if ((! empty($memberdata['IBAN'])) && ! empty($memberdata[$paidField])) {
            $ret['BEZAHLT_kto'] += $memberdata[$feeField];
            $ret['BEZAHLT_kto_anzahl'] += 1;
        }
        if (empty($memberdata['IBAN'])) {
            $ret['BEITRAG_rech'] += $memberdata[$feeField];
            $ret['BEITRAG_rech_anzahl'] += 1;
        }
        if (empty($memberdata['IBAN']) && ! empty($memberdata[$paidField])) {
            $ret['BEZAHLT_rech'] += $memberdata[$feeField];
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

    // Beitrag, Faelligkeit und Text aus den Arbeitsfeldern des aktuellen Buchungskreises
    $scope = mf_collection_scope();

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
    $text = preg_replace('/#fee#/', $user->getValue($scope->field(CollectionScope::FIELD_FEE)), $text);
    $text = preg_replace('/#due_day#/', $user->getValue($scope->field(CollectionScope::FIELD_DUEDATE)), $text);
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
    $text = preg_replace('/#membership_fee_text#/', $user->getValue($scope->field(CollectionScope::FIELD_TEXT)), $text);
    $text = preg_replace('/#iban_obfuscated#/', obfuscate_iban($user->getValue('IBAN')), $text);
    $text = preg_replace('/#membernumber#/', $user->getValue('MEMBERNUMBER' . $gCurrentOrgId), $text);

    // Rechnung (zuletzt erstellte offene Rechnung des Buchungskreises, siehe mf_current_invoice())
    if (strpos($text, '#invoice_') !== false || strpos($text, '#dunning_') !== false) {
        $invoice = mf_current_invoice((int) $user->getValue('usr_id'), $scope);
        $link = '';
        if ($invoice !== null && strpos($text, '#invoice_link#') !== false) {
            $link = \Plugins\MembershipFee\classes\Invoices\PdfBridge::signedUrl((string) $invoice['mfi_invoice_no'], (string) $invoice['mfi_kind'],
                \Plugins\MembershipFee\classes\Invoices\PdfBridge::TYPE_INVOICE, '+1 year');
        }
        $values = array(
            '#invoice_no#' => $invoice['mfi_invoice_no'] ?? '',
            '#invoice_date#' => $invoice !== null ? mf_format_date($invoice['mfi_invoice_date']) : '',
            '#invoice_due#' => $invoice !== null ? mf_format_date($invoice['mfi_due_date'] ?? '') : '',
            '#invoice_amount#' => $invoice !== null ? mf_format_amount($invoice['mfi_amount']) : '',
            '#invoice_link#' => $link,
            '#dunning_date#' => $invoice !== null ? mf_format_date($invoice['mfi_dunning_date'] ?? '') : '',
            '#dunning_due#' => $invoice !== null ? mf_format_date($invoice['mfi_dunning_due'] ?? '') : ''
        );
        $text = str_replace(array_keys($values), array_values($values), $text);
    }

    return $text;
}

/**
 * Zuletzt erstellte Rechnung eines Mitglieds im Buchungskreis, die noch offen oder im Einzug ist
 * (fuer Platzhalter und PDF-Anhang in E-Mails).
 *
 * @return array<string, mixed>|null
 */
function mf_current_invoice(int $usrId, CollectionScope $scope): ?array
{
    static $cache = array();

    // beim ersten Aufruf je Buchungskreis alle Rechnungen mit einer Abfrage laden (Listen mit vielen Mitgliedern)
    $kind = $scope->key();
    if (!isset($cache[$kind])) {
        $cache[$kind] = array();
        foreach (\Plugins\MembershipFee\classes\Invoices\InvoiceStore::search(array('kind' => $kind)) as $invoice) {
            if (!in_array($invoice['status'], array(\Plugins\MembershipFee\classes\Invoices\InvoiceStatus::OPEN, \Plugins\MembershipFee\classes\Invoices\InvoiceStatus::IN_COLLECTION), true)) {
                continue;
            }
            $member = (int) $invoice['mfi_usr_id'];
            if (!isset($cache[$kind][$member]) || $invoice['mfi_id'] > $cache[$kind][$member]['mfi_id']) {
                $cache[$kind][$member] = $invoice;
            }
        }
    }
    return $cache[$kind][$usrId] ?? null;
}

/**
 * Testempfaenger (Einstellungen -> E-Mail-Benachrichtigungen): Ist eine Adresse eingetragen, gehen alle E-Mails
 * des Plugins an diese Adresse statt an die Mitglieder.
 */
function mf_test_email(): string
{
    global $pPreferences;

    $config = ($pPreferences instanceof ConfigTable) ? $pPreferences->config : null;
    if ($config === null) {
        $preferences = new ConfigTable();
        $preferences->read();
        $config = $preferences->config;
    }
    $email = trim((string) ($config['Testbetrieb']['test_email'] ?? ''));
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
}

/**
 * Tatsaechlicher Empfaenger einer E-Mail: im Testbetrieb der Testempfaenger, der Betreff nennt dann den
 * eigentlichen Empfaenger.
 *
 * @return array{email:string, name:string, subject_prefix:string, test:bool}
 */
function mf_mail_target(string $email, string $name): array
{
    $test = mf_test_email();
    if ($test === '') {
        return array('email' => $email, 'name' => $name, 'subject_prefix' => '', 'test' => false);
    }
    return array(
        'email' => $test,
        'name' => 'Test (' . $name . ')',
        'subject_prefix' => '[TEST an ' . trim($name . ' <' . $email . '>') . '] ',
        'test' => true
    );
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
    // Inhalt fuer die Anzeige rechts neben dem Menue merken (Schluessel wie in mf_menu_groups())
    $GLOBALS['mfPanels'][$group . '_' . $id] = array('title' => '<i class="' . $icon . ' bi-fw"></i>' . $title, 'body' => $body);
    return '';
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
    return '';   // keine Registerkarten mehr, siehe getMenuePanel()
}

/**
 *
 * @param
 *            none
 * @return string
 */
function closeMenueTab()
{
    return '';
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
/**
 * Daten fuer die Pruefung "Einmalige Beitragsrollen" lesen und auswerten (siehe classes/Checks/OneTimeRoleCheck.php).
 *
 * @return array<int, array<string, mixed>> Funde
 */
function mf_onetime_role_findings(): array
{
    global $gDb, $gProfileFields, $gCurrentOrgId;

    // einmalige Beitragsrollen der Organisation
    $roleNames = array();
    $statement = $gDb->queryPrepared('SELECT rol_id, rol_name
          FROM ' . TBL_ROLES . '
    INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
         WHERE rol_valid = true
           AND rol_cost IS NOT NULL
           AND rol_cost_period = -1
           AND cat_name_intern <> \'EVENTS\'
           AND (cat_org_id = ? OR cat_org_id IS NULL)', array((int) $gCurrentOrgId));
    while ($row = $statement->fetch()) {
        $roleNames[(int) $row['rol_id']] = (string) $row['rol_name'];
    }
    if (count($roleNames) === 0) {
        return array();
    }
    $roleIds = array_keys($roleNames);
    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));

    // Namen aller gueltigen Benutzer mit Mitgliedschaft oder Position in diesen Rollen
    $lastId = (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id');
    $firstId = (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id');
    $names = array();
    $nameOf = static function (int $usrId) use (&$names, $gDb, $lastId, $firstId): string {
        if (!isset($names[$usrId])) {
            $statement = $gDb->queryPrepared('SELECT usd_usf_id, usd_value FROM ' . TBL_USER_DATA . ' WHERE usd_usr_id = ? AND usd_usf_id IN (?, ?)',
                array($usrId, $lastId, $firstId));
            $parts = array('last' => '', 'first' => '');
            while ($row = $statement->fetch()) {
                $parts[(int) $row['usd_usf_id'] === $lastId ? 'last' : 'first'] = (string) $row['usd_value'];
            }
            $names[$usrId] = trim($parts['last'] . ', ' . $parts['first'], ', ');
        }
        return $names[$usrId];
    };

    $memberships = array();
    $statement = $gDb->queryPrepared('SELECT mem_id, mem_usr_id, mem_rol_id, mem_begin, mem_end
          FROM ' . TBL_MEMBERS . '
    INNER JOIN ' . TBL_USERS . ' ON usr_id = mem_usr_id AND usr_valid = true
         WHERE mem_rol_id IN (' . $placeholders . ')', $roleIds);
    while ($row = $statement->fetch()) {
        $memberships[] = array(
            'mem_id' => (int) $row['mem_id'],
            'usr_id' => (int) $row['mem_usr_id'],
            'name' => $nameOf((int) $row['mem_usr_id']),
            'rol_id' => (int) $row['mem_rol_id'],
            'rol_name' => $roleNames[(int) $row['mem_rol_id']],
            'begin' => (string) $row['mem_begin'],
            'end' => (string) $row['mem_end']
        );
    }

    // nicht stornierte Positionen je Mitglied, Rolle und Jahr (staerkster Status: eingezogen > exportiert > offen)
    $rank = array(\Plugins\MembershipFee\classes\Bookings\BookingStore::STATUS_OPEN => 1,
        \Plugins\MembershipFee\classes\Bookings\BookingStore::STATUS_EXPORTED => 2,
        \Plugins\MembershipFee\classes\Bookings\BookingStore::STATUS_COLLECTED => 3);
    $positionYears = array();
    \Plugins\MembershipFee\classes\Bookings\BookingStore::ensureTables();
    $statement = $gDb->queryPrepared('SELECT mfb_usr_id, mfb_rol_id, mfb_year, mfb_status
          FROM ' . \Plugins\MembershipFee\classes\Bookings\BookingStore::bookingsTable() . '
         WHERE mfb_org_id = ?
           AND mfb_status <> ?
           AND mfb_rol_id IN (' . $placeholders . ')',
        array_merge(array((int) $gCurrentOrgId, \Plugins\MembershipFee\classes\Bookings\BookingStore::STATUS_CANCELLED), $roleIds));
    while ($row = $statement->fetch()) {
        $usrId = (int) $row['mfb_usr_id'];
        $rolId = (int) $row['mfb_rol_id'];
        $year = (int) $row['mfb_year'];
        $status = (string) $row['mfb_status'];
        $current = $positionYears[$usrId][$rolId][$year] ?? null;
        if ($current === null || ($rank[$status] ?? 0) > ($rank[$current] ?? 0)) {
            $positionYears[$usrId][$rolId][$year] = $status;
        }
        $nameOf($usrId);
    }

    return \Plugins\MembershipFee\classes\Checks\OneTimeRoleCheck::evaluate($memberships, $positionYears, $names, $roleNames);
}

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

/**
 * Tabelle fuer zwischengespeicherte OpenIBAN-Ergebnisse (je IBAN nur der SHA-256-Hash, keine Kontonummer).
 */
function mf_openiban_cache_table(): string
{
    static $ensured = false;
    $table = TABLE_PREFIX . '_plugin_membershipfee_ibancache';
    if (!$ensured) {
        $GLOBALS['gDb']->queryPrepared('CREATE TABLE IF NOT EXISTS ' . $table . ' (
            mfi_hash     char(64)      NOT NULL,
            mfi_valid    tinyint(1)    NOT NULL DEFAULT 0,
            mfi_bic      varchar(11)   NOT NULL DEFAULT \'\',
            mfi_bank     varchar(255)  NOT NULL DEFAULT \'\',
            mfi_checked  datetime      NOT NULL,
            PRIMARY KEY (mfi_hash)
        ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4');
        $ensured = true;
    }
    return $table;
}

/**
 * Fragt IBANs bei openiban.com ab (Gueltigkeit, Bankname, BIC). Ergebnisse werden 30 Tage
 * zwischengespeichert; gleichzeitig laufen bis zu 8 Anfragen. Fehlgeschlagene Anfragen werden
 * nicht gespeichert und beim naechsten Aufruf wiederholt.
 *
 * @param array<int, string> $ibans normalisierte IBANs (ohne Leerzeichen, Grossbuchstaben)
 * @return array<string, array{valid:bool, bic:string, bank:string, error:string}> IBAN => Ergebnis
 */
function mf_openiban_lookup(array $ibans): array
{
    global $gDb;
    $table = mf_openiban_cache_table();
    $result = array();
    $hashes = array();
    foreach (array_unique($ibans) as $iban) {
        $hashes[hash('sha256', $iban)] = $iban;
    }
    if (count($hashes) === 0) {
        return $result;
    }

    // Cache lesen
    foreach (array_chunk(array_keys($hashes), 500) as $chunk) {
        $statement = $gDb->queryPrepared('SELECT * FROM ' . $table . ' WHERE mfi_checked >= ? AND mfi_hash IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
            array_merge(array(date('Y-m-d H:i:s', strtotime('-30 days'))), $chunk));
        while ($row = $statement->fetch()) {
            $result[$hashes[$row['mfi_hash']]] = array('valid' => (bool) $row['mfi_valid'], 'bic' => (string) $row['mfi_bic'], 'bank' => (string) $row['mfi_bank'], 'error' => '');
        }
    }

    $open = array();
    foreach ($hashes as $hash => $iban) {
        if (!isset($result[$iban])) {
            $open[$hash] = $iban;
        }
    }
    if (count($open) === 0) {
        return $result;
    }
    if (!function_exists('curl_multi_init')) {
        foreach ($open as $iban) {
            $result[$iban] = array('valid' => false, 'bic' => '', 'bank' => '', 'error' => 'cURL ist auf dem Server nicht verfügbar');
        }
        return $result;
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }

    foreach (array_chunk($open, 8, true) as $batch) {
        $multi = curl_multi_init();
        $handles = array();
        foreach ($batch as $hash => $iban) {
            $handle = curl_init('https://openiban.com/validate/' . rawurlencode($iban) . '?getBIC=true&validateBankCode=true');
            curl_setopt_array($handle, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => array('Accept: application/json'),
                CURLOPT_USERAGENT => 'Admidio-Mitgliedsbeitrag'
            ));
            curl_multi_add_handle($multi, $handle);
            $handles[$hash] = $handle;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $hash => $handle) {
            $iban = $batch[$hash];
            $body = curl_multi_getcontent($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $curlError = curl_error($handle);
            curl_multi_remove_handle($multi, $handle);
            // kein curl_close(): seit PHP 8.0 ohne Wirkung, ab PHP 8.5 als veraltet gemeldet

            $data = is_string($body) ? json_decode($body, true) : null;
            if ($httpCode !== 200 || !is_array($data)) {
                $result[$iban] = array('valid' => false, 'bic' => '', 'bank' => '', 'error' => 'Abfrage fehlgeschlagen' . ($curlError !== '' ? ': ' . $curlError : ' (HTTP ' . $httpCode . ')'));
                continue;
            }
            $entry = array(
                'valid' => (bool) ($data['valid'] ?? false),
                'bic' => strtoupper(trim((string) ($data['bankData']['bic'] ?? ''))),
                'bank' => trim((string) ($data['bankData']['name'] ?? '')),
                'error' => ''
            );
            $result[$iban] = $entry;
            $gDb->queryPrepared('REPLACE INTO ' . $table . ' (mfi_hash, mfi_valid, mfi_bic, mfi_bank, mfi_checked) VALUES (?, ?, ?, ?, ?)',
                array($hash, $entry['valid'] ? 1 : 0, mb_substr($entry['bic'], 0, 11), mb_substr($entry['bank'], 0, 255), DATETIME_NOW));
        }
        curl_multi_close($multi);
    }
    return $result;
}

/**
 * Gleicht die IBANs aller Mitglieder mit OpenIBAN ab und liefert die Auffaelligkeiten:
 * IBAN laut OpenIBAN ungueltig, BIC fehlt/weicht ab, Bankname fehlt/weicht ab, Abfrage fehlgeschlagen.
 * IBANs, die schon die lokale Pruefziffern-Pruefung nicht bestehen, meldet check_iban().
 *
 * @return array{rows: array<int, array<string, mixed>>, checked: int}
 */
function mf_openiban_check(): array
{
    $members = list_members(array('FIRST_NAME', 'LAST_NAME', 'IBAN', 'BIC', 'BANK'), 0);
    $ibanByUser = array();
    foreach ($members as $usrId => $memberdata) {
        $iban = strtoupper(str_replace(' ', '', (string) $memberdata['IBAN']));
        if (strlen($iban) > 1 && test_iban($iban)) {
            $ibanByUser[$usrId] = $iban;
        }
    }
    $lookup = mf_openiban_lookup(array_values($ibanByUser));

    $normalizeBic = static function (string $bic): string {
        $bic = strtoupper(str_replace(' ', '', $bic));
        return (strlen($bic) === 11 && substr($bic, 8) === 'XXX') ? substr($bic, 0, 8) : $bic;
    };
    $rows = array();
    foreach ($ibanByUser as $usrId => $iban) {
        $info = $lookup[$iban] ?? null;
        if ($info === null) {
            continue;
        }
        $storedBic = trim((string) $members[$usrId]['BIC']);
        $storedBank = trim((string) $members[$usrId]['BANK']);
        $problems = array();
        if ($info['error'] !== '') {
            $problems[] = $info['error'];
        } elseif (!$info['valid']) {
            $problems[] = 'IBAN laut OpenIBAN ungültig (z. B. unbekannte Bankleitzahl)';
        } else {
            if ($info['bic'] !== '' && $normalizeBic($storedBic) !== $normalizeBic($info['bic'])) {
                $problems[] = $storedBic === '' ? 'BIC fehlt' : 'BIC weicht ab';
            }
            if ($info['bank'] !== '' && mb_strtolower($storedBank) !== mb_strtolower($info['bank'])) {
                $problems[] = $storedBank === '' ? 'Bankname fehlt' : 'Bankname weicht ab';
            }
        }
        if (count($problems) === 0) {
            continue;
        }
        $rows[] = array(
            'usr_id' => (int) $usrId,
            'name' => trim($members[$usrId]['LAST_NAME'] . ', ' . $members[$usrId]['FIRST_NAME'], ', '),
            'iban' => substr($iban, 0, 4) . ' … ' . substr($iban, -4),
            'stored_bic' => $storedBic,
            'stored_bank' => $storedBank,
            'bic' => $info['bic'],
            'bank' => $info['bank'],
            'problems' => $problems,
            'fixable' => $info['error'] === '' && $info['valid'] && ($info['bic'] !== '' || $info['bank'] !== '')
        );
    }
    usort($rows, static function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    return array('rows' => $rows, 'checked' => count($ibanByUser));
}

/**
 * Beitragsrollen als Eintraege fuer eine Auswahlliste (nach Rollentyp gruppiert).
 * @return array<int, array{0:int, 1:string, 2:string}>
 */
function mf_contribution_role_select_entries(): array
{
    $entries = array();
    $sortArray = array();
    foreach (beitragsrollen_einlesen() as $key => $data) {
        $entries[$key] = array($key, $data['rolle'], expand_rollentyp($data['rollentyp']));
        $sortArray[$key] = expand_rollentyp($data['rollentyp']);
    }
    array_multisort($sortArray, SORT_ASC, $entries);
    return $entries;
}

/**
 * Rollenfilter einer Seite (Neuberechnung, Faelligkeitsdatum, Beitragszahlungen).
 *
 * Das Auswahlfeld im Filter der Seite (mf_role_filter_add()) sendet rolefilter=<key> und die gewaehlten Rollen;
 * die Auswahl wird in der Sitzung gespeichert (leer = alle Beitragsrollen) und die Seite per GET neu geladen,
 * damit "Zurueck" und "Neu laden" das Formular nicht erneut senden. Ohne Formular gilt die gespeicherte Auswahl.
 *
 * @param string $key recalculation | duedates | payments
 * @return array<int, int>|null gewaehlte rol_id oder null = alle Beitragsrollen
 */
function mf_role_filter_selection(string $key): ?array
{
    $sessionKey = $key . '_rol_sel';
    if (($_POST['rolefilter'] ?? '') === $key) {
        $selected = array();
        if (isset($_POST[$key . '_roleselection']) && is_array($_POST[$key . '_roleselection'])) {
            $selected = array_values(array_filter(array_map('intval', $_POST[$key . '_roleselection'])));
        }
        if (count($selected) > 0) {
            $_SESSION['pMembershipFee'][$sessionKey] = $selected;
        } else {
            unset($_SESSION['pMembershipFee'][$sessionKey]);
        }
        admRedirect(CURRENT_URL);
        exit();
    }

    $selected = $_SESSION['pMembershipFee'][$sessionKey] ?? null;
    return (is_array($selected) && count($selected) > 0) ? array_map('intval', $selected) : null;
}

/**
 * Auswahlfeld "Rollenauswahl" im Filter einer Seite (siehe mf_role_filter_selection()).
 *
 * Das Feld steht neben den uebrigen Filtern der Seite. Eine Aenderung wird ohne eigenen Button sofort
 * uebernommen: die Seite wird mit der neuen Auswahl neu geladen (per POST, danach wieder per GET).
 *
 * @param HtmlForm $form       Filterformular der Seite (Typ navbar)
 * @param string   $key        recalculation | duedates | payments (bzw. ad_duedates, ad_payments)
 * @param string   $helpTextId Hilfetext zur Rollenauswahl
 */
function mf_role_filter_add(HtmlForm $form, HtmlPage $page, string $key, string $helpTextId): void
{
    global $gL10n, $gCurrentSession;

    $fieldId = $key . '_roleselection';
    $form->addSelectBox($fieldId, $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_SELECTION'), mf_contribution_role_select_entries(), array(
        'defaultValue' => mf_role_filter_selection($key) ?? '',
        'showContextDependentFirstEntry' => false,
        'helpTextId' => $helpTextId,
        'multiselect' => true
    ));

    // Aenderung sofort uebernehmen; kurz warten, solange die Auswahlliste noch offen ist
    $page->addJavascript('
        (function () {
            var select = $("#' . $fieldId . '");
            // breiter als die uebrigen Filter, damit mehrere Rollen nebeneinander Platz haben (auf dem Handy volle Breite)
            // eigene Mindestbreite (auch ohne gewaehlte Rolle) und Zeilenumbruch in der Filterleiste statt Zusammendruecken
            select.next(".select2-container").addClass("mf-rolefilter").css({"width": "100%"});
            select.closest(".admidio-form-group").css({"flex": "1 1 20rem", "min-width": "min(20rem, 100%)"});
            select.closest("form").css({"flex-wrap": "wrap", "row-gap": ".5rem"});
            // Rollen unterhalb der Feldbezeichnung anzeigen und so kompakt, dass das Feld so hoch ist wie die uebrigen Filter
            if (!document.getElementById("mf-rolefilter-style")) {
                $("head").append("<style id=\"mf-rolefilter-style\">"
                    + ".mf-rolefilter .select2-selection--multiple { min-height: calc(3.5rem + 2px) !important; padding: 1.65rem .75rem .4rem !important; }"
                    + ".mf-rolefilter .select2-selection__rendered { display: flex !important; flex-wrap: wrap; gap: .35rem; margin: 0 !important; padding: 0 !important; }"
                    + ".mf-rolefilter .select2-selection__choice { margin: 0 !important; padding: .1rem .5rem !important; font-size: .875rem !important; line-height: 1.45 !important; }"
                    + ".mf-rolefilter .select2-search--inline, .mf-rolefilter .select2-search__field { margin: 0 !important; height: 1.5rem !important; line-height: 1.5rem !important; }"
                    + "</style>");
            }
            var initial = JSON.stringify(select.val() || []);
            var timer = null;
            function applyRoleFilter() {
                if (JSON.stringify(select.val() || []) === initial) {
                    return;
                }
                var form = $("<form method=\"post\"></form>").attr("action", window.location.href);
                form.append($("<input type=\"hidden\" name=\"rolefilter\">").val(' . json_encode($key) . '));
                form.append($("<input type=\"hidden\" name=\"adm_csrf_token\">").val(' . json_encode($gCurrentSession->getCsrfToken()) . '));
                $.each(select.val() || [], function (i, value) {
                    form.append($("<input type=\"hidden\" name=\"' . $fieldId . '[]\">").val(value));
                });
                $("body").append(form);
                form.trigger("submit");
            }
            function schedule() {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    var plugin = select.data("select2");
                    if (plugin && plugin.isOpen()) {
                        return;
                    }
                    applyRoleFilter();
                }, 400);
            }
            select.on("change", schedule);
            select.on("select2:close", schedule);
        })();', true);
}

/**
 * Menue des Plugins (linke Spalte auf allen Seiten, siehe classes/Ui/SidebarPage.php).
 *
 * Gruppen wie die frueheren Registerkarten; je Eintrag:
 *  - key    eindeutiger Schluessel (Gruppe_Panel), zugleich Parameter show= der Hauptseite
 *  - title  Bezeichnung mit Schrittnummer
 *  - icon   Bootstrap-Icon
 *  - url    Ziel: eigene Seite (Liste usw.) oder membership_fee.php?show=<key> (Inhalt auf der Hauptseite)
 *  - script / collection  zum Markieren des aktiven Eintrags auf den eigenen Seiten
 *
 * @return array<int, array{label:string, entries:array<int, array{key:string, title:string, icon:string, url:string, script:string, collection:string}>}>
 */
function mf_menu_groups(): array
{
    global $gL10n, $gSettingsManager, $gCurrentUser;

    $pPreferences = new ConfigTable();
    $pPreferences->read();
    $mf = \Plugins\MembershipFee\classes\Bookings\CollectionScope::MEMBERSHIP;
    $ad = \Plugins\MembershipFee\classes\Bookings\CollectionScope::WORK;

    $page = static function (string $key, string $title, string $icon, string $script, string $collection = ''): array {
        // menu=1: Aufruf aus dem Menue, die Brotkrumen beginnen wieder bei "Mitgliedsbeitrag" (siehe mf_page_headline())
        $params = array_merge($collection !== '' ? array('collection' => $collection) : array(), array('menu' => 1));
        return array(
            'key' => $key, 'title' => $title, 'icon' => $icon, 'script' => $script, 'collection' => $collection,
            'url' => SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/' . $script, $params)
        );
    };
    $main = static function (string $key, string $title, string $icon): array {
        return array(
            'key' => $key, 'title' => $title, 'icon' => $icon, 'script' => 'membership_fee.php', 'collection' => '',
            'url' => SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array('show' => $key))
        );
    };

    $groups = array();

    // Schrittnummern wie 1.0, 1.1, 2.0 (Hauptschritt.Unterschritt)
    $num = static fn (int $major, int $minor): string => $major . '.' . $minor . ' ';

    // Beitragsberechnung (Schritt 1 im Buchungskreis Mitgliedsbeitrag)
    $minor = 0;
    $entries = array();
    if (in_array(1, (array) ($pPreferences->config['tests_enable'] ?? array()))) {
        $entries[] = $page('fees_tests', $num(1, $minor++) . $gL10n->get('PLG_MEMBERSHIPFEE_TESTS'), 'bi-heart-pulse', 'tests.php', $mf);
    }
    $entries[] = $page('fees_recalculation', $num(1, $minor++) . $gL10n->get('PLG_MEMBERSHIPFEE_MENU_CALCULATE'), 'bi-calculator', 'recalculation.php', $mf);
    if (!empty($pPreferences->config['individual_contributions']['access_to_module'])) {
        $entries[] = $page('fees_individualcontributions', $num(1, $minor++) . $gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS'), 'bi-house-gear', 'individualcontributions.php', $mf);
    }
    $groups[] = array('label' => $gL10n->get('PLG_MEMBERSHIPFEE_MENU_GROUP_FEE_CALC'), 'entries' => $entries);

    // Arbeitsdienstberechnung (Schritt 1 im Buchungskreis Arbeitsdienst)
    $groups[] = array('label' => $gL10n->get('PLG_MEMBERSHIPFEE_MENU_GROUP_WORK'), 'entries' => array(
        $page('workservice_worktargets', $num(1, 0) . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS'), 'bi-bullseye', 'work_targets.php', $ad),
        $page('workservice_workinput', $num(1, 1) . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_INPUT'), 'bi-pencil-square', 'work_input.php', $ad),
        $page('workservice_workbilling', $num(1, 2) . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING'), 'bi-receipt', 'work_billing.php', $ad)
    ));

    // Einzug (Schritte 2 bis 5, gemeinsam fuer Mitgliedsbeitrag und Arbeitsdienst). Ohne collection-Parameter
    // gilt der zuletzt gewaehlte Buchungskreis; 'switch' blendet auf der Seite die Umschaltung ein.
    $shared = static function (array $entry, bool $switch = true): array {
        $entry['switch'] = $switch;
        return $entry;
    };
    $groups[] = array('label' => $gL10n->get('PLG_MEMBERSHIPFEE_MENU_GROUP_COLLECTION'), 'entries' => array(
        // Rechnungen erstellen setzt fuer Lastschrift-Zahler auch das Einzugsdatum (frueher eigener Schritt Faelligkeit)
        $shared($page('collection_bill', $num(2, 0) . $gL10n->get('PLG_MEMBERSHIPFEE_MENU_BILL'), 'bi-receipt', 'bill.php')),
        $shared($page('collection_prenotification', $num(2, 1) . $gL10n->get('PLG_MEMBERSHIPFEE_MENU_DISPATCH'), 'bi-envelope-paper', 'pre_notification.php')),
        // 3.0 Lastschrifteinzug enthaelt auch "SEPA-Dateien erzeugen" (frueher eigener Menuepunkt)
        $shared($page('collection_runs', $num(3, 0) . $gL10n->get('PLG_MEMBERSHIPFEE_MENU_DIRECT_DEBIT'), 'bi-bank', 'runs.php'), false),
        $shared($page('collection_payments', $num(3, 1) . $gL10n->get('PLG_MEMBERSHIPFEE_MENU_MANUAL_PAYMENTS'), 'bi-cash-coin', 'payments.php')),
        $shared($page('collection_analysis', $num(4, 0) . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_ANALYSIS'), 'bi-bar-chart', 'analysis.php')),
        $shared($page('collection_bookingoverview', $num(5, 0) . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_OVERVIEW'), 'bi-clock-history', 'booking_overview.php'))
    ));

    // Mandatsverwaltung
    $groups[] = array('label' => $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT'), 'entries' => array(
        $main('mandatemanagement_createmandateid', $gL10n->get('PLG_MEMBERSHIPFEE_CREATE_MANDATE_ID'), 'bi-list-ol'),
        $page('mandatemanagement_mandates', $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_EDIT'), 'bi-pen', 'mandates.php', $mf)
    ));

    // Einstellungen
    if (isUserAuthorizedForPreferences()) {
        $entries = array();
        $preferences = array(
            'contributionsettings' => array('PLG_MEMBERSHIPFEE_CONTRIBUTION_SETTINGS', 'bi-database-gear'),
            'advancedroleediting' => array('PLG_MEMBERSHIPFEE_ADVANCED_ROLE_EDITING', 'bi-person-gear'),
            'individualcontributions' => array('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS', 'bi-house-gear'),
            'contributiongroups' => array('PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUPS', 'bi-diagram-3'),
            'workservice' => array('PLG_MEMBERSHIPFEE_WORK_SETTINGS', 'bi-tools'),
            'manualtemplates' => array('PLG_MEMBERSHIPFEE_MANUAL_TEMPLATES', 'bi-receipt'),
            'profileaddin' => array('PLG_MEMBERSHIPFEE_PROFILE_ADDIN', 'bi-person-vcard'),
            'accountdata' => array('PLG_MEMBERSHIPFEE_ACCOUNT_DATA', 'bi-bank'),
            'mandatemanagement' => array('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT', 'bi-sliders2'),
            'columnset' => array('PLG_MEMBERSHIPFEE_VIEW_DEFINITIONS', 'bi-eyeglasses'),
            'export' => array('PLG_MEMBERSHIPFEE_EXPORT', 'bi-download'),
            'email_notifications' => array('PLG_MEMBERSHIPFEE_EMAIL_NOTIFICATIONS', 'bi-envelope'),
            'testssetup' => array('PLG_MEMBERSHIPFEE_TESTS', 'bi-heart-pulse'),
            'access_preferences' => array('PLG_MEMBERSHIPFEE_ACCESS_PREFERENCES', 'bi-key-fill'),
            'delete' => array('PLG_MEMBERSHIPFEE_RESET', 'bi-trash'),
            'uninstallation' => array('PLG_MEMBERSHIPFEE_UNINSTALLATION', 'bi-trash'),
            'plugin_informations' => array('PLG_MEMBERSHIPFEE_PLUGIN_INFORMATION', 'bi-info-circle')
        );
        foreach ($preferences as $id => $definition) {
            if ($id === 'profileaddin' && !$gCurrentUser->isAdministrator()) {
                continue;
            }
            $entries[] = $main('preferences_' . $id, $gL10n->get($definition[0]), $definition[1]);
        }
        $groups[] = array('label' => $gL10n->get('SYS_SETTINGS'), 'entries' => $entries);
    }

    // Dokumentation (nur deutsch)
    if (in_array($gSettingsManager->getString('system_language'), array('de', 'de-DE'), true)
        && is_file(__DIR__ . '/../docs/documentation-de.html')) {
        $groups[] = array('label' => $gL10n->get('PLG_MEMBERSHIPFEE_DOCUMENTATION'), 'entries' => array(
            $main('documentation', $gL10n->get('PLG_MEMBERSHIPFEE_DOCUMENTATION'), 'bi-book')
        ));
    }

    return $groups;
}

/**
 * Aktiver Menueeintrag einer eigenen Seite: nach Dateiname und Buchungskreis (bei duedates.php usw.).
 */
function mf_menu_active_key(array $groups): string
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    // Folgeseiten eines Eintrags der Hauptseite
    $aliases = array('create_mandate_id.php' => 'mandatemanagement_createmandateid');
    if (isset($aliases[$script])) {
        return $aliases[$script];
    }
    $collection = (string) ($_SESSION['pMembershipFee']['collection'] ?? \Plugins\MembershipFee\classes\Bookings\CollectionScope::MEMBERSHIP);
    $fallback = '';
    foreach ($groups as $group) {
        foreach ($group['entries'] as $entry) {
            if ($entry['script'] !== $script) {
                continue;
            }
            if ($entry['collection'] === '' || $entry['collection'] === $collection) {
                return $entry['key'];
            }
            $fallback = $fallback !== '' ? $fallback : $entry['key'];
        }
    }
    return $fallback;
}

/**
 * Seitenaufbau wie bei den Admidio-Einstellungen (Vorlage preferences/preferences.tpl): links die gruppierten
 * Menueeintraege als senkrechte Registerkarten, rechts der Inhalt der Seite. Auf kleinen Bildschirmen steht
 * das Menue zugeklappt ueber dem Inhalt.
 */
function mf_layout_html(array $groups, string $activeKey, string $content): string
{
    global $gL10n;

    $activeTitle = '';
    $items = '';
    foreach ($groups as $group) {
        $items .= '<li class="list-group-item group-heading" role="presentation"><h5 class="mb-0">' . SecurityUtils::encodeHTML($group['label']) . '</h5></li>';
        $count = count($group['entries']);
        foreach ($group['entries'] as $i => $entry) {
            $active = $entry['key'] === $activeKey;
            if ($active) {
                $activeTitle = $entry['title'];
            }
            $items .= '<li class="list-group-item nav-item' . ($i === $count - 1 ? ' group-last' : '') . '" role="presentation">'
                . '<a class="nav-link text-start' . ($active ? ' active' : '') . '" href="' . $entry['url'] . '"' . ($active ? ' aria-current="page"' : '') . '>'
                . '<i class="bi ' . $entry['icon'] . ' me-1"></i>' . SecurityUtils::encodeHTML($entry['title']) . '</a></li>';
        }
    }

    return '<button class="btn btn-secondary w-100 mb-3 d-md-none text-start" type="button" data-bs-toggle="collapse" data-bs-target="#mf_menu" aria-expanded="false" aria-controls="mf_menu">'
        . '<i class="bi bi-list me-1"></i>' . $gL10n->get('SYS_MENU') . ($activeTitle !== '' ? ': ' . SecurityUtils::encodeHTML($activeTitle) : '') . '</button>'
        . '<div class="tabs-x tabs-left tab-bordered mf-tabs" data-max-title-length="-1">'
        . '<ul id="mf_menu" class="nav nav-tabs flex-column list-group admidio-preferences-group collapse d-md-flex">' . $items . '</ul>'
        . '<div class="tab-content mf-tab-content">' . $content . '</div>'
        . '</div>';
}

/**
 * Umschaltung des Buchungskreises auf den gemeinsamen Einzugsseiten (Faelligkeit, Vorabinformation, SEPA-Datei,
 * Rechnungen, Beitragszahlungen, Buchungsuebersicht): verlinkt dieselbe Seite mit collection=mf bzw. collection=ad.
 */
function mf_collection_switch_html(): string
{
    global $gL10n;

    $current = mf_collection_scope()->key();
    $params = array();
    foreach ($_GET as $name => $value) {
        if (is_string($value) && $name !== 'collection') {
            $params[$name] = $value;
        }
    }
    $script = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'membership_fee.php'));

    $html = '<div class="d-flex flex-wrap align-items-center gap-2 mb-3"><span class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_COLLECTION_SCOPE') . ':</span>'
        . '<div class="btn-group" role="group">';
    $buttons = array(
        CollectionScope::MEMBERSHIP => array('PLG_MEMBERSHIPFEE_SCOPE_MF', 'bi-currency-euro'),
        CollectionScope::WORK => array('PLG_MEMBERSHIPFEE_SCOPE_AD', 'bi-tools')
    );
    foreach ($buttons as $key => [$label, $icon]) {
        $active = $key === $current;
        $html .= '<a class="btn px-3 ' . ($active ? 'btn-primary' : 'btn-outline-primary') . '"' . ($active ? ' aria-current="true"' : '')
            . ' href="' . SecurityUtils::encodeUrl($script, array_merge($params, array('collection' => $key))) . '">'
            . '<i class="bi ' . $icon . '"></i> ' . $gL10n->get($label)
            . ' <span class="badge ' . ($active ? 'bg-light text-primary' : 'bg-primary') . ' ms-1">' . mf_collection_year_label(CollectionScope::fromKey($key, (int) $GLOBALS['gCurrentOrgId'])) . '</span></a>';
    }
    return $html . '</div></div>';
}

/**
 * Seitentitel einer Seite aus dem Menue: Nummer und Bezeichnung des aktiven Menueeintrags
 * (z. B. "1.1 Beitraege berechnen"), damit Titel, Brotkrumen und Menue uebereinstimmen.
 * Ohne Zugriff auf das Menue (z. B. Arbeitsdienstleiter) oder ohne passenden Eintrag gilt $default.
 */
function mf_page_headline(string $default): string
{
    global $gNavigation, $gL10n;

    if (!isUserAuthorized()) {
        return $default;
    }
    // aus dem Menue aufgerufen: Navigation neu beginnen, damit die Brotkrumen "Mitgliedsbeitrag / <Seite>" lauten
    if (($_GET['menu'] ?? '') === '1' && isset($gNavigation)) {
        $gNavigation->addStartUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', $gL10n->get('PLG_MEMBERSHIPFEE_MEMBERSHIP_FEE'), 'bi-currency-euro');
    }
    $groups = mf_menu_groups();
    $key = mf_menu_active_key($groups);
    foreach ($groups as $group) {
        foreach ($group['entries'] as $entry) {
            if ($entry['key'] === $key) {
                return $entry['title'];
            }
        }
    }
    return $default;
}

/**
 * Schaltflaeche "Verschieben" einer Zeile in 2.0/2.1 (Tabellen Ueberweisung und SEPA-Einzug).
 * Nach SEPA nur mit IBAN und Mandats-ID; Rechnungen im Einzug, bezahlt oder storniert bleiben, wo sie sind.
 *
 * @param string $group      aktuelle Tabelle (SEPA | MAN)
 * @param string $autoMethod Zahlart aus dem Profil (InvoicePlanner::method())
 * @param string $status     Status der Rechnung (offen bei Zeilen ohne Rechnung)
 */
function mf_paymethod_move_button(int $usrId, int $year, string $group, string $autoMethod, string $status): string
{
    global $gL10n;

    if ($status === \Plugins\MembershipFee\classes\Invoices\InvoiceStatus::IN_COLLECTION) {
        return '<i class="bi bi-lock text-muted" title="' . SecurityUtils::encodeHTML($gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_LOCKED')) . '"></i>';
    }
    if ($status !== \Plugins\MembershipFee\classes\Invoices\InvoiceStatus::OPEN) {
        return '';
    }
    $target = $group === 'SEPA' ? 'MAN' : 'SEPA';
    $label = $gL10n->get($target === 'SEPA' ? 'PLG_MEMBERSHIPFEE_PAYMETHOD_TO_SEPA' : 'PLG_MEMBERSHIPFEE_PAYMETHOD_TO_MAN');
    $disabled = $target === 'SEPA' && $autoMethod !== 'SEPA';
    $title = $disabled ? $gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_NO_MANDATE') : $gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_MOVE_DESC', array($year));
    return '<button type="button" class="btn btn-sm btn-outline-secondary text-nowrap mf-move" data-usr="' . $usrId . '" data-year="' . $year
        . '" data-target="' . $target . '" title="' . SecurityUtils::encodeHTML($title) . '"' . ($disabled ? ' disabled' : '') . '>'
        . '<i class="bi bi-arrow-left-right"></i> ' . SecurityUtils::encodeHTML($label) . '</button>';
}

/**
 * Dialog (Einzugsdatum fuer eine Rechnung, die nach SEPA wechselt) und JavaScript fuer die Schaltflaechen "Verschieben".
 */
function mf_paymethod_move_assets(HtmlPage $page, CollectionScope $scope): void
{
    global $gL10n, $gCurrentSession;

    $blockedDays = array();
    for ($holidayYear = (int) date('Y'); $holidayYear <= (int) date('Y') + 1; $holidayYear++) {
        $blockedDays = array_merge($blockedDays, mf_bank_holidays($holidayYear));
    }
    $options = '';
    foreach (array('AUTO' => 'PLG_MEMBERSHIPFEE_AUTO_DIRECT_DEBIT', 'RCUR' => 'PLG_MEMBERSHIPFEE_FOLLOW_DIRECT_DEBIT',
        'FRST' => 'PLG_MEMBERSHIPFEE_FIRST_DIRECT_DEBIT', 'OOFF' => 'PLG_MEMBERSHIPFEE_ONETIMES_DIRECT_DEBIT',
        'FNAL' => 'PLG_MEMBERSHIPFEE_FINAL_DIRECT_DEBIT') as $value => $label) {
        $options .= '<option value="' . $value . '">' . SecurityUtils::encodeHTML($gL10n->get($label)) . '</option>';
    }

    $page->addHtml('<div class="modal fade" id="mf_move_modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title">' . $gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_TO_SEPA') . '</h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
        . '<div class="modal-body"><p>' . $gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_NEED_DATE') . '</p>'
        . '<label class="form-label" for="mf_move_date">' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_DEBIT_DATE') . '</label>'
        . '<input type="date" class="form-control mb-2" id="mf_move_date" min="' . DATE_NOW . '">'
        . '<label class="form-label" for="mf_move_sequencetype">' . $gL10n->get('PLG_MEMBERSHIPFEE_SEQUENCETYPE') . '</label>'
        . '<select class="form-select" id="mf_move_sequencetype">' . $options . '</select>'
        . '<div id="mf_move_problem" class="text-danger fw-bold mt-2"></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . $gL10n->get('SYS_CANCEL') . '</button>'
        . '<button type="button" class="btn btn-primary" id="mf_move_confirm"><i class="bi bi-check-lg"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_TO_SEPA') . '</button></div>'
        . '</div></div></div>');

    $url = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/paymethod.php', array('collection' => $scope->key()));
    $page->addJavascript('
        var mfMoveToken = ' . json_encode($gCurrentSession->getCsrfToken()) . ';
        var mfMoveHolidays = ' . json_encode($blockedDays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
        var mfMoveToday = ' . json_encode(DATE_NOW) . ';
        var mfMovePending = null;
        function mfMoveProblem(value) {
            if (!/^\\d{4}-\\d{2}-\\d{2}$/.test(value)) { return "kein gültiges Datum"; }
            if (value < mfMoveToday) { return "liegt in der Vergangenheit"; }
            var parts = value.split("-");
            var day = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).getDay();
            if (day === 6) { return "Samstag"; }
            if (day === 0) { return "Sonntag"; }
            return mfMoveHolidays[value] || "";
        }
        function mfMoveSend(data, button) {
            data.adm_csrf_token = mfMoveToken;
            $(button).prop("disabled", true);
            $.post(' . json_encode($url) . ', data, function(result) {
                if (result.ok) {
                    if (result.pdf_errors && result.pdf_errors.length > 0) {
                        alert(result.pdf_errors.join("\\n"));
                    }
                    window.location.reload();
                    return;
                }
                $(button).prop("disabled", false);
                if (result.need_date) {
                    mfMovePending = data;
                    $("#mf_move_date").val(result.debit_default);
                    $("#mf_move_problem").text("");
                    bootstrap.Modal.getOrCreateInstance(document.getElementById("mf_move_modal")).show();
                    return;
                }
                alert(result.message);
            }, "json").fail(function() { $(button).prop("disabled", false); alert(' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_FAILED')) . '); });
        }
        $(document).on("click", ".mf-move", function() {
            mfMoveSend({usr_id: $(this).data("usr"), year: $(this).data("year"), target: $(this).data("target")}, this);
        });
        $("#mf_move_confirm").click(function() {
            var value = $("#mf_move_date").val() || "";
            var problem = mfMoveProblem(value);
            if (problem !== "") {
                $("#mf_move_problem").text(value.split("-").reverse().join(".") + " ist als Einzugsdatum nicht möglich (" + problem + ").");
                return;
            }
            var data = $.extend({}, mfMovePending, {debit_date: value, sequencetype: $("#mf_move_sequencetype").val()});
            mfMoveSend(data, this);
        });
    ', true);
}

/**
 * Mitglieder, die per "Verschieben" fuer ein Beitragsjahr mit offenen (oder exportierten) Positionen auf
 * Ueberweisung gestellt sind. Sie gehoeren nicht in die SEPA-Datei.
 *
 * @return array<int, true> usr_id => true
 */
function mf_paymethod_override_users(CollectionScope $scope): array
{
    $overrides = \Plugins\MembershipFee\classes\Invoices\PaymentMethodStore::all($scope->key());
    if (count($overrides) === 0) {
        return array();
    }
    $result = array();
    foreach (BookingStore::getByUsers(array_keys($overrides), array(BookingStore::STATUS_OPEN, BookingStore::STATUS_EXPORTED)) as $usrId => $positions) {
        foreach ($scope->filterPositions($positions) as $position) {
            if (($overrides[(int) $usrId][(int) $position['mfb_year']] ?? null) === \Plugins\MembershipFee\classes\Invoices\InvoicePlanner::METHOD_MANUAL) {
                $result[(int) $usrId] = true;
                break;
            }
        }
    }
    return $result;
}

/**
 * Ueberweiser unter den angegebenen Mitgliedern: ohne IBAN oder Mandats-ID oder per "Verschieben" auf Ueberweisung.
 *
 * @param array<int, int> $usrIds
 * @return array<int, true> usr_id => true
 */
function mf_paymethod_transfer_users(CollectionScope $scope, array $usrIds): array
{
    global $gDb, $gProfileFields, $gCurrentOrgId;

    $usrIds = array_values(array_unique(array_map('intval', $usrIds)));
    $result = mf_paymethod_override_users($scope);
    if (count($usrIds) === 0) {
        return array();
    }
    $usfIban = (int) $gProfileFields->getProperty('IBAN', 'usf_id');
    $usfMandate = (int) $gProfileFields->getProperty('MANDATEID' . (int) $gCurrentOrgId, 'usf_id');
    $sepa = array();
    foreach (array_chunk($usrIds, 500) as $chunk) {
        $statement = $gDb->queryPrepared('SELECT iban.usd_usr_id
              FROM ' . TBL_USER_DATA . ' iban
              JOIN ' . TBL_USER_DATA . ' mandate ON mandate.usd_usr_id = iban.usd_usr_id AND mandate.usd_usf_id = ? AND mandate.usd_value <> \'\'
             WHERE iban.usd_usf_id = ? AND iban.usd_value <> \'\'
               AND iban.usd_usr_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_merge(array($usfMandate, $usfIban), $chunk));
        while ($row = $statement->fetch()) {
            $sepa[(int) $row['usd_usr_id']] = true;
        }
    }
    $transfer = array();
    foreach ($usrIds as $usrId) {
        if (!isset($sepa[$usrId]) || isset($result[$usrId])) {
            $transfer[$usrId] = true;
        }
    }
    return $transfer;
}

/**
 * Profilwerte vieler Mitglieder mit wenigen Abfragen (statt je Mitglied User::readDataById()), roh aus der
 * Datenbank. Liefert zusaetzlich 'usr_uuid'. Fehlende Felder sind ''.
 *
 * @param array<int, int> $usrIds
 * @param array<int, string> $fieldNames interne Feldnamen (usf_name_intern)
 * @return array<int, array<string, string>> usr_id => (Feldname => Wert)
 */
function mf_profile_values(array $usrIds, array $fieldNames): array
{
    global $gDb, $gProfileFields;

    $usrIds = array_values(array_unique(array_map('intval', $usrIds)));
    $result = array();
    if (count($usrIds) === 0) {
        return $result;
    }
    $fieldIds = array();
    foreach ($fieldNames as $name) {
        $fieldIds[(int) $gProfileFields->getProperty($name, 'usf_id')] = $name;
    }
    unset($fieldIds[0]);
    foreach (array_chunk($usrIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $statement = $gDb->queryPrepared('SELECT usr_id, usr_uuid FROM ' . TBL_USERS . ' WHERE usr_id IN (' . $placeholders . ')', $chunk);
        while ($row = $statement->fetch()) {
            $result[(int) $row['usr_id']] = array_fill_keys($fieldNames, '') + array('usr_uuid' => (string) $row['usr_uuid']);
        }
        if (count($fieldIds) > 0) {
            $statement = $gDb->queryPrepared('SELECT usd_usr_id, usd_usf_id, usd_value FROM ' . TBL_USER_DATA
                . ' WHERE usd_usr_id IN (' . $placeholders . ') AND usd_usf_id IN (' . implode(',', array_fill(0, count($fieldIds), '?')) . ')',
                array_merge($chunk, array_keys($fieldIds)));
            while ($row = $statement->fetch()) {
                if (isset($result[(int) $row['usd_usr_id']])) {
                    $result[(int) $row['usd_usr_id']][$fieldIds[(int) $row['usd_usf_id']]] = (string) $row['usd_value'];
                }
            }
        }
    }
    return $result;
}

/**
 * Block "SEPA-Dateien erzeugen" eines Buchungskreises (Lastschrifteinzug, frueher eigener Menuepunkt):
 * Tabelle der Faelligkeiten je Sequenztyp, Schalter "zusammenfassen" und Button mit der Anzahl der Dateien.
 * Ohne offene Lastschriften nur ein Hinweis.
 */
function mf_sepa_create_block(HtmlPage $page, CollectionScope $scope): string
{
    global $gL10n, $pPreferences;

    $duedates = mf_sepa_duedate_overview($scope);
    if (count($duedates) === 0) {
        return '<div class="alert alert-info" role="alert"><i class="bi bi-info-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_NONE_OPEN') . '</div>';
    }

    $formId = 'sepa_export_form_' . $scope->key();
    $html = '<style>#' . $formId . ' label.col-sm-3 { display: none; } #' . $formId . ' .col-sm-9 { flex: 0 0 100%; max-width: 100%; margin-left: 0; }</style>'
        . '<table class="table table-condensed"><thead><tr><th style="text-align: center;">' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE') . '</th>';
    foreach (array('FRST', 'RCUR', 'FNAL', 'OOFF') as $sequenceType) {
        $html .= '<th style="text-align: center;">' . $sequenceType . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    // Dateien: zusammengefasst je Faelligkeitsdatum eine, sonst je Faelligkeitsdatum und Sequenztyp (siehe sepa_create.php)
    $fileCounts = array('combined' => 0, 'separate' => 0);
    foreach ($duedates as $duedate => $counts) {
        $html .= '<tr><td style="text-align: center;">' . mf_format_date($duedate) . '</td>';
        foreach ($counts as $count) {
            $html .= '<td style="text-align: center;">' . ($count > 0 ? $count : '<span class="text-muted">–</span>') . '</td>';
        }
        $html .= '</tr>';
        $types = count(array_filter(array_map('intval', array_values($counts))));
        $fileCounts['combined'] += $types > 0 ? 1 : 0;
        $fileCounts['separate'] += $types;
    }
    $html .= '</tbody></table>';

    $form = new HtmlForm($formId, ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/sepa_create.php', $page);
    $form->addCustomContent('', '<input type="hidden" name="collection" value="' . $scope->key() . '" />');
    $form->addCheckbox('combine_sequence_types', $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_COMBINE'), (bool) ($pPreferences->config['SEPA']['combine_sequence_types'] ?? 1), array(
        'helpTextId' => 'PLG_MEMBERSHIPFEE_SEPA_COMBINE_DESC'
    ));
    $form->addSubmitButton('btn_create_export_file_' . $scope->key(), $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_CREATE_FILES'), array(
        'icon' => 'bi-filetype-xml',
        'class' => 'btn-primary'
    ));
    $html .= $form->show();

    // Beschriftung "x SEPA-Dateien erzeugen", abhaengig vom Schalter
    $html .= '<script>$(function() {
        var form = $("#' . $formId . '"), counts = ' . json_encode($fileCounts) . ';
        var one = ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_SEPA_CREATE_FILE_ONE')) . ', many = ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_SEPA_CREATE_FILES_COUNT')) . ';
        form.find("button[type=submit]").each(function() {
            var icon = $(this).find("i").first().prop("outerHTML") || "";
            $(this).html(icon + " <span class=\"mf-sepa-label\"></span>");
        });
        function update() {
            var n = form.find("input[name=combine_sequence_types]").prop("checked") ? counts.combined : counts.separate;
            form.find(".mf-sepa-label").text(n === 1 ? one : many.replace("#VAR1#", n));
        }
        form.on("change", "input[name=combine_sequence_types]", update);
        update();
    });</script>';
    return $html;
}
