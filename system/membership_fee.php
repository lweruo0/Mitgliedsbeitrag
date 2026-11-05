<?php
/**
 ***********************************************************************************************
 * Creates the main view for the admidio plugin  Mitgliedsbeitrag / Membership fee
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Entity\Text;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Roles\Entity\Role;
use Plugins\MembershipFee\classes\Bookings\ProfileAddin;
use Plugins\MembershipFee\classes\Config\ConfigTable;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/common_function.php');

    // only authorized user are allowed to start this module
    if (! isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    // Initialize and check the parameters
    $getChoice = admFuncVariableIsValid($_GET, 'choice', 'string', array(
        'defaultValue' => ''
    ));
    $getConf = admFuncVariableIsValid($_GET, 'conf', 'numeric');
    // angezeigter Inhalt rechts neben dem Menue (siehe mf_menu_groups()); Standard: Uebersicht
    $getShow = admFuncVariableIsValid($_GET, 'show', 'string', array('defaultValue' => ''));
    if ($getShow === '' && $getChoice !== '') {
        $getShow = 'preferences_' . $getChoice;
    }
    // ohne Auswahl (Aufruf des Plugins): erster Eintrag des Menues, z. B. 1.0 Pruefungen
    if ($getShow === '' || $getShow === 'overview' || preg_match('/^[a-z_]+$/', $getShow) !== 1) {
        $menuGroups = mf_menu_groups();
        admRedirect($menuGroups[0]['entries'][0]['url']);
        // => EXIT
    }

    // Einzugsseiten (Faelligkeit, Vorabinformation, ...) starten im Buchungskreis Mitgliedsbeitrag;
    // die Schritte der Registerkarte Arbeitsdienst uebergeben collection=ad (siehe mf_collection_scope())
    // Buchungskreis: per collection-Parameter (Menue, Umschaltung) gewaehlt, sonst der zuletzt verwendete
    $mfScope = mf_collection_scope();

    $pPreferences = new ConfigTable();
    $pPreferences->read();

    $duedates = array();
    $directdebittype = false;

    // alle Mitglieder einlesen
    $members = list_members(array(
        'DUEDATE' . $gCurrentOrgId,
        'SEQUENCETYPE' . $gCurrentOrgId,
        'CONTRIBUTORY_TEXT' . $gCurrentOrgId,
        'PAID' . $gCurrentOrgId,
        'FEE' . $gCurrentOrgId,
        'MANDATEID' . $gCurrentOrgId,
        'MANDATEDATE' . $gCurrentOrgId,
        'IBAN',
        'BIC'
    ), 0);

    // jetzt wird gezaehlt
    foreach ($members as $member => $memberdata) {
        // alle Faelligkeitsdaten einlesen
        if (! empty($memberdata['DUEDATE' . $gCurrentOrgId]) && ! empty($memberdata['FEE' . $gCurrentOrgId]) && empty($memberdata['PAID' . $gCurrentOrgId]) && 
        // && !empty($memberdata['CONTRIBUTORY_TEXT'.$gCurrentOrgId])
        ! empty($memberdata['IBAN'])) {
            $directdebittype = true;

            if (! isset($duedates[$memberdata['DUEDATE' . $gCurrentOrgId]])) {
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]] = array();
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]]['FNAL'] = 0;
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]]['RCUR'] = 0;
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]]['OOFF'] = 0;
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]]['FRST'] = 0;
            }

            if ($memberdata['SEQUENCETYPE' . $gCurrentOrgId] == 'FNAL') {
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]]['FNAL'] ++;
            } elseif ($memberdata['SEQUENCETYPE' . $gCurrentOrgId] == 'RCUR') {
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]]['RCUR'] ++;
            } elseif ($memberdata['SEQUENCETYPE' . $gCurrentOrgId] == 'OOFF') {
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]]['OOFF'] ++;
            } else {
                $duedates[$memberdata['DUEDATE' . $gCurrentOrgId]]['FRST'] ++;
            }
        }
    }
    unset($members);

    $rols = beitragsrollen_einlesen();
    $sortArray = array();
    $selectBoxEntriesBeitragsrollen = array();

    foreach ($rols as $key => $data) {
        $selectBoxEntriesBeitragsrollen[$key] = array(
            $key,
            $data['rolle'],
            expand_rollentyp($data['rollentyp'])
        );
        $sortArray[$key] = expand_rollentyp($data['rollentyp']);
    }
    array_multisort($sortArray, SORT_ASC, $selectBoxEntriesBeitragsrollen);
    unset($sortArray);

    $selectBoxEntriesAlleRollen = 'SELECT rol_id, rol_name, cat_name
          						 FROM ' . TBL_ROLES . '
    					   INNER JOIN ' . TBL_CATEGORIES . '
                                   ON cat_id = rol_cat_id
                                WHERE rol_valid   = true
                                  AND (  cat_org_id  = ' . $gCurrentOrgId . '
                                   OR cat_org_id IS NULL )
                             ORDER BY cat_sequence, rol_name';

    if ($getChoice == 'individualcontributions') {
        if ($getConf == - 1) {
            $pPreferences->config['individual_contributions']['desc'][] = $pPreferences->config_default['individual_contributions']['desc'][0];
            $pPreferences->config['individual_contributions']['short_desc'][] = $pPreferences->config_default['individual_contributions']['short_desc'][0];
            $pPreferences->config['individual_contributions']['role'][] = $pPreferences->config_default['individual_contributions']['role'][0];
            $pPreferences->config['individual_contributions']['amount'][] = $pPreferences->config_default['individual_contributions']['amount'][0];
            $pPreferences->config['individual_contributions']['profilefield'][] = $pPreferences->config_default['individual_contributions']['profilefield'][0];
        } else {
            array_splice($pPreferences->config['individual_contributions']['desc'], $getConf, 1);
            array_splice($pPreferences->config['individual_contributions']['short_desc'], $getConf, 1);
            array_splice($pPreferences->config['individual_contributions']['role'], $getConf, 1);
            array_splice($pPreferences->config['individual_contributions']['amount'], $getConf, 1);
            array_splice($pPreferences->config['individual_contributions']['profilefield'], $getConf, 1);
        }
    }

    $num_individualcontributions = count($pPreferences->config['individual_contributions']['desc']);

    // Beitragsgruppen und Vorlagen fuer manuelle Positionen (fehlen evtl. noch vor dem ersten Init)
    foreach (array('Beitragsgruppen' => array('name', 'roles'), 'Positionsvorlagen' => array('name', 'amount', 'text'), 'Arbeitsdienst' => array('soll_rollen', 'soll_stunden')) as $section => $keys) {
        foreach ($keys as $key) {
            if (!isset($pPreferences->config[$section][$key]) || !is_array($pPreferences->config[$section][$key])) {
                $pPreferences->config[$section][$key] = $pPreferences->config_default[$section][$key] ?? array(' ');
            }
        }
    }
    foreach (array('contributiongroups' => array('Beitragsgruppen', array('name', 'roles')), 'manualtemplates' => array('Positionsvorlagen', array('name', 'amount', 'text')), 'workservice' => array('Arbeitsdienst', array('soll_rollen', 'soll_stunden'))) as $choice => $sectionData) {
        if ($getChoice !== $choice || !isset($_GET['conf'])) {
            continue;
        }
        list($section, $keys) = $sectionData;
        foreach ($keys as $key) {
            if ($getConf == - 1) {
                $pPreferences->config[$section][$key][] = ' ';
            } elseif (isset($pPreferences->config[$section][$key][$getConf])) {
                array_splice($pPreferences->config[$section][$key], $getConf, 1);
            }
        }
    }
    $num_contributiongroups = count($pPreferences->config['Beitragsgruppen']['name']);
    $num_manualtemplates = count($pPreferences->config['Positionsvorlagen']['name']);
    $num_workservice = count($pPreferences->config['Arbeitsdienst']['soll_rollen']);

    $fixrollen = beitragsrollen_einlesen('fix');
    $alt_fix_rollen = $fixrollen;

    // das Array fuer die Auswahl der Profilfelder plus evtl. Zusatzfelder erzeugen
    $fieldSelectionList = array();
    $i = 1;
    foreach ($gProfileFields->getProfileFields() as $field) {
        if ($field->getValue('usf_hidden') == 0 || $gCurrentUser->isAdministratorUsers()) {
            $fieldSelectionList[$i]['id'] = 'p' . $field->getValue('usf_id');
            $fieldSelectionList[$i]['cat_name'] = $field->getValue('cat_name');
            $fieldSelectionList[$i]['data'] = addslashes($field->getValue('usf_name'));
            $i ++;
        }
    }

    $headline = $gL10n->get('PLG_MEMBERSHIPFEE_MEMBERSHIP_FEE');

    $gNavigation->addStartUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', $headline, 'bi-currency-euro');

    // create html page object
    // Seitentitel und Brotkrumen wie der gewaehlte Menueeintrag (z. B. "4.0 SEPA-Datei erstellen");
    // die Startseite (Uebersicht) behaelt den Titel "Mitgliedsbeitrag"
    $menuTitle = '';
    if ($getShow !== 'overview') {
        foreach (mf_menu_groups() as $menuGroup) {
            foreach ($menuGroup['entries'] as $menuEntry) {
                if ($menuEntry['key'] === $getShow) {
                    $menuTitle = $menuEntry['title'];
                }
            }
        }
        if ($menuTitle !== '') {
            $gNavigation->addUrl(CURRENT_URL, $menuTitle);
        }
    }

    $page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-membership-fee-main', $headline);
    $page->setActiveMenuEntry($getShow);

    if ($menuTitle !== '') {
        $page->setHeadline($menuTitle);
        $page->setTitle($menuTitle);
    }

    // Inhalte der Menueeintraege (Schluessel Gruppe_Panel), siehe getMenuePanel(); angezeigt wird nur der gewaehlte
    $GLOBALS['mfPanels'] = array();

    $page->addJavascript('
    function cischieben(){
        var ci = $("input[type=text]#ci").val();
        var origci = $("input[type=text]#origci").val(ci);
        $("input[type=text]#ci").val("");
    };
    function creditorschieben(){
        var creditor = $("input[type=text]#creditor").val();
        var origcreditor = $("input[type=text]#origcreditor").val(creditor);
        $("input[type=text]#creditor").val("");
    };'); // !!!: ohne true

    $page->addJavascript('
     $(".form-preferences").submit(function(event) {
        var id = $(this).attr("id");
        var action = $(this).attr("action");
        var formAlert = $("#" + id + " .form-alert");
        formAlert.hide();
       //  alert("id:" + id + " action: " + action + " formAlert: " +  formAlert);
        // disable default form submit
        event.preventDefault();

        $.post({

            url: action,
            data: $(this).serialize(),
            success: function(data) {
                if (data === "success") {
                    formAlert.attr("class", "alert alert-success form-alert");
                    formAlert.html("<i class=\"bi bi-check-lg\"></i><strong>' . $gL10n->get('SYS_SAVE_DATA') . '</strong>");
                    formAlert.fadeIn("slow");
                    formAlert.animate({opacity: 1.0}, 2500);
                    formAlert.fadeOut("slow");
                }
                else if(data === "success_replace") {
                   formAlert.attr("class", "alert alert-success form-alert");
                    formAlert.html("<i class=\"bi bi-check-lg\"></i><strong>' . $gL10n->get('SYS_SAVE_DATA') . '</strong>");
                    formAlert.fadeIn("slow");
                    formAlert.animate({opacity: 1.0}, 2500);
                    formAlert.fadeOut("slow");
                    window.location.replace("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
        'choice' => 'advancedroleediting'
    )) . '");
                }
 else if(data === "success_export") {
                   formAlert.attr("class", "alert alert-success form-alert");
                    formAlert.html("<i class=\"bi bi-check-lg\"></i><strong>' . $gL10n->get('SYS_SAVE_DATA') . '</strong>");
                    formAlert.fadeIn("slow");
                    formAlert.animate({opacity: 1.0}, 2500);
                    formAlert.fadeOut("slow");
                    window.location.replace("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
        'choice' => 'export'
    )) . '");
                }
                else {
                    formAlert.attr("class", "alert alert-danger form-alert");
                    formAlert.fadeIn();
                    formAlert.html("<i class=\"bi bi-exclamation-circle\"></i>" + data);
                }
            }
        });
    });', true);

    $javascriptCode = 'var arr_user_fields = createProfileFieldsArray(); ';

    // create an array with the necessary data
    foreach ($pPreferences->config['columnconfig'] as $conf => $confFields) {
        $javascriptCode .= '
        var arr_default_fields' . $conf . ' = createColumnsArray' . $conf . '();
        var fieldNumberIntern' . $conf . '  = 0;

    	// Funktion fuegt eine neue Zeile zum Zuordnen von Spalten fuer die Liste hinzu
    	function addColumn' . $conf . '()
    	{
        	var category = "";
        	var fieldNumberShow  = fieldNumberIntern' . $conf . ' + 1;
        	var table = document.getElementById("mylist_fields_tbody' . $conf . '");
        	var newTableRow = table.insertRow(fieldNumberIntern' . $conf . ');
        	newTableRow.setAttribute("id", "row" + (fieldNumberIntern' . $conf . ' + 1))
        	//$(newTableRow).css("display", "none"); // ausgebaut wg. Kompatibilitaetsproblemen im IE8
        	var newCellCount = newTableRow.insertCell(-1);
        	newCellCount.innerHTML = (fieldNumberShow) + ".&nbsp;' . $gL10n->get('SYS_COLUMN') . '&nbsp;:";

        	// neue Spalte zur Auswahl des Profilfeldes
        	var newCellField = newTableRow.insertCell(-1);
        	htmlCboFields = "<select class=\"form-control\"  size=\"1\" id=\"column" + fieldNumberShow + "\" class=\"ListProfileField\" name=\"column' . $conf . '_" + fieldNumberShow + "\">" +
                "<option value=\"\"></option>";
        	for(var counter = 1; counter < arr_user_fields.length; counter++)
        	{
            	if(category != arr_user_fields[counter]["cat_name"])
            	{
                	if(category.length > 0)
                	{
                    	htmlCboFields += "</optgroup>";
                	}
                	htmlCboFields += "<optgroup label=\"" + arr_user_fields[counter]["cat_name"] + "\">";
                	category = arr_user_fields[counter]["cat_name"];
            	}

            	var selected = "";

            	// bei gespeicherten Listen das entsprechende Profilfeld selektieren
            	// und den Feldnamen dem Listenarray hinzufuegen
            	if(arr_default_fields' . $conf . '[fieldNumberIntern' . $conf . '])
            	{
                	if(arr_user_fields[counter]["id"] == arr_default_fields' . $conf . '[fieldNumberIntern' . $conf . ']["id"])
                	{
                    	selected = " selected=\"selected\" ";
                   	 	arr_default_fields' . $conf . '[fieldNumberIntern' . $conf . ']["data"] = arr_user_fields[counter]["data"];
                	}
            	}
             	htmlCboFields += "<option value=\"" + arr_user_fields[counter]["id"] + "\" " + selected + ">" + arr_user_fields[counter]["data"] + "</option>";
        	}
        	htmlCboFields += "</select>";
        	newCellField.innerHTML = htmlCboFields;

        	$(newTableRow).fadeIn("slow");
        	fieldNumberIntern' . $conf . '++;
    	}

    	function createColumnsArray' . $conf . '()
    	{
        	var default_fields = new Array(); ';

        for ($number = 0; $number < count($confFields); $number ++) {
            foreach ($fieldSelectionList as $key => $data) {
                if ($confFields[$number] == $data['id']) {
                    $javascriptCode .= '
                			default_fields[' . $number . '] 		  = new Object();
                			default_fields[' . $number . ']["id"]   = "' . $fieldSelectionList[$key]["id"] . '";
                			default_fields[' . $number . ']["data"] = "' . $fieldSelectionList[$key]["data"] . '";
                		';
                }
            }
        }
        $javascriptCode .= '
        	return default_fields;
    	}
    ';
    }

    $javascriptCode .= '
    function createProfileFieldsArray()
    {
        var user_fields = new Array(); ';

    // create an array for all columns with the necessary data
    for ($i = 1; $i < count($fieldSelectionList) + 1; $i ++) {
        $javascriptCode .= '
                user_fields[' . $i . '] 				= new Object();
                user_fields[' . $i . ']["id"]   		= "' . $fieldSelectionList[$i]['id'] . '";
                user_fields[' . $i . ']["cat_name"] 	= "' . $fieldSelectionList[$i]['cat_name'] . '";
                user_fields[' . $i . ']["data"]   	= "' . $fieldSelectionList[$i]['data'] . '";
            ';
    }

    $javascriptCode .= '
        return user_fields;
    }
';

    $page->addJavascript($javascriptCode);

    $javascriptCode = '$(document).ready(function() { ';
    foreach ($pPreferences->config['columnconfig'] as $conf => $confFields) {
        $javascriptCode .= '
    		for(var counter = 0; counter < ' . count($confFields) . '; counter++) {
        		addColumn' . $conf . '();
    		}
    	';
    }
    $javascriptCode .= '});
';

    $page->addJavascript($javascriptCode);

    $beitrag = analyse_mem();
    // Stand des Beitragseinzugs als drei Kennzahlen (Betrag und Anzahl Mitglieder)
    $currency = $gSettingsManager->getString('system_currency');
    $summaryTile = static function (string $label, float $amount, int $count, string $class) use ($currency): string {
        return '<div class="col-sm-4 mb-2"><div class="border rounded p-2 h-100">'
            . '<div class="text-muted small">' . $label . '</div>'
            . '<div class="fs-5 fw-bold text-nowrap ' . $class . '">' . number_format($amount, 2, ',', '.') . ' ' . $currency . '</div>'
            . '<div class="text-muted small">' . $count . ' Mitglieder</div></div></div>';
    };
    $totalAmount = (float) ($beitrag['BEITRAG_kto'] + $beitrag['BEITRAG_rech']);
    $totalCount = (int) ($beitrag['BEITRAG_kto_anzahl'] + $beitrag['BEITRAG_rech_anzahl']);
    $paidAmount = (float) ($beitrag['BEZAHLT_kto'] + $beitrag['BEZAHLT_rech']);
    $paidCount = (int) ($beitrag['BEZAHLT_kto_anzahl'] + $beitrag['BEZAHLT_rech_anzahl']);
    $summaryHtml = '<div class="row">'
        . $summaryTile($gL10n->get('PLG_MEMBERSHIPFEE_TOTAL'), $totalAmount, $totalCount, '')
        . $summaryTile($gL10n->get('PLG_MEMBERSHIPFEE_ALREADY_PAID'), $paidAmount, $paidCount, 'text-success')
        . $summaryTile($gL10n->get('PLG_MEMBERSHIPFEE_PENDING'), $totalAmount - $paidAmount, $totalCount - $paidCount, ($totalAmount - $paidAmount) > 0.004 ? 'text-danger' : '')
        . '</div>';

    $showGermanDocumentation = in_array($gSettingsManager->getString('system_language'), array('de', 'de-DE'), true)
        && is_file(__DIR__ . '/../docs/documentation-de.html');

    if (count($rols) > 0) {

        // TAB: FEES (Panels in der Reihenfolge des Ablaufs eines Beitragseinzugs, nummeriert)
        $page->addHtml(openMenueTab('fees', 'accordion_fees'));
        $workflowStep = 0;

        // PANEL: TESTS

        // Panel Tests nur anzeigen, wenn mindestens ein Einzeltest aktiviert ist
        if (in_array(1, $pPreferences->config['tests_enable'])) {
            $formTests = new HtmlForm('tests_form', '', $page);
            $formTests->addButton('btn_tests', $gL10n->get('PLG_MEMBERSHIPFEE_TESTS'), array(
                'icon' => 'bi-heart-pulse',
                'link' => 'tests.php',
                'class' => 'btn-primary offset-sm-3'
            ));
            $formTests->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_TESTS_DESC'));

            $page->addHtml(getMenuePanel('fees', 'tests', 'accordion_fees', (++$workflowStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_TESTS'), 'bi bi-heart-pulse', $formTests->show()));
        }

        // PANEL: RECALCULATION

        unset($_SESSION['pMembershipFee']['recalculation_user']);

        // die Rollenauswahl steht im Filter der Seite selbst (siehe mf_role_filter_add())
        $formRecalculation = new HtmlForm('recalculation_form', '', $page);
        $formRecalculation->addButton('btn_recalculation', $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION'), array(
            'icon' => 'bi-calculator',
            'link' => 'recalculation.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formRecalculation->addCustomContent('', '<strong>' . $gL10n->get('SYS_NOTE') . ':</strong> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_BOOKINGS_NOTE'));

        $page->addHtml(getMenuePanel('fees', 'recalculation', 'accordion_fees', (++$workflowStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION'), 'bi bi-calculator', $formRecalculation->show()));

        // PANEL: INDIVIDUAL_CONTRIBUTIONS

        if ($pPreferences->config['individual_contributions']['access_to_module']) {
            $formIndividualContributions = new HtmlForm('individual_contributions_form', '', $page);

            $formIndividualContributions->addButton('btn_individualcontributions', $gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS'), array(
                'icon' => 'bi-house-gear',
                'link' => 'individualcontributions.php',
                'class' => 'btn-primary offset-sm-3'
            ));
            $formIndividualContributions->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS_DESC'));

            $page->addHtml(getMenuePanel('fees', 'individualcontributions', 'accordion_fees', $gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS'), 'bi bi-house-gear', $formIndividualContributions->show()));
        }

        // PANEL: DUEDATES (Faelligkeitsdatum)

        $formDuedates = new HtmlForm('duedates_form', '', $page);
        $formDuedates->addButton('btn_duedates', $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'), array(
            'icon' => 'bi-calendar-check',
            'link' => 'duedates.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formDuedates->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE_EDIT_DESC'));
        $page->addHtml(getMenuePanel('fees', 'duedates', 'accordion_fees', (++$workflowStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'), 'bi bi-calendar-check', $formDuedates->show()));


        // PANEL: PRE_NOTIFICATION (Vorabinformation)

        $formPreNotification = new HtmlForm('pre_notification_form', '', $page);
        if (! $directdebittype) {
            $formPreNotification->addCustomContent('', '<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_NO_DUEDATES_EXIST') . '</div>');
        } else {
            $formPreNotification->addButton('btn_pre_notification', $gL10n->get('PLG_MEMBERSHIPFEE_PRE_NOTIFICATION'), array(
                'icon' => 'bi-filetype-xlsx',
                'link' => 'pre_notification.php',
                'class' => 'btn-primary offset-sm-3'
            ));
            $formPreNotification->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_PRE_NOTIFICATION_DESC'));
        }
        $page->addHtml(getMenuePanel('fees', 'prenotification', 'accordion_fees', (++$workflowStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_PRE_NOTIFICATION'), 'bi bi-envelope-paper', $formPreNotification->show()));

        // PANEL: SEPA (SEPA-Datei erzeugen)

        $sepaStep = ++$workflowStep;
        // SEPA-Dateien: je Faelligkeitsdatum eine Datei (bzw. je Faelligkeitsdatum und Sequenztyp), siehe sepa_create.php
        $formSepa = new HtmlForm('sepa_export_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/sepa_create.php', $page);

        if (! $directdebittype) {
            $html = '<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_NO_DUEDATES_EXIST') . '</div>';
            $formSepa->addCustomContent('', $html);
        } else {
            $htmlTable = '
        <table class="table table-condensed">
            <thead>
                <tr>
                    <th style="text-align: center;font-weight:bold;">' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE') . '</th>
                    <th style="text-align: center;font-weight:bold;">FRST</th>
                    <th style="text-align: center;font-weight:bold;">RCUR</th>
                    <th style="text-align: center;font-weight:bold;">FNAL</th>
                    <th style="text-align: center;font-weight:bold;">OOFF</th>
                </tr>
            </thead>
            <tbody>';
            ksort($duedates);
            foreach ($duedates as $duedate => $duedatedata) {
                $datumtemp = \DateTime::createFromFormat('Y-m-d', $duedate);
                $htmlTable .= '
                <tr>
                    <td style="text-align: center;">' . $datumtemp->format($gSettingsManager->getString('system_date')) . '</td>';
                foreach (array('FRST', 'RCUR', 'FNAL', 'OOFF') as $sequenceType) {
                    $htmlTable .= '<td style="text-align: center;">' . ($duedatedata[$sequenceType] > 0 ? $duedatedata[$sequenceType] : '<span class="text-muted">–</span>') . '</td>';
                }
                $htmlTable .= '</tr>';
            }
            $htmlTable .= '
            </tbody>
        </table>';

            $formSepa->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'), $htmlTable);
            $formSepa->addCheckbox('combine_sequence_types', $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_COMBINE'), (bool) ($pPreferences->config['SEPA']['combine_sequence_types'] ?? 1), array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_SEPA_COMBINE_DESC'
            ));
            $formSepa->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_FILES_INFO'));

            $formSepa->addSubmitButton('btn_create_export_file', $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_CREATE_FILES'), array(
                'icon' => 'bi-filetype-xml',
                'class' => 'btn-primary offset-sm-3'
            ));
            $html = '<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_EXPORT_INFO') . '</div>';
            $formSepa->addStaticControl('', '', $html);
        }

        $page->addHtml(getMenuePanel('fees', 'sepa', 'accordion_fees', $sepaStep . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_CREATE_FILE'), 'bi bi-filetype-xml', $formSepa->show()));

        // PANEL: BILL (Rechnungen erstellen, fuer Mitglieder ohne Lastschrift)
        $formBillExport = new HtmlForm('billexport_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bill.php', $page);
        $formBillExport->addSubmitButton('btn_bill', $gL10n->get('PLG_MEMBERSHIPFEE_BILL_EDIT'), array(
            'icon' => 'bi-file-earmark-spreadsheet',
            'class' => 'offset-sm-3'
        ));
        $formBillExport->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_BILL_EDIT_DESC'));

        $page->addHtml(getMenuePanel('fees', 'bill', 'accordion_fees', $sepaStep . 'b. ' . $gL10n->get('PLG_MEMBERSHIPFEE_BILL_CREATE'), 'bi bi-file-earmark-spreadsheet', $formBillExport->show()));


        // PANEL: RUNS (Einzugsuebersicht)

        $formRuns = new HtmlForm('runs_form', '', $page);
        $formRuns->addButton('btn_runs', $gL10n->get('PLG_MEMBERSHIPFEE_RUNS'), array(
            'icon' => 'bi-bank',
            'link' => 'runs.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formRuns->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_RUNS_DESC'));

        $page->addHtml(getMenuePanel('fees', 'runs', 'accordion_fees', (++$workflowStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_RUNS'), 'bi bi-bank', $formRuns->show()));

        // PANEL: PAYMENTS

        $formPayments = new HtmlForm('payments_form', '', $page);
        $formPayments->addButton('btn_payments', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PAYMENTS_EDIT'), array(
            'icon' => 'bi-cash-coin',
            'link' => 'payments.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formPayments->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PAYMENTS_DESC'));

        $page->addHtml(getMenuePanel('fees', 'payments', 'accordion_fees', $workflowStep . 'b. ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PAYMENTS'), 'bi bi-cash-coin', $formPayments->show()));

        // PANEL: ANALYSIS

        $formAnalysis = new HtmlForm('analysis_form', '', $page);

        $formAnalysis->addButton('btn_analysis', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_ANALYSIS'), array(
            'icon' => 'bi-bar-chart',
            'link' => 'analysis.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formAnalysis->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_ANALYSIS_DESC'));

        $page->addHtml(getMenuePanel('fees', 'analysis', 'accordion_fees', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_ANALYSIS'), 'bi bi-bar-chart', $formAnalysis->show()));

        // PANEL: ROLEOVERVIEW

        $formRoleOverview = new HtmlForm('roleoverview_form', '', $page);
        $formRoleOverview->addButton('btn_roleoverview', $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_OVERVIEW'), array(
            'icon' => 'bi-list-columns-reverse',
            'link' => 'roleoverview.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formRoleOverview->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_OVERVIEW_DESC'));

        $page->addHtml(getMenuePanel('fees', 'roleoverview', 'accordion_fees', $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_OVERVIEW'), 'bi bi-list-columns-reverse', $formRoleOverview->show()));

        // PANEL: BOOKING_OVERVIEW (Buchungshistorie aller Mitglieder)

        $formBookingOverview = new HtmlForm('booking_overview_form', '', $page);
        $formBookingOverview->addButton('btn_booking_overview', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_OVERVIEW'), array(
            'icon' => 'bi-clock-history',
            'link' => 'booking_overview.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formBookingOverview->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_OVERVIEW_DESC'));

        $page->addHtml(getMenuePanel('fees', 'bookingoverview', 'accordion_fees', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_OVERVIEW'), 'bi bi-clock-history', $formBookingOverview->show()));

        $page->addHtml(closeMenueTab());

        // TAB: WORKSERVICE (Arbeitsdienst; Schritte nummeriert wie beim Beitragseinzug)
        $page->addHtml(openMenueTab('workservice', 'accordion_workservice'));
        $workStep = 0;

        // PANEL: WORK_TARGETS (Sollstunden abgleichen)
        $formWorkTargets = new HtmlForm('worktargets_form', '', $page);
        $formWorkTargets->addButton('btn_work_targets', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS'), array(
            'icon' => 'bi-bullseye',
            'link' => 'work_targets.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formWorkTargets->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_PANEL_DESC'));
        $page->addHtml(getMenuePanel('workservice', 'worktargets', 'accordion_workservice', (++$workStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS'), 'bi bi-bullseye', $formWorkTargets->show()));

        // PANEL: WORK_INPUT (Stunden erfassen)
        $formWorkInput = new HtmlForm('workinput_form', '', $page);
        $formWorkInput->addButton('btn_work_input', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_INPUT'), array(
            'icon' => 'bi-pencil-square',
            'link' => 'work_input.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formWorkInput->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_INPUT_DESC'));
        $page->addHtml(getMenuePanel('workservice', 'workinput', 'accordion_workservice', (++$workStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_INPUT'), 'bi bi-pencil-square', $formWorkInput->show()));

        // Einzug im Buchungskreis Arbeitsdienst: dieselben Seiten wie beim Mitgliedsbeitrag mit collection=ad
        $workCollection = array('collection' => \Plugins\MembershipFee\classes\Bookings\CollectionScope::WORK);
        $workScope = \Plugins\MembershipFee\classes\Bookings\CollectionScope::work((int) $gCurrentOrgId);
        $workDuedates = mf_sepa_duedate_overview($workScope);
        $workPanel = static function (string $id, string $title, string $icon, string $page2, string $label, string $desc) use ($page, $gL10n, $workCollection, &$workStep): string {
            $form = new HtmlForm($id . '_form', '', $page);
            $form->addButton('btn_' . $id, $label, array(
                'icon' => $icon,
                'link' => SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/' . $page2, $workCollection),
                'class' => 'btn-primary offset-sm-3'
            ));
            $form->addCustomContent('', $desc);
            return getMenuePanel('workservice', $id, 'accordion_workservice', $title, 'bi ' . $icon, $form->show());
        };

        // PANEL: WORK_BILLING (Abrechnen)
        $page->addHtml($workPanel('workbilling', (++$workStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING'), 'bi-receipt', 'work_billing.php', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING'), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING_PANEL_DESC')));
        // PANEL: Faelligkeitsdatum und Vorabinformation im Arbeitsdienst
        $page->addHtml($workPanel('workduedates', (++$workStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'), 'bi-calendar-check', 'duedates.php', $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_DUEDATE_DESC')));
        $page->addHtml($workPanel('workprenotification', (++$workStep) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_PRE_NOTIFICATION'), 'bi-envelope-paper', 'pre_notification.php', $gL10n->get('PLG_MEMBERSHIPFEE_PRE_NOTIFICATION'), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_PRE_NOTIFICATION_DESC')));

        // PANEL: SEPA-Datei Arbeitsdienst
        $workSepaStep = ++$workStep;
        $formWorkSepa = new HtmlForm('worksepa_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/sepa_create.php', $page);
        if (count($workDuedates) === 0) {
            $formWorkSepa->addCustomContent('', '<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_NO_DUEDATES_EXIST') . '</div>');
        } else {
            $htmlTable = '<table class="table table-condensed"><thead><tr><th style="text-align: center;">' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE') . '</th>';
            foreach (array('FRST', 'RCUR', 'FNAL', 'OOFF') as $sequenceType) {
                $htmlTable .= '<th style="text-align: center;">' . $sequenceType . '</th>';
            }
            $htmlTable .= '</tr></thead><tbody>';
            foreach ($workDuedates as $duedate => $counts) {
                $htmlTable .= '<tr><td style="text-align: center;">' . mf_format_date($duedate) . '</td>';
                foreach ($counts as $count) {
                    $htmlTable .= '<td style="text-align: center;">' . ($count > 0 ? $count : '<span class="text-muted">–</span>') . '</td>';
                }
                $htmlTable .= '</tr>';
            }
            $htmlTable .= '</tbody></table>';
            $formWorkSepa->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'), $htmlTable);
            $formWorkSepa->addCustomContent('', '<input type="hidden" name="collection" value="' . \Plugins\MembershipFee\classes\Bookings\CollectionScope::WORK . '" />');
            $formWorkSepa->addCheckbox('combine_sequence_types', $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_COMBINE'), (bool) ($pPreferences->config['SEPA']['combine_sequence_types'] ?? 1), array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_SEPA_COMBINE_DESC'
            ));
            $formWorkSepa->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_SEPA_INFO'));
            $formWorkSepa->addSubmitButton('btn_create_work_export_file', $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_CREATE_FILES'), array(
                'icon' => 'bi-filetype-xml',
                'class' => 'btn-primary offset-sm-3'
            ));
        }
        $page->addHtml(getMenuePanel('workservice', 'worksepa', 'accordion_workservice', $workSepaStep . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_CREATE_FILE'), 'bi bi-filetype-xml', $formWorkSepa->show()));
        $page->addHtml($workPanel('workbill', $workSepaStep . 'b. ' . $gL10n->get('PLG_MEMBERSHIPFEE_BILL_CREATE'), 'bi-file-earmark-spreadsheet', 'bill.php', $gL10n->get('PLG_MEMBERSHIPFEE_BILL_EDIT'), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILL_DESC')));

        // PANEL: Einzugsuebersicht (gemeinsam) und Zahlungen ohne Lastschrift
        $workRunsStep = ++$workStep;
        $formWorkRuns = new HtmlForm('workruns_form', '', $page);
        $formWorkRuns->addButton('btn_work_runs', $gL10n->get('PLG_MEMBERSHIPFEE_RUNS'), array(
            'icon' => 'bi-bank',
            'link' => 'runs.php',
            'class' => 'btn-primary offset-sm-3'
        ));
        $formWorkRuns->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_RUNS_DESC'));
        $page->addHtml(getMenuePanel('workservice', 'workruns', 'accordion_workservice', $workRunsStep . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_RUNS'), 'bi bi-bank', $formWorkRuns->show()));
        $page->addHtml($workPanel('workpayments', $workRunsStep . 'b. ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PAYMENTS'), 'bi-cash-coin', 'payments.php', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PAYMENTS'), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_PAYMENTS_DESC')));

        $page->addHtml(closeMenueTab());

        // TAB: MANDATEMANAGEMENT
        $page->addHtml(openMenueTab('mandatemanagement', 'accordion_mandatemanagement'));

        // PANEL: CREATEMANDATEID

        unset($_SESSION['pMembershipFee']['createmandateid_user']);

        $formCreateMandateID = new HtmlForm('createmandateid_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/create_mandate_id.php', $page);

        $formCreateMandateID->addSelectBoxFromSql('createmandateid_roleselection', $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_SELECTION'), $gDb, $selectBoxEntriesAlleRollen, array(
            'defaultValue' => (isset($_SESSION['pMembershipFee']['createmandateid_rol_sel']) ? $_SESSION['pMembershipFee']['createmandateid_rol_sel'] : ''),
            'showContextDependentFirstEntry' => false,
            'helpTextId' => 'PLG_MEMBERSHIPFEE_CREATE_MANDATE_ID_DESC',
            'multiselect' => true
        ));
        $formCreateMandateID->addSubmitButton('btn_createmandateid', $gL10n->get('PLG_MEMBERSHIPFEE_CREATE_MANDATE_ID'), array(
            'icon' => 'bi-list-ol',
            'class' => 'offset-sm-3'
        ));

        $page->addHtml(getMenuePanel('mandatemanagement', 'createmandateid', 'accordion_mandatemanagement', $gL10n->get('PLG_MEMBERSHIPFEE_CREATE_MANDATE_ID'), 'bi bi-list-ol', $formCreateMandateID->show()));

        // PANEL: MANDATES

        $formMandates = new HtmlForm('mandates_form', ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/mandates.php', $page);

        $formMandates->addSubmitButton('btn_mandates', $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_EDIT'), array(
            'icon' => 'bi-pen',
            'class' => 'offset-sm-3'
        ));
        $formMandates->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_EDIT_DESC'));

        $page->addHtml(getMenuePanel('mandatemanagement', 'mandates', 'accordion_mandatemanagement', $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_EDIT'), 'bi bi-pen', $formMandates->show()));

        $page->addHtml(closeMenueTab());


        if (isUserAuthorizedForPreferences()) {
            // TAB: PREFERENCES
            $page->addHtml(openMenueTab('preferences', 'accordion_preferences'));

            // PANEL: CONTRIBUTION_SETTINGS

            $formContributionSettings = new HtmlForm('contributionsettings_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'contributionsettings'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            $formContributionSettings->addInput('beitrag_prefix', $gL10n->get('PLG_MEMBERSHIPFEE_PREFIX'), $pPreferences->config['Beitrag']['beitrag_prefix'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTRIBUTION_PREFIX_DESC'
            ));
            $formContributionSettings->addCheckbox('beitrag_abrunden', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_ROUNDDOWN'), $pPreferences->config['Beitrag']['beitrag_abrunden'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTRIBUTION_ROUNDDOWN_DESC'
            ));
            $formContributionSettings->addInput('beitrag_mindestbetrag', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_MINCALC') . ' ' . $gSettingsManager->getString('system_currency'), $pPreferences->config['Beitrag']['beitrag_mindestbetrag'], array(
                'type' => 'number',
                'minNumber' => 0,
                'maxNumber' => 999,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTRIBUTION_MINCALC_DESC'
            ));
            $formContributionSettings->addInput('beitrag_jahr', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_YEAR'), (int) $pPreferences->config['Beitrag']['beitrag_jahr'], array(
                'type' => 'number',
                'minNumber' => 0,
                'maxNumber' => 9999,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTRIBUTION_YEAR_DESC'
            ));
            $formContributionSettings->addCheckbox('beitrag_textmitnam', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_TEXT_MEMNAMES'), $pPreferences->config['Beitrag']['beitrag_textmitnam'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTRIBUTION_TEXT_MEMNAMES_DESC'
            ));
            $selectBoxEntries = array(
                '#' => ' &nbsp ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_BLANK'),
                '.' => '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_DOT'),
                ',' => ', ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_COMMA'),
                '-' => '- ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_HYPHEN'),
                '/' => '/ ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_SLASH'),
                '+' => '+ ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PLUS'),
                '*' => '* ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_TIMES') . '(*)',
                '%' => '% ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PERCENT') . '(*)'
            );
            $formContributionSettings->addSelectBox('beitrag_text_token', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_TEXT_TOKEN'), $selectBoxEntries, array(
                'defaultValue' => $pPreferences->config['Beitrag']['beitrag_text_token'],
                'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTRIBUTION_TEXT_TOKEN_DESC',
                'showContextDependentFirstEntry' => false
            ));
            $formContributionSettings->addInput('beitrag_role_separator', $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_SEPARATOR'), $pPreferences->config['Beitrag']['beitrag_role_separator'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_ROLE_SEPARATOR_DESC',
                'helpTextIdLabel' => 'PLG_MEMBERSHIPFEE_ROLE_SEPARATOR_DESC2'
            ));
            $formContributionSettings->addCustomContent('', '<small>' . $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_SEPARATOR_DESC2') . '</small>');
            $formContributionSettings->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'contributionsettings', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_SETTINGS'), 'bi bi-database-gear', $formContributionSettings->show()));

            // PANEL: ADVANCED_ROLE_EDITING

            $formAdvancedRoleEditing = new HtmlForm('advancedroleediting_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'advancedroleediting'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            $formAdvancedRoleEditing->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_ADVANCED_ROLE_EDITING_DESC'));

            $formAdvancedRoleEditing->addDescription('<div style="width:100%; height:450px; overflow:auto; border:20px;">');

            foreach ($alt_fix_rollen as $key => $data) {
                $formAdvancedRoleEditing->openGroupBox('advancedroleediting_group', $data['rolle']);

                $formAdvancedRoleEditing->addInput('rol_cost' . $key, $gL10n->get('SYS_CONTRIBUTION') . ' ' . $gSettingsManager->getString('system_currency'), $data['rol_cost'], array(
                    'type' => 'number',
                    'minNumber' => - 99999,
                    'maxNumber' => 99999,
                    'step' => 0.01
                ));
                $formAdvancedRoleEditing->addSelectBox('rol_cost_period' . $key, $gL10n->get('SYS_CONTRIBUTION_PERIOD'), Role::getCostPeriods(), array(
                    'defaultValue' => $data['rol_cost_period']
                ));
                $formAdvancedRoleEditing->addInput('rol_description' . $key, $gL10n->get('SYS_DESCRIPTION'), $data['rol_description']);

                $formAdvancedRoleEditing->closeGroupBox();
            }

            $formAdvancedRoleEditing->addDescription('</div>');
            $formAdvancedRoleEditing->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'advancedroleediting', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_ADVANCED_ROLE_EDITING'), 'bi bi-person-gear', $formAdvancedRoleEditing->show()));

            // PANEL: INDIVIDUAL_CONTRIBUTIONS

            $formIndividualContributionsSetup = new HtmlForm('individual_contributions_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'individualcontributions'
            )), $page, array(
                'class' => 'form-preferences'
            ));

            $selectBoxEntries = array(
                '0' => $gL10n->get('SYS_DISABLED'),
                '1' => $gL10n->get('SYS_ENABLED')
            );
            $formIndividualContributionsSetup->addSelectBox('enable_individual_contributions', $gL10n->get('PLG_MEMBERSHIPFEE_ACCESS_TO_MODULE_INDIVIDUAL_CONTRIBUTIONS'), $selectBoxEntries, array(
                'defaultValue' => $pPreferences->config['individual_contributions']['access_to_module'],
                'showContextDependentFirstEntry' => false,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_ACCESS_TO_MODULE_INDIVIDUAL_CONTRIBUTIONS_DESC'
            ));

            $formIndividualContributionsSetup->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS_DESC'));
            $formIndividualContributionsSetup->addLine();

            $formIndividualContributionsSetup->addDescription('<div style="width:100%; height:' . ($num_individualcontributions < 2 ? 500 : 650) . 'px; overflow:auto; border:20px;">');
            for ($conf = 0; $conf < $num_individualcontributions; $conf ++) {
                $formIndividualContributionsSetup->openGroupBox('individualcontributions_group', ($conf + 1) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONFIGURATION'));
                $formIndividualContributionsSetup->addInput('individual_contributions_desc' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_DESCRIPTION'), $pPreferences->config['individual_contributions']['desc'][$conf], array(
                    'property' => HtmlForm::FIELD_REQUIRED,
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_DESCRIPTION_DESC'
                ));
                $formIndividualContributionsSetup->addInput('individual_contributions_short_desc' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_SHORT_DESCRIPTION'), $pPreferences->config['individual_contributions']['short_desc'][$conf], array(
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_SHORT_DESCRIPTION_DESC'
                ));

                $sql = 'SELECT rol.rol_id, rol.rol_name, cat.cat_name
                      FROM ' . TBL_CATEGORIES . ' as cat, ' . TBL_ROLES . ' as rol
                     WHERE cat.cat_id = rol.rol_cat_id
                       AND ( cat.cat_org_id = ' . $gCurrentOrgId . '
                        OR cat.cat_org_id IS NULL )
                  ORDER BY cat.cat_name DESC';
                $formIndividualContributionsSetup->addSelectBoxFromSql('individual_contributions_role' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_ROLE'), $gDb, $sql, array(
                    'defaultValue' => $pPreferences->config['individual_contributions']['role'][$conf],
                    'multiselect' => false,
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_ROLE_DESC'
                ));

                $formIndividualContributionsSetup->addInput('individual_contributions_amount' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_AMOUNT'), $pPreferences->config['individual_contributions']['amount'][$conf], array(
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_AMOUNT_DESC'
                ));

                $fieldSelectionList2 = array();

                foreach ($gProfileFields->getProfileFields() as $field) {
                    if ($field->getValue('usf_hidden') == 0 || $gCurrentUser->isAdministratorUsers()) {
                        $fieldSelectionList2[] = array(
                            $field->getValue('usf_id'),
                            addslashes($field->getValue('usf_name')),
                            $field->getValue('cat_name')
                        );
                    }
                }

                $formIndividualContributionsSetup->addSelectBox('individual_contributions_profilefield' . $conf, $gL10n->get('SYS_PROFILE_FIELD'), $fieldSelectionList2, array(
                    'firstEntry' => '',
                    'defaultValue' => $pPreferences->config['individual_contributions']['profilefield'][$conf],
                    'showContextDependentFirstEntry' => true,
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_PROFILE_FIELD_DESC'
                ));

                if ($num_individualcontributions != 1) {
                    $html = '<a id="add_config" class="icon-text-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                        'choice' => 'individualcontributions',
                        'conf' => $conf
                    )) . '">
                            <i class="bi bi-trash"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_DELETE_CONFIG') . '
                        </a>';
                    $formIndividualContributionsSetup->addCustomContent('', $html);
                }
                $formIndividualContributionsSetup->closeGroupBox();
            }
            $formIndividualContributionsSetup->addDescription('</div>');
            $html = '<a id="add_config" class="icon-text-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                'choice' => 'individualcontributions',
                'conf' => - 1
            )) . '">
                    <i class="bi bi-copy"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_ADD_ANOTHER_CONFIG') . '
                </a>';
            $htmlDesc = '<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_NOT_SAVED_SETTINGS_LOST') . '</div>';
            $formIndividualContributionsSetup->addCustomContent('', $html, array(
                'helpTextId' => $htmlDesc
            ));
            $formIndividualContributionsSetup->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'individualcontributions', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS'), 'bi bi-house-gear', $formIndividualContributionsSetup->show()));

            // PANEL: CONTRIBUTION_GROUPS (Beitragsgruppen mit Differenzberechnung)

            $formContributionGroups = new HtmlForm('contributiongroups_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'contributiongroups'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            $formContributionGroups->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUPS_DESC'));
            $formContributionGroups->addLine();

            for ($conf = 0; $conf < $num_contributiongroups; $conf ++) {
                $formContributionGroups->openGroupBox('contributiongroups_group' . $conf, ($conf + 1) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUP'));
                $formContributionGroups->addInput('group_name' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUP_NAME'), trim((string) $pPreferences->config['Beitragsgruppen']['name'][$conf]), array(
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUP_NAME_DESC'
                ));
                $groupRoles = array_values(array_filter(array_map('intval', explode(',', (string) ($pPreferences->config['Beitragsgruppen']['roles'][$conf] ?? '')))));
                $formContributionGroups->addSelectBox('group_roles' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUP_ROLES'), $selectBoxEntriesBeitragsrollen, array(
                    'defaultValue' => $groupRoles,
                    'showContextDependentFirstEntry' => false,
                    'multiselect' => true,
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUP_ROLES_DESC'
                ));
                $html = '<a class="icon-text-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                    'choice' => 'contributiongroups',
                    'conf' => $conf
                )) . '"><i class="bi bi-trash"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_DELETE_CONFIG') . '</a>';
                $formContributionGroups->addCustomContent('', $html);
                $formContributionGroups->closeGroupBox();
            }
            $html = '<a class="icon-text-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                'choice' => 'contributiongroups',
                'conf' => - 1
            )) . '"><i class="bi bi-plus-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUP_ADD') . '</a>';
            $htmlDesc = '<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_NOT_SAVED_SETTINGS_LOST') . '</div>';
            $formContributionGroups->addCustomContent('', $html, array(
                'helpTextId' => $htmlDesc
            ));
            $formContributionGroups->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'contributiongroups', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUPS'), 'bi bi-diagram-3', $formContributionGroups->show()));

            // PANEL: WORK_SERVICE (Arbeitsdienst: Sollstunden je Rolle, Befreiungen, Altersgrenze, Stundensatz)

            $workRules = \Plugins\MembershipFee\classes\Work\WorkRules::fromConfig($pPreferences->config);
            $sqlAllRoles = 'SELECT rol.rol_id, rol.rol_name, cat.cat_name
                              FROM ' . TBL_ROLES . ' AS rol
                        INNER JOIN ' . TBL_CATEGORIES . ' AS cat
                                ON cat.cat_id = rol.rol_cat_id
                             WHERE rol.rol_valid = true
                               AND (  cat.cat_org_id = ' . (int) $gCurrentOrgId . '
                                   OR cat.cat_org_id IS NULL )
                          ORDER BY cat.cat_sequence, rol.rol_name';
            $formWorkService = new HtmlForm('workservice_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'workservice'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            $formWorkService->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_WORK_SETTINGS_DESC'));
            $formWorkService->addLine();

            for ($conf = 0; $conf < $num_workservice; $conf ++) {
                $formWorkService->openGroupBox('workservice_group' . $conf, ($conf + 1) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGET_ROLE'));
                $formWorkService->addSelectBoxFromSql('work_role' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_ROLE'), $gDb, $sqlAllRoles, array(
                    'defaultValue' => (int) $pPreferences->config['Arbeitsdienst']['soll_rollen'][$conf],
                    'multiselect' => false
                ));
                $formWorkService->addInput('work_hours' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGET_HOURS'), trim((string) ($pPreferences->config['Arbeitsdienst']['soll_stunden'][$conf] ?? '')), array(
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_TARGET_HOURS_DESC'
                ));
                $html = '<a class="icon-text-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                    'choice' => 'workservice',
                    'conf' => $conf
                )) . '"><i class="bi bi-trash"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_DELETE_CONFIG') . '</a>';
                $formWorkService->addCustomContent('', $html);
                $formWorkService->closeGroupBox();
            }
            $html = '<a class="icon-text-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                'choice' => 'workservice',
                'conf' => - 1
            )) . '"><i class="bi bi-plus-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGET_ROLE_ADD') . '</a>';
            $htmlDesc = '<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_NOT_SAVED_SETTINGS_LOST') . '</div>';
            $formWorkService->addCustomContent('', $html, array(
                'helpTextId' => $htmlDesc
            ));
            $formWorkService->addLine();
            $formWorkService->addSelectBoxFromSql('work_exempt_roles', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_EXEMPT_ROLES'), $gDb, $sqlAllRoles, array(
                'defaultValue' => $workRules->exemptRoleIds(),
                'multiselect' => true,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_EXEMPT_ROLES_DESC'
            ));
            $formWorkService->addSelectBoxFromSql('work_exempt_categories', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_EXEMPT_CATEGORIES'), $gDb, 'SELECT cat_id, cat_name
                  FROM ' . TBL_CATEGORIES . '
                 WHERE cat_type = \'ROL\'
                   AND (  cat_org_id = ' . (int) $gCurrentOrgId . '
                       OR cat_org_id IS NULL )
              ORDER BY cat_sequence', array(
                'defaultValue' => $workRules->exemptCategoryIds(),
                'multiselect' => true,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_EXEMPT_CATEGORIES_DESC'
            ));
            $formWorkService->addSelectBoxFromSql('work_included_roles', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_INCLUDED_ROLES'), $gDb, $sqlAllRoles, array(
                'defaultValue' => $workRules->includedRoleIds(),
                'multiselect' => true,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_INCLUDED_ROLES_DESC'
            ));
            $formWorkService->addInput('work_max_age', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_MAX_AGE'), $workRules->maxAge() !== null ? (string) $workRules->maxAge() : '', array(
                'type' => 'number',
                'minNumber' => 1,
                'maxNumber' => 120,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_MAX_AGE_DESC'
            ));
            $formWorkService->addInput('work_rate', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_RATE'), number_format($workRules->rate(), 2, ',', ''), array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_RATE_DESC'
            ));
            $formWorkService->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            // Menuepunkt "Arbeitsdienst erfassen" (eigenes Recht ueber "Sichtbar für")
            $workMenuId = mf_work_menu_id();
            $formWorkMenu = new HtmlForm('workmenu_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'workmenu'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            if ($workMenuId > 0) {
                $formWorkMenu->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_WORK_MENU'), '<i class="bi bi-check-circle text-success"></i> '
                    . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_MENU_EXISTS')
                    . ' <a href="' . ADMIDIO_URL . FOLDER_MODULES . '/menu.php">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_MENU_EDIT') . '</a>');
            } else {
                $formWorkMenu->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_WORK_MENU'), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_MENU_DESC'));
                $formWorkMenu->addSubmitButton('btn_work_menu', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_MENU_CREATE'), array(
                    'icon' => 'bi-plus-circle',
                    'class' => ' offset-sm-3'
                ));
            }

            $page->addHtml(getMenuePanel('preferences', 'workservice', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_SETTINGS'), 'bi bi-tools', $formWorkService->show() . '<hr />' . $formWorkMenu->show()));

            // PANEL: MANUAL_TEMPLATES (Vorlagen fuer manuelle Positionen)

            $formManualTemplates = new HtmlForm('manualtemplates_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'manualtemplates'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            $formManualTemplates->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_MANUAL_TEMPLATES_DESC'));
            $formManualTemplates->addLine();

            for ($conf = 0; $conf < $num_manualtemplates; $conf ++) {
                $formManualTemplates->openGroupBox('manualtemplates_group' . $conf, ($conf + 1) . '. ' . $gL10n->get('PLG_MEMBERSHIPFEE_MANUAL_TEMPLATE'));
                $formManualTemplates->addInput('template_name' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_LABEL'), trim((string) $pPreferences->config['Positionsvorlagen']['name'][$conf]));
                $formManualTemplates->addInput('template_amount' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_AMOUNT') . ' ' . $gSettingsManager->getString('system_currency'), trim((string) ($pPreferences->config['Positionsvorlagen']['amount'][$conf] ?? '')));
                $formManualTemplates->addInput('template_text' . $conf, $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_TEXT'), trim((string) ($pPreferences->config['Positionsvorlagen']['text'][$conf] ?? '')), array(
                    'maxLength' => 140
                ));
                $html = '<a class="icon-text-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                    'choice' => 'manualtemplates',
                    'conf' => $conf
                )) . '"><i class="bi bi-trash"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_DELETE_CONFIG') . '</a>';
                $formManualTemplates->addCustomContent('', $html);
                $formManualTemplates->closeGroupBox();
            }
            $html = '<a class="icon-text-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                'choice' => 'manualtemplates',
                'conf' => - 1
            )) . '"><i class="bi bi-plus-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_MANUAL_TEMPLATE_ADD') . '</a>';
            $formManualTemplates->addCustomContent('', $html, array(
                'helpTextId' => $htmlDesc
            ));
            $formManualTemplates->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'manualtemplates', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_MANUAL_TEMPLATES'), 'bi bi-receipt', $formManualTemplates->show()));

            // PANEL: PROFILE_ADDIN (Registerkarte "Beitraege" im Mitgliedsprofil)

            if ($gCurrentUser->isAdministrator()) {
                $addinInstalled = ProfileAddin::isInstalled();
                // aeltere Fassung (nur "Beitraege") automatisch um die Registerkarte "Arbeitsdienst" ergaenzen
                if ($addinInstalled && !ProfileAddin::isCurrent()) {
                    ProfileAddin::install(PLUGIN_FOLDER);
                }
                $formProfileAddin = new HtmlForm('profileaddin_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/profile_addin_install.php', array(
                    'mode' => $addinInstalled ? 'uninstall' : 'install'
                )), $page);
                $formProfileAddin->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_PROFILE_ADDIN_TABS_DESC'));
                $formProfileAddin->addStaticControl('profileaddin_status', $gL10n->get('PLG_MEMBERSHIPFEE_STATUS'), $gL10n->get($addinInstalled ? 'PLG_MEMBERSHIPFEE_PROFILE_ADDIN_ACTIVE' : 'PLG_MEMBERSHIPFEE_PROFILE_ADDIN_INACTIVE'));
                $formProfileAddin->addSubmitButton('btn_profileaddin', $gL10n->get($addinInstalled ? 'PLG_MEMBERSHIPFEE_PROFILE_ADDIN_REMOVE' : 'PLG_MEMBERSHIPFEE_PROFILE_ADDIN_INSTALL'), array(
                    'icon' => $addinInstalled ? 'bi-x-circle' : 'bi-plus-circle',
                    'class' => ' offset-sm-3'
                ));
                $page->addHtml(getMenuePanel('preferences', 'profileaddin', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_PROFILE_ADDIN'), 'bi bi-person-vcard', $formProfileAddin->show()));
            }

            // PANEL: ACCOUNT_DATA

            $formAccountData = new HtmlForm('accountdata_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'accountdata'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            $formAccountData->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA_INFO'));
            $formAccountData->addInput('iban', $gL10n->get('PLG_MEMBERSHIPFEE_IBAN'), $pPreferences->config['Kontodaten']['iban'], array(
                'property' => HtmlForm::FIELD_REQUIRED
            ));
            $formAccountData->addInput('bic', $gL10n->get('PLG_MEMBERSHIPFEE_BIC'), $pPreferences->config['Kontodaten']['bic']);
            $formAccountData->addInput('bank', $gL10n->get('PLG_MEMBERSHIPFEE_BANK'), $pPreferences->config['Kontodaten']['bank'], array(
                'property' => HtmlForm::FIELD_REQUIRED
            ));

            if ($getChoice == 'accountdata') {
                $formAccountData->addInput('creditor', $gL10n->get('PLG_MEMBERSHIPFEE_CREDITOR'), $pPreferences->config['Kontodaten']['inhaber'], array(
                    'property' => HtmlForm::FIELD_REQUIRED
                ));
                $html = '<a class="iconLink" id="creditorschieben" href="javascript:creditorschieben()">
                        <i class="bi bi-arrow-down" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_MOVE_CREDITOR') . '"></i>
                    </a>';

                $formAccountData->addCustomContent('', $html);
                $formAccountData->addInput('origcreditor', $gL10n->get('PLG_MEMBERSHIPFEE_ORIG_CREDITOR'), $pPreferences->config['Kontodaten']['origcreditor']);

                $formAccountData->addInput('ci', $gL10n->get('PLG_MEMBERSHIPFEE_CI'), $pPreferences->config['Kontodaten']['ci'], array(
                    'property' => HtmlForm::FIELD_REQUIRED
                ));
                $html = '<a class="iconLink" id="cischieben" href="javascript:cischieben()">
                        <i class="bi bi-arrow-down" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_MOVE_CI') . '"></i>
                    </a>';
                $formAccountData->addCustomContent('', $html);
                $formAccountData->addInput('origci', $gL10n->get('PLG_MEMBERSHIPFEE_ORIG_CI'), $pPreferences->config['Kontodaten']['origci']);
                $html = '<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_CHANGE_CDTR_INFO') . '</div>';
                $formAccountData->addCustomContent('', $html);
            } else {
                $formAccountData->addInput('creditor', $gL10n->get('PLG_MEMBERSHIPFEE_CREDITOR'), $pPreferences->config['Kontodaten']['inhaber'], array(
                    'property' => HtmlForm::FIELD_REQUIRED
                ));
                if (! empty($pPreferences->config['Kontodaten']['origcreditor'])) {
                    $formAccountData->addInput('origcreditor', $gL10n->get('PLG_MEMBERSHIPFEE_ORIG_CREDITOR'), $pPreferences->config['Kontodaten']['origcreditor']);
                }
                $formAccountData->addInput('ci', $gL10n->get('PLG_MEMBERSHIPFEE_CI'), $pPreferences->config['Kontodaten']['ci'], array(
                    'property' => HtmlForm::FIELD_REQUIRED
                ));
                if (! empty($pPreferences->config['Kontodaten']['origci'])) {
                    $formAccountData->addInput('origci', $gL10n->get('PLG_MEMBERSHIPFEE_ORIG_CI'), $pPreferences->config['Kontodaten']['origci']);
                }

                $html = '<a class="icon-text-info" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array(
                    'choice' => 'accountdata'
                )) . '">' . $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_CHANGE') . '</a>';
                $formAccountData->addCustomContent('', $html, array(
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_MANDATE_CHANGE_DESC'
                ));
            }
            $formAccountData->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'accountdata', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA'), 'bi bi-bank', $formAccountData->show()));

            // PANEL: MANDATE_MANAGEMENT

            $formMandateManagement = new HtmlForm('mandatemanagement_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'mandatemanagement'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            $formMandateManagement->addInput('prefix_mem', $gL10n->get('PLG_MEMBERSHIPFEE_PREFIX_MEM'), $pPreferences->config['Mandatsreferenz']['prefix_mem']);
            $formMandateManagement->addInput('prefix_pay', $gL10n->get('PLG_MEMBERSHIPFEE_PREFIX_PAY'), $pPreferences->config['Mandatsreferenz']['prefix_pay']);
            $formMandateManagement->addInput('min_length', $gL10n->get('PLG_MEMBERSHIPFEE_MIN_LENGTH'), $pPreferences->config['Mandatsreferenz']['min_length'], array(
                'type' => 'number',
                'minNumber' => 5,
                'maxNumber' => 35
            ));

            $configSelection = array();
            $i = 0;
            foreach ($gProfileFields->getProfileFields() as $field) {
                // normalerweise sollte für das "Datenfeld für die laufende Nummer" ein Profilfeld mit einer laufenden Nummer (Mitgliedsnummer oder UserID) gewählt werden
                // LAST_NAME oder DEBTOR z.B. darf nicht gewählt werden, da dies zu einem SQL-FEHLER in der Funktion list_members führt
                if (in_array($field->getValue('usf_name_intern'), array(
                    'LAST_NAME',
                    'FIRST_NAME',
                    'DEBTOR',
                    'MANDATEID' . $gCurrentOrgId,
                    'FEE' . $gCurrentOrgId,
                    'CONTRIBUTORY_TEXT' . $gCurrentOrgId,
                    'IBAN'
                ))) {
                    continue;
                }

                $configSelection[$i][0] = $field->getValue('usf_name_intern');
                $configSelection[$i][1] = addslashes($field->getValue('usf_name'));
                $configSelection[$i][2] = $field->getValue('cat_name');
                $i ++;
            }
            $configSelection[$i][0] = '-- User_ID --';
            $configSelection[$i][1] = '-- User_ID --';
            $configSelection[$i][2] = $gL10n->get('PLG_MEMBERSHIPFEE_ADDITIONAL_FIELDS');
            $formMandateManagement->addSelectBox('data_field', $gL10n->get('PLG_MEMBERSHIPFEE_DATA_FIELD_SERIAL_NUMBER'), $configSelection, array(
                'defaultValue' => $pPreferences->config['Mandatsreferenz']['data_field'],
                'showContextDependentFirstEntry' => false
            ));
            $formMandateManagement->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT_DESC'));
            $formMandateManagement->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'mandatemanagement', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT'), 'bi bi-sliders2', $formMandateManagement->show()));

            // PANEL: VIEW_DEFINITIONS

            $formColumnSet = new HtmlForm('columnset_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'columnset'
            )), $page, array(
                'class' => 'form-preferences'
            ));
            $formColumnSet->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_VIEW_DEFINITIONS_HEADER'));
            $formColumnSet->addDescription('<div style="width:100%; height:550px; overflow:auto; border:20px;">');

            foreach ($pPreferences->config['columnconfig'] as $conf => $confFields) {
                $groupHeader = '';
                switch ($conf) {
                    case 'payments_fields':
                        $groupHeader = 'PLG_MEMBERSHIPFEE_PAYMENTS_FIELDS';
                        break;
                    case 'mandates_fields':
                        $groupHeader = 'PLG_MEMBERSHIPFEE_MANDATES_FIELDS';
                        break;
                    case 'duedates_fields':
                        $groupHeader = 'PLG_MEMBERSHIPFEE_DUEDATES_FIELDS';
                        break;
                    case 'bill_fields':
                        $groupHeader = 'PLG_MEMBERSHIPFEE_BILL';
                        break;
                }
                $formColumnSet->openGroupBox('configurations_group', $gL10n->get($groupHeader));

                $html = '
	           <div class="table-responsive">
		          <table class="table table-condensed" id="mylist_fields_table">
			         <thead>
				            <tr>
					           <th style="width: 20%;">' . $gL10n->get('SYS_ABR_NO') . '</th>
					           <th style="width: 37%;">' . $gL10n->get('SYS_CONTENT') . '</th>
				            </tr>
			         </thead>
			         <tbody id="mylist_fields_tbody' . $conf . '">
				            <tr id="table_row_button">
					           <td colspan="2">
					               <a class="icon-text-link" href="javascript:addColumn' . $conf . '()"><i class="bi bi-plus-circle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_ADD_ANOTHER_COLUMN') . '</a>
                                </td>
				            </tr>
			         </tbody>
		          </table>
	           </div>';
                $formColumnSet->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_COLUMN_SELECTION'), $html);
                $formColumnSet->closeGroupBox();
            }
            $formColumnSet->addDescription('</div>');
            $formColumnSet->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'columnset', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_VIEW_DEFINITIONS'), 'bi bi-eyeglasses', $formColumnSet->show()));

            // PANEL: EXPORT

            $selectBoxEntries = array(
                'xlsx' => $gL10n->get('SYS_MICROSOFT_EXCEL') . ' (XLSX)',
                'csv-ms' => $gL10n->get('SYS_MICROSOFT_EXCEL') . ' (CSV)',
                'csv-oo' => $gL10n->get('SYS_CSV') . ' (' . $gL10n->get('SYS_UTF8') . ')'
            );

            $formExport = new HtmlForm('configurations_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'export'
            )), $page, array(
                'class' => 'form-preferences'
            ));

            $formExport->openGroupBox('sepa', $headline = $gL10n->get('PLG_MEMBERSHIPFEE_SEPA'));
            $formExport->addInput('dateiname', $gL10n->get('PLG_MEMBERSHIPFEE_XML_FILE_NAME'), $pPreferences->config['SEPA']['dateiname'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_XML_FILE_NAME_DESC',
                'property' => HtmlForm::FIELD_REQUIRED
            ));
            $selectBoxEntriesDirectDebit = array(
                'v2' => 'pain.008.001.02',
                'v8' => 'pain.008.001.08'
            );
            $formExport->addSelectBox('direct_debit_format', $gL10n->get('PLG_MEMBERSHIPFEE_XML_FILE') . ' ' . $gL10n->get('SYS_FORMAT'), $selectBoxEntriesDirectDebit, array(
                'defaultValue' => $pPreferences->config['SEPA']['direct_debit_format'],
                'showContextDependentFirstEntry' => false
            ));
            $formExport->addInput('kontroll_dateiname', $gL10n->get('PLG_MEMBERSHIPFEE_CONTROL_FILE_NAME'), $pPreferences->config['SEPA']['kontroll_dateiname'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_CONTROL_FILE_NAME_DESC',
                'property' => HtmlForm::FIELD_REQUIRED
            ));
            $formExport->addSelectBox('kontroll_dateityp', $gL10n->get('PLG_MEMBERSHIPFEE_CONTROL_FILE_TYPE'), $selectBoxEntries, array(
                'defaultValue' => $pPreferences->config['SEPA']['kontroll_dateityp'],
                'showContextDependentFirstEntry' => false
            ));
            $formExport->addInput('vorabinformation_dateiname', $gL10n->get('PLG_MEMBERSHIPFEE_PRE_NOTIFICATION_FILE_NAME'), $pPreferences->config['SEPA']['vorabinformation_dateiname'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_NAME_WITHOUT_ENDING',
                'property' => HtmlForm::FIELD_REQUIRED
            ));
            $formExport->addSelectBox('vorabinformation_dateityp', $gL10n->get('PLG_MEMBERSHIPFEE_PRE_NOTIFICATION_FILE_TYPE'), $selectBoxEntries, array(
                'defaultValue' => $pPreferences->config['SEPA']['vorabinformation_dateityp'],
                'showContextDependentFirstEntry' => false
            ));
            $formExport->addCheckbox('duedate_for_all', $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE_FOR_ALL'), $pPreferences->config['SEPA']['duedate_for_all'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_DUEDATE_FOR_ALL_DESC'
            ));
            $formExport->closeGroupBox();

            $formExport->openGroupBox('sepa_share', $headline = $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_SETTINGS'));
            $formExport->addInput('share_email', $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_RECIPIENTS'), (string) ($pPreferences->config['SEPA-Freigabe']['email'] ?? ''), array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_SHARE_RECIPIENTS_SETTINGS_DESC'
            ));
            $formExport->addInput('share_days', $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_DAYS'), (string) ($pPreferences->config['SEPA-Freigabe']['tage'] ?? 14), array(
                'type' => 'number',
                'minNumber' => 1,
                'maxNumber' => 365,
                'step' => 1,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_SHARE_DAYS_DESC'
            ));
            $formExport->closeGroupBox();

            $formExport->openGroupBox('bill', $headline = $gL10n->get('PLG_MEMBERSHIPFEE_BILL'));
            $formExport->addInput('rechnung_dateiname', $gL10n->get('PLG_MEMBERSHIPFEE_BILL_FILE_NAME'), $pPreferences->config['Rechnungs-Export']['rechnung_dateiname'], array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_NAME_WITHOUT_ENDING',
                'property' => HtmlForm::FIELD_REQUIRED
            ));
            $formExport->addSelectBox('rechnung_dateityp', $gL10n->get('PLG_MEMBERSHIPFEE_BILL_FILE_TYPE'), $selectBoxEntries, array(
                'defaultValue' => $pPreferences->config['Rechnungs-Export']['rechnung_dateityp'],
                'showContextDependentFirstEntry' => false
            ));
            $formExport->closeGroupBox();

            $formExport->addDescription('');
            $formExport->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'export', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_EXPORT'), 'bi bi-download', $formExport->show()));

            // PANEL: EMAIL_NOTIFICATIONS

            $formEmailNotifications = new HtmlForm('email_notifications_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'emailnotifications'
            )), $page, array(
                'class' => 'form-preferences'
            ));

            $formEmailNotifications->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_EMAIL_NOTIFICATIONS'), '<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_EMAIL_NOTIFICATIONS_DESC') . ':</p><p>
            <strong>#user_first_name#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_FIRST_NAME') . '<br />
            <strong>#user_last_name#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_LAST_NAME') . '<br />
            <strong>#street#</strong> - ' . $gL10n->get('SYS_STREET') . '<br />
            <strong>#postcode#</strong> - ' . $gL10n->get('SYS_POSTCODE') . '<br />
            <strong>#city#</strong> - ' . $gL10n->get('SYS_CITY') . '<br />
            <strong>#email#</strong> - ' . $gL10n->get('SYS_EMAIL') . '<br />
            <strong>#phone#</strong> - ' . $gL10n->get('SYS_PHONE') . '<br />
            <strong>#mobile#</strong> - ' . $gL10n->get('SYS_MOBILE') . '<br />
            <strong>#birthday#</strong> - ' . $gL10n->get('SYS_BIRTHDAY') . '<br />
            <strong>#organization_long_name#</strong> - ' . $gL10n->get('ORG_VARIABLE_NAME_ORGANIZATION') . '<br />
            <strong>#fee#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_FEE') . '<br />
            <strong>#due_day#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_DUE_DAY') . '<br />
            <strong>#mandate_id#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_MANDATE_ID') . '<br />
            <strong>#mandate_date#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_MANDATEDATE') . '<br />
            <strong>#creditor_id#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_CREDITOR_ID') . '<br />
            <strong>#iban#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_IBAN') . '<br />
	        <strong>#iban_obfuscated#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_IBAN_OBFUSCATED') . '<br />
            <strong>#bic#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_BIC') . '<br />
            <strong>#bank#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_BANK') . '<br />
            <strong>#debtor#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_DEBTOR') . '<br />
            <strong>#membership_fee_text#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_VARIABLE_MEMBERSHIP_FEE_TEXT') . '<br />
            <strong > # membernumber#</strong> - ' . $gL10n->get('PLG_MEMBERSHIPFEE_MEMBERNUMBER') . '</p>');

            $text = new Text($gDb);
            $text->readDataByColumns(array(
                'txt_name' => 'PMBMAIL_CONTRIBUTION_PAYMENTS',
                'txt_org_id' => $gCurrentOrgId
            ));
            if ($text->getValue('txt_text') == '') {
                // convert <br /> to a normal line feed
                $value = preg_replace('/<br[[:space:]]*\/?[[:space:]]*>/', chr(13) . chr(10), $gL10n->get('PLG_MEMBERSHIPFEE_PMBMAIL_CONTRIBUTION_PAYMENTS'));
                $text->setValue('txt_text', $value);
                $text->save();
                $text->readDataByColumns(array(
                    'txt_name' => 'PMBMAIL_CONTRIBUTION_PAYMENTS',
                    'txt_org_id' => $gCurrentOrgId
                ));
            }
            $formEmailNotifications->addMultilineTextInput('mail_text', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PAYMENTS_MAIL_TEXT'), $text->getValue('txt_text'), 7);

            $text->readDataByColumns(array(
                'txt_name' => 'PMBMAIL_PRE_NOTIFICATION',
                'txt_org_id' => $gCurrentOrgId
            ));
            if ($text->getValue('txt_text') == '') {
                // convert <br /> to a normal line feed
                $value = preg_replace('/<br[[:space:]]*\/?[[:space:]]*>/', chr(13) . chr(10), $gL10n->get('PLG_MEMBERSHIPFEE_PMBMAIL_PRE_NOTIFICATION'));
                $text->setValue('txt_text', $value);
                $text->save();
                $text->readDataByColumns(array(
                    'txt_name' => 'PMBMAIL_PRE_NOTIFICATION',
                    'txt_org_id' => $gCurrentOrgId
                ));
            }
            $formEmailNotifications->addMultilineTextInput('pre_notification_text', $gL10n->get('PLG_MEMBERSHIPFEE_PRE_NOTIFICATION_MAIL_TEXT'), $text->getValue('txt_text'), 7);

            // Vorabinformation Arbeitsdienst (Platzhalter aus den Arbeitsdienst-Feldern, siehe replace_emailparameter())
            $text->readDataByColumns(array(
                'txt_name' => 'PMBMAIL_PRE_NOTIFICATION_AD',
                'txt_org_id' => $gCurrentOrgId
            ));
            if ($text->getValue('txt_text') == '') {
                $value = preg_replace('/<br[[:space:]]*\/?[[:space:]]*>/', chr(13) . chr(10), $gL10n->get('PLG_MEMBERSHIPFEE_PMBMAIL_PRE_NOTIFICATION_AD'));
                $text->setValue('txt_text', $value);
                $text->save();
                $text->readDataByColumns(array(
                    'txt_name' => 'PMBMAIL_PRE_NOTIFICATION_AD',
                    'txt_org_id' => $gCurrentOrgId
                ));
            }
            $formEmailNotifications->addMultilineTextInput('pre_notification_ad_text', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_PRE_NOTIFICATION_MAIL_TEXT'), $text->getValue('txt_text'), 7, array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_PRE_NOTIFICATION_MAIL_TEXT_DESC'
            ));

            $text->readDataByColumns(array(
                'txt_name' => 'PMBMAIL_BILL',
                'txt_org_id' => $gCurrentOrgId
            ));
            if ($text->getValue('txt_text') == '') {
                // convert <br /> to a normal line feed
                $value = preg_replace('/<br[[:space:]]*\/?[[:space:]]*>/', chr(13) . chr(10), $gL10n->get('PLG_MEMBERSHIPFEE_PMBMAIL_BILL'));
                $text->setValue('txt_text', $value);
                $text->save();
                $text->readDataByColumns(array(
                    'txt_name' => 'PMBMAIL_BILL',
                    'txt_org_id' => $gCurrentOrgId
                ));
            }
            $formEmailNotifications->addMultilineTextInput('bill_text', $gL10n->get('PLG_MEMBERSHIPFEE_BILL_MAIL_TEXT'), $text->getValue('txt_text'), 7);

            $formEmailNotifications->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'email_notifications', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_EMAIL_NOTIFICATIONS'), 'bi bi-envelope', $formEmailNotifications->show()));

            // PANEL: TESTS

            $formTestsSetup = new HtmlForm('testssetup_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'testssetup'
            )), $page, array(
                'class' => 'form-preferences'
            ));

            $formTestsSetup->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_TESTS_SETUP_INFO'));

            $formTestsSetup->addCheckbox('role_membership_duty_and_exclusion', $gL10n->get('PLG_MEMBERSHIPFEE_TESTS') . ' "' . $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_DUTY') . '" ' . $gL10n->get('PLG_MEMBERSHIPFEE_AND') . ' "' . $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_EXCLUSION') . '" ' . $gL10n->get('PLG_MEMBERSHIPFEE_ENABLE'), (bool) $pPreferences->config['tests_enable']['role_membership_duty_and_exclusion']);
            $formTestsSetup->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_DUTY'), '', array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_DUTY_DESC2'
            ));
            if (count($fixrollen) > 0) {
                $formTestsSetup->addDescription('<div style="width:100%; height:250px; overflow:auto; border:20px;">');
                foreach ($fixrollen as $key => $data) {
                    $formTestsSetup->addCheckbox('fixrollenpflicht' . $key, $data['rolle'], (in_array($key, $pPreferences->config['Rollenpruefung']['fixrollenpflicht']) ? 1 : 0));
                }
                $formTestsSetup->addDescription('</div>');
            } else {
                $formTestsSetup->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_NO_CONTRIBUTION_ROLES'));
            }

            $formTestsSetup->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_EXCLUSION'), '', array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_EXCLUSION_DESC2'
            ));
            if (count($fixrollen) > 1) {
                $formTestsSetup->addDescription('<div style="width:100%; height:250px; overflow:auto; border:20px;">');
                $fixrollenL = $fixrollen;
                array_pop($fixrollenL); // das letzte Element entfernen
                $fixrollenR = $fixrollen;

                foreach ($fixrollenL as $keyL => $dataL) {
                    unset($fixrollenR[$keyL]); // dasselbe Element entfernen
                    foreach ($fixrollenR as $keyR => $dataR) {
                        $formTestsSetup->addCheckbox('fixrollenfixrollen' . $keyL . '_' . $keyR, $dataL['rolle'] . ' ./. ' . $dataR['rolle'], (in_array($keyL . '_' . $keyR, $pPreferences->config['Rollenpruefung']['fixrollenfixrollen']) ? 1 : 0));
                    }
                }
                unset($fixrollenL);
                unset($fixrollenR);
                $formTestsSetup->addDescription('</div>');
            } else {
                $formTestsSetup->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_NO_COMBINATION_ROLES'));
            }
            $sql = 'SELECT cat_id, cat_name
          FROM ' . TBL_CATEGORIES . ' , ' . TBL_ROLES . '
         WHERE cat_id = rol_cat_id
           AND ( cat_org_id = ' . $gCurrentOrgId . '
            OR cat_org_id IS NULL )';
            $formTestsSetup->addSelectBoxFromSql('bezugskategorie', $gL10n->get('PLG_MEMBERSHIPFEE_CAT_SELECTION'), $gDb, $sql, array(
                'defaultValue' => $pPreferences->config['Rollenpruefung']['bezugskategorie'],
                'multiselect' => true,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_CAT_SELECTION_DESC'
            ));
            $formTestsSetup->addLine();

            $formTestsSetup->addCheckbox('account_details', $gL10n->get('PLG_MEMBERSHIPFEE_TEST') . ' "' . $gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA') . '" ' . $gL10n->get('PLG_MEMBERSHIPFEE_ENABLE'), (bool) $pPreferences->config['tests_enable']['account_details']);
            $formTestsSetup->addLine();

            $formTestsSetup->addCheckbox('mandate_management', $gL10n->get('PLG_MEMBERSHIPFEE_TEST') . ' "' . $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT') . '" ' . $gL10n->get('PLG_MEMBERSHIPFEE_ENABLE'), (bool) $pPreferences->config['tests_enable']['mandate_management']);
            $formTestsSetup->addLine();

            $formTestsSetup->addCheckbox('iban_check', $gL10n->get('PLG_MEMBERSHIPFEE_TEST') . ' "' . $gL10n->get('PLG_MEMBERSHIPFEE_IBANCHECK') . '" ' . $gL10n->get('PLG_MEMBERSHIPFEE_ENABLE'), (bool) $pPreferences->config['tests_enable']['iban_check']);
            $formTestsSetup->addLine();

            $formTestsSetup->addCheckbox('bic_check', $gL10n->get('PLG_MEMBERSHIPFEE_TEST') . ' "' . $gL10n->get('PLG_MEMBERSHIPFEE_BICCHECK') . '" ' . $gL10n->get('PLG_MEMBERSHIPFEE_ENABLE'), (bool) $pPreferences->config['tests_enable']['bic_check']);
            $formTestsSetup->addLine();

            $formTestsSetup->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'testssetup', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_TESTS'), 'bi bi-heart-pulse', $formTestsSetup->show()));

            // PANEL: ACCESS_PREFERENCES

            $formAccessPreferences = new HtmlForm('access_preferences_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee_function.php', array(
                'form' => 'access_preferences'
            )), $page, array(
                'class' => 'form-preferences'
            ));

            $sql = 'SELECT rol.rol_id, rol.rol_name, cat.cat_name
                  FROM ' . TBL_CATEGORIES . ' AS cat, ' . TBL_ROLES . ' AS rol
                 WHERE cat.cat_id = rol.rol_cat_id
                   AND ( cat.cat_org_id = ' . $gCurrentOrgId . '
                    OR cat.cat_org_id IS NULL )
              ORDER BY cat_sequence, rol.rol_name ASC';
            $formAccessPreferences->addSelectBoxFromSql('access_preferences', '', $gDb, $sql, array(
                'defaultValue' => $pPreferences->config['access']['preferences'],
                'helpTextId' => 'PLG_MEMBERSHIPFEE_ACCESS_PREFERENCES_DESC',
                'multiselect' => true
            ));
            $formAccessPreferences->addSubmitButton('btn_save_configurations', $gL10n->get('SYS_SAVE'), array(
                'icon' => 'bi-check-lg',
                'class' => ' offset-sm-3'
            ));

            $page->addHtml(getMenuePanel('preferences', 'access_preferences', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_ACCESS_PREFERENCES'), 'bi bi-key-fill', $formAccessPreferences->show()));

            // PANEL: DELETE

            $formDelete = new HtmlForm('delete_form', '', $page);
            $formDelete->addButton('btn_delete', $gL10n->get('PLG_MEMBERSHIPFEE_DELETE'), array(
                'icon' => 'bi-trash',
                'link' => 'delete.php',
                'class' => 'btn-primary offset-sm-3'
            ));
            $formDelete->addCustomContent('', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_DELETE_DESC'));

            $page->addHtml(getMenuePanel('preferences', 'delete', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_RESET'), 'bi bi-trash', $formDelete->show()));

            // PANEL: DEINSTALLATION

            $formUninstallation = new HtmlForm('uninstallation_form', '', $page);
            $formUninstallation->addButton('btn_uninstallation', $gL10n->get('PLG_MEMBERSHIPFEE_UNINSTALLATION'), array(
                'icon' => 'bi-trash',
                'link' => 'uninstall.php',
                'class' => 'btn-primary offset-sm-3'
            ));
            $formUninstallation->addCustomContent('', '<br/>' . $gL10n->get('PLG_MEMBERSHIPFEE_UNINSTALLATION_DESC'));

            $page->addHtml(getMenuePanel('preferences', 'uninstallation', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_UNINSTALLATION'), 'bi bi-trash', $formUninstallation->show()));

            // PANEL: PLUGIN_INFORMATION

            $formPluginInformations = new HtmlForm('plugin_informations_form', '', $page);
            $formPluginInformations->addStaticControl('plg_name', $gL10n->get('PLG_MEMBERSHIPFEE_PLUGIN_NAME'), $gL10n->get('PLG_MEMBERSHIPFEE_MEMBERSHIP_FEE'));
            $formPluginInformations->addStaticControl('plg_version', $gL10n->get('PLG_MEMBERSHIPFEE_PLUGIN_VERSION'), $pPreferences->config['Plugininformationen']['version']);
            $formPluginInformations->addStaticControl('plg_date', $gL10n->get('PLG_MEMBERSHIPFEE_PLUGIN_DATE'), $pPreferences->config['Plugininformationen']['stand']);

            $docfile = 'documentation-en.pdf';
            if ($gSettingsManager->getString('system_language') === 'de' || $gSettingsManager->getString('system_language') === 'de-DE') {
                $docfile = 'documentation-de.pdf';
            }
            $html = '<a class="icon-text-link" href="' . ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/docs/' . $docfile . '" target="_blank"><i class="bi bi-file-earmark-pdf"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_DOCUMENTATION_OPEN') . '</a>';
            $formPluginInformations->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_DOCUMENTATION'), $html);

            $page->addHtml(getMenuePanel('preferences', 'plugin_informations', 'accordion_preferences', $gL10n->get('PLG_MEMBERSHIPFEE_PLUGIN_INFORMATION'), 'bi bi-info-circle', $formPluginInformations->show()));

            $page->addHtml(closeMenueTab());
        }

        // TAB: DOCUMENTATION (nur in deutscher Sprache vorhanden)
        if ($showGermanDocumentation) {
            $GLOBALS['mfPanels']['documentation'] = array('title' => '', 'body' => file_get_contents(__DIR__ . '/../docs/documentation-de.html'));
        }
    } else {
        $page->addHtml('<div class="alert alert-warning alert-small" role="alert"><i class="bi bi-exclamation-triangle"></i>' . $gL10n->get('PLG_MEMBERSHIPFEE_NO_CONTRIBUTION_ROLES_DEFINED') . '</div>');
    }

    // rechts: Uebersicht oder der Inhalt des gewaehlten Menueeintrags
    // 4.0 SEPA-Datei erstellen gilt fuer beide Buchungskreise: Inhalt des gewaehlten Buchungskreises
    $panelKey = $getShow === 'collection_sepa' ? ($mfScope->isMembership() ? 'fees_sepa' : 'workservice_worksepa') : $getShow;
    $panel = $GLOBALS['mfPanels'][$panelKey] ?? null;
    if ($panel === null) {
        $page->addHtml('<div class="alert alert-warning" role="alert">' . $gL10n->get('SYS_INVALID_PAGE_VIEW') . '</div>');
    } elseif ($getShow === 'documentation') {
        $page->addHtml($panel['body']);
    } else {
        foreach (mf_menu_groups() as $menuGroup) {
            foreach ($menuGroup['entries'] as $menuEntry) {
                if ($menuEntry['key'] === $getShow) {
                    $panel['title'] = '<i class="bi ' . $menuEntry['icon'] . ' bi-fw"></i>' . SecurityUtils::encodeHTML($menuEntry['title']);
                }
            }
        }
        $page->addHtml('<div class="card admidio-tabbed-field-group"><div class="card-header">' . $panel['title'] . '</div>'
            . '<div class="card-body">' . $panel['body'] . '</div></div>');
    }

    $page->show();
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}

/**
 * Startseite rechts neben dem Menue: Stand des Beitragseinzugs und die Schritte als Links.
 */
function mf_overview_html(string $summaryHtml): string
{
    global $gL10n;

    $html = '<div class="card admidio-tabbed-field-group"><div class="card-header"><i class="bi bi-speedometer2 bi-fw"></i>'
        . $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_YEAR') . ' ' . getContributionYear() . '</div>'
        . '<div class="card-body">' . $summaryHtml . '</div></div>';

    $html .= '<div class="row">';
    foreach (array_slice(mf_menu_groups(), 0, 3) as $group) {
        $html .= '<div class="col-xl-4"><div class="card admidio-tabbed-field-group"><div class="card-header">' . SecurityUtils::encodeHTML($group['label']) . '</div>'
            . '<div class="card-body"><div class="list-group">';
        foreach ($group['entries'] as $entry) {
            if ($entry['key'] === 'overview') {
                continue;
            }
            $html .= '<a class="list-group-item list-group-item-action" href="' . $entry['url'] . '"><i class="bi ' . $entry['icon'] . ' me-2"></i>'
                . SecurityUtils::encodeHTML($entry['title']) . '</a>';
        }
        $html .= '</div></div></div></div>';
    }
    return $html . '</div>';
}
