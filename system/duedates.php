<?php
/**
 ***********************************************************************************************
 * Setzen eines Faelligkeitsdatums fuer das Admidio-Plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Hinweis:   duedates.php ist eine modifizierte members_assignment.php
 *
 * Parameters:
 *
 * mode             : html   - Standardmodus zun Anzeigen einer html-Liste aller Benutzer mit Beitraegen
 *                    assign - Setzen eines Faelligkeitsdatum
 * usr_id           : Id des Benutzers, fuer den das Faelligkeitsdatum gesetzt/geloescht wird
 * datum_neu        : das neue Faelligkeitsdatum
 * mem_show_choice  : 0 - (Default) Alle Benutzer anzeigen
 *                    1 - Nur Benutzer anzeigen, bei denen ein Faelligkeitsdatum vorhanden ist
 *                    2 - Nur Benutzer anzeigen, bei denen kein Faelligkeitsdatum vorhanden ist
 * bulk             : nur ohne usr_id: set = Faelligkeit bei allen angezeigten Benutzern ohne Faelligkeit setzen,
 *                    remove = bei allen angezeigten Benutzern entfernen; leer = umschalten (bisheriges Verhalten)
 * sequencetype     : Sequenztyp, der gleichzeitig mit dem Faelligkeitsdatum gesetzt wird (AUTO, FRST, RCUR, FNAL oder OOFF);
 *                    AUTO = FRST bei der ersten Lastschrift, sonst RCUR (siehe mf_auto_sequence_types())
 *
 * Samstage, Sonntage und Bankfeiertage (mf_bank_holidays()) sind als Faelligkeit nicht moeglich.
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Roles\Entity\Role;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Config\ConfigTable;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

// only authorized user are allowed to start this module
if (! isUserAuthorized()) {
    throw new Exception('SYS_NO_RIGHTS');
}

$pPreferences = new ConfigTable();
$pPreferences->read();

$user = new User($gDb, $gProfileFields);

if (isset($_GET['mode']) && $_GET['mode'] == 'assign') {
    // ajax mode then only show text if error occurs
    $gMessage->showTextOnly(true);
}

// Initialize and check the parameters
$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
    'defaultValue' => 'html',
    'validValues' => array(
        'html',
        'assign'
    )
));
$getUserId = admFuncVariableIsValid($_GET, 'usr_id', 'numeric', array(
    'defaultValue' => 0,
    'directOutput' => true
));
$getDatumNeu = admFuncVariableIsValid($_GET, 'datum_neu', 'date');
$getMembersShow = admFuncVariableIsValid($_GET, 'mem_show_choice', 'numeric', array(
    'defaultValue' => 0
));
$getSequenceType = admFuncVariableIsValid($_GET, 'sequencetype', 'string');
$getBulk = admFuncVariableIsValid($_GET, 'bulk', 'string', array(
    'defaultValue' => '',
    'validValues' => array('', 'set', 'remove')
));

// write role selection in session
if (strpos($gNavigation->getUrl(), 'membership_fee.php') !== false) {
    if (isset($_POST['duedates_roleselection'])) {
        $_SESSION['pMembershipFee']['duedates_rol_sel'] = $_POST['duedates_roleselection'];
    } else {
        unset($_SESSION['pMembershipFee']['duedates_rol_sel']);
    }
}

if ($getMode == 'assign') {
    $ret_text = 'ERROR';

    $userArray = array();
    if ($getUserId != 0) // Faelligkeitsdatum nur fuer einen einzigen User aendern
    {
        $userArray[0] = $getUserId;
    } else // Alle aendern wurde gewaehlt
    {
        $userArray = $_SESSION['pMembershipFee']['duedates_user'];
    }

    $problem = mf_duedate_problem((string) $getDatumNeu);
    $autoTypes = array();
    if ($getSequenceType === 'AUTO') {
        $autoTypes = mf_auto_sequence_types($userArray);
    }
    $resultTypes = array();

    // ungueltiges Datum: abbrechen, bevor bei irgendeinem Benutzer etwas geaendert wird
    if ($problem !== null && $getBulk !== 'remove') {
        foreach ($userArray as $data) {
            $user->readDataById($data);
            if (strlen($user->getValue('DUEDATE' . $gCurrentOrgId)) === 0) {
                echo 'Das Fälligkeitsdatum ' . date('d.m.Y', (int) strtotime((string) $getDatumNeu)) . ' ist nicht möglich (' . $problem
                    . '). Bitte einen Bankarbeitstag wählen, z. B. ' . date('d.m.Y', (int) strtotime(mf_next_valid_duedate((string) $getDatumNeu))) . '.';
                exit();
            }
        }
    }

    try {
        foreach ($userArray as $dummy => $data) {
            $user->readDataById($data);
            $hasDuedate = strlen($user->getValue('DUEDATE' . $gCurrentOrgId)) !== 0;

            // Schaltflaechen „bei allen setzen/entfernen“: nur die Benutzer aendern, bei denen sich etwas aendert
            if (($getBulk === 'set' && $hasDuedate) || ($getBulk === 'remove' && !$hasDuedate)) {
                $ret_text = 'success';
                continue;
            }

            // zuerst mal sehen, ob bei diesem user bereits ein Faelligkeitsdatum vorhanden ist
            if (!$hasDuedate) {
                // er hat noch kein Faelligkeitsdatum, deshalb ein neues eintragen
                if ($problem !== null) {
                    echo 'Das Fälligkeitsdatum ' . date('d.m.Y', (int) strtotime((string) $getDatumNeu)) . ' ist nicht möglich (' . $problem
                        . '). Bitte einen Bankarbeitstag wählen, z. B. ' . date('d.m.Y', (int) strtotime(mf_next_valid_duedate((string) $getDatumNeu))) . '.';
                    exit();
                }
                $user->setValue('DUEDATE' . $gCurrentOrgId, $getDatumNeu);

                $sequenceType = $getSequenceType === 'AUTO' ? ($autoTypes[(int) $data] ?? 'FRST') : $getSequenceType;
                if ($sequenceType != '') {
                    $user->setValue('SEQUENCETYPE' . $gCurrentOrgId, $sequenceType);
                }
                $resultTypes[] = $sequenceType;
            } else {
                // er hat bereits ein Faelligkeitsdatum, deshalb das vorhandene loeschen
                $user->setValue('DUEDATE' . $gCurrentOrgId, '');
            }

            $user->save();
            $ret_text = 'success';
        }
    } catch (Exception $e) {
        echo $e->getMessage();
        exit();
    }
    // bei einem einzelnen Benutzer den gesetzten Sequenztyp mitliefern (success:RCUR), damit die Zeile ihn anzeigt
    echo ($ret_text === 'success' && $getUserId != 0 && count($resultTypes) === 1) ? $ret_text . ':' . $resultTypes[0] : $ret_text;
} else {
    $userArray = array();
    $membersList = array();

    if (isset($_SESSION['pMembershipFee']['duedates_rol_sel'])) {
        // Rollenwahl ist vorhanden, deshalb Daten aufbereiten fuer list_members
        $membersListRols = array();
        $role = new Role($gDb);
        foreach ($_SESSION['pMembershipFee']['duedates_rol_sel'] as $rol_id) {
            $role->readDataById($rol_id);
            $membersListRols[$role->getValue('rol_name')] = 0;
        }
    } else {
        $membersListRols = 0;
    }

    $membersListFields = array_filter($pPreferences->config['columnconfig']['duedates_fields']); // array_filter: löschen leerer Eintr�ge, falls das Setup fehlgeschlagen ist

    // Fälligkeitsdatum, Sequenztyp und Beitrag gehören immer in diese Tabelle (die Spalten Fälligkeit und
    // Sequenztyp werden beim Anklicken per JavaScript aktualisiert); fehlen sie in der Spaltenkonfiguration,
    // werden sie vorne ergänzt
    $requiredFields = array();
    foreach (array('DUEDATE', 'SEQUENCETYPE', 'FEE') as $fieldName) {
        $usfIdRequired = (int) $gProfileFields->getProperty($fieldName . $gCurrentOrgId, 'usf_id');
        if ($usfIdRequired > 0 && !in_array('p' . $usfIdRequired, $membersListFields, true)
            && !in_array($fieldName . $gCurrentOrgId, $membersListFields, true)) {
            $requiredFields[] = 'p' . $usfIdRequired;
        }
    }
    $membersListFields = array_merge($requiredFields, $membersListFields);

    $membersListSqlCondition = 'AND mem_usr_id IN (SELECT DISTINCT usr_id
        FROM ' . TBL_USERS . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS mandateid
          ON mandateid.usd_usr_id = usr_id
         AND mandateid.usd_usf_id = ' . $gProfileFields->getProperty('MANDATEID' . $gCurrentOrgId, 'usf_id') . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS mandatedate
          ON mandatedate.usd_usr_id = usr_id
         AND mandatedate.usd_usf_id = ' . $gProfileFields->getProperty('MANDATEDATE' . $gCurrentOrgId, 'usf_id') . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS duedate
          ON duedate.usd_usr_id = usr_id
         AND duedate.usd_usf_id = ' . $gProfileFields->getProperty('DUEDATE' . $gCurrentOrgId, 'usf_id') . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS paid
          ON paid.usd_usr_id = usr_id
         AND paid.usd_usf_id = ' . $gProfileFields->getProperty('PAID' . $gCurrentOrgId, 'usf_id') . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS fee
          ON fee.usd_usr_id = usr_id
         AND fee.usd_usf_id = ' . $gProfileFields->getProperty('FEE' . $gCurrentOrgId, 'usf_id') . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS iban
          ON iban.usd_usr_id = usr_id
         AND iban.usd_usf_id = ' . $gProfileFields->getProperty('IBAN', 'usf_id') . '
       WHERE paid.usd_value IS NULL
         AND fee.usd_value IS NOT NULL
    ';

    if ($pPreferences->config['SEPA']['duedate_for_all'] == 0) {
        $membersListSqlCondition .= '      AND iban.usd_value IS NOT NULL
		 AND mandatedate.usd_value IS NOT NULL
		 AND mandateid.usd_value IS NOT NULL  ';
    }

    if ($getMembersShow == 1) // Nur Benutzer anzeigen, bei denen ein Faelligkeitsdatumvorhanden ist
    {
        $membersListSqlCondition .= ' AND duedate.usd_value IS NOT NULL ) ';
    } elseif ($getMembersShow == 2) // Nur Benutzer anzeigen, bei denen kein Faelligkeitsdatum vorhanden ist
    {
        $membersListSqlCondition .= ' AND duedate.usd_value IS NULL ) ';
    } else // Alle Benutzer anzeigen
    {
        $membersListSqlCondition .= ' ) ';
    }

    $membersList = list_members($membersListFields, $membersListRols, $membersListSqlCondition);

    // set headline of the script
    $headline = $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE');

    $gNavigation->addUrl(CURRENT_URL, $headline);

    $page = new HtmlPage('plg-mitgliedsbeitrag-duedates', $headline);
    $page->setContentFullWidth();

    // gesperrte Tage (Bankfeiertage) fuer die Pruefung im Browser: Vorjahr bis zwei Jahre voraus
    $blockedDays = array();
    for ($holidayYear = (int) date('Y') - 1; $holidayYear <= (int) date('Y') + 2; $holidayYear++) {
        $blockedDays = array_merge($blockedDays, mf_bank_holidays($holidayYear));
    }

    $javascriptCode = '
        var mfHolidays = ' . json_encode($blockedDays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';

        // Samstag, Sonntag oder Bankfeiertag? Liefert den Grund oder ""
        function mfDuedateProblem(value) {
            if (!/^\\d{4}-\\d{2}-\\d{2}$/.test(value)) {
                return "kein gültiges Datum";
            }
            var parts = value.split("-");
            var day = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).getDay();
            if (day === 6) {
                return "Samstag";
            }
            if (day === 0) {
                return "Sonntag";
            }
            return mfHolidays[value] || "";
        }

        function mfFormatDate(value) {
            var parts = value.split("-");
            return parts.length === 3 ? parts[2] + "." + parts[1] + "." + parts[0] : value;
        }

        function mfCheckDuedate() {
            var value = $("#datum").val();
            var problem = mfDuedateProblem(value);
            $("#datum").toggleClass("is-invalid", problem !== "");
            $("#mf_duedate_problem").text(problem !== "" ? mfFormatDate(value) + " ist als Fälligkeit nicht möglich (" + problem + ")." : "");
            return problem;
        }
        $("#datum").on("change input", mfCheckDuedate);
        $("#datum").closest("form").after("<div id=\\"mf_duedate_problem\\" class=\\"text-danger fw-bold mb-2\\"></div>");
        mfCheckDuedate();

        // Anzeige abhaengig vom gewaehlten Filter
        $("#mem_show").change(function () {
            if($(this).val().length > 0) {
                window.location.replace("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/duedates.php') . '?mem_show_choice=" + $(this).val());
            }
        });

        // Schaltflaechen „bei allen setzen“ / „bei allen entfernen“
        $(".mf-duedate-bulk").click(function(){
            var bulk = $(this).data("bulk");
            var datum = $("#datum").val();
            var sequencetype = $("#lastschrifttyp").val();
            var count = $("input[type=checkbox].memlist_member").length;
            if (bulk === "set" && mfCheckDuedate() !== "") {
                alert($("#mf_duedate_problem").text() + " Bitte einen Bankarbeitstag wählen.");
                return false;
            }
            var question = bulk === "set"
                ? "Fälligkeit " + mfFormatDate(datum) + " (Sequenztyp: " + $("#lastschrifttyp option:selected").text() + ") bei allen angezeigten Mitgliedern ohne Fälligkeit setzen?"
                : "Fälligkeit bei allen angezeigten Mitgliedern entfernen?";
            if (!confirm(question)) {
                return false;
            }
            var button = $(this);
            $(".mf-duedate-bulk").prop("disabled", true);
            button.find(".spinner-border").removeClass("d-none");
            $.post("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/duedates.php', array(
        'mode' => 'assign'
    )) . '&bulk=" + bulk + "&sequencetype=" + sequencetype + "&datum_neu=" + datum,
                function(data){
                    if(data.indexOf("success") === 0) {
                        window.location.replace("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/duedates.php') . '?mem_show_choice=" + $("#mem_show").val());
                    } else {
                        $(".mf-duedate-bulk").prop("disabled", false);
                        button.find(".spinner-border").addClass("d-none");
                        alert(data);
                    }
                }
            );
        });

        // if checkbox in header is clicked then change all data
        $("input[type=checkbox].change_checkbox").click(function(){
            var datum = $("#datum").val();
            if (mfCheckDuedate() !== "") {
                alert($("#mf_duedate_problem").text() + " Bitte einen Bankarbeitstag wählen.");
                return false;
            }
            var sequencetype = $("#lastschrifttyp").val(); 
            $.post("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/duedates.php', array(
        'mode' => 'assign'
    )) . '&sequencetype=" + sequencetype + "&datum_neu=" + datum,
                function(data){
                    // check if error occurs
                    if(data == "success") {
                        var mem_show = $("#mem_show").val();
                        window.location.replace("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/duedates.php') . '?mem_show_choice=" + mem_show);
                    }
                    else {
                        alert(data);
                        return false;
                    }
                    return true;
                }
            );
        });

        // if checkbox of user is clicked then change data
        $("input[type=checkbox].memlist_checkbox").click(function(e){
            e.stopPropagation();
            var checkbox = $(this);
            var row_id = $(this).parent().parent().attr("id");
            var pos = row_id.search("_");
            var userid = row_id.substring(pos+1);
            var datum = $("#datum").val();
            var member_checked = $("input[type=checkbox]#member_"+userid).prop("checked");
            var sequencetype = $("#lastschrifttyp").val();
            if (member_checked && mfCheckDuedate() !== "") {
                alert($("#mf_duedate_problem").text() + " Bitte einen Bankarbeitstag wählen.");
                checkbox.prop("checked", false);
                return false;
            }

            // change data in database
            $.post("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/duedates.php', array(
        'mode' => 'assign'
    )) . '&datum_neu=" + datum + "&sequencetype=" + sequencetype + "&usr_id=" + userid,
                function(data){
                    // check if error occurs
                    if(data.indexOf("success") === 0) {
                        if(member_checked){
                            $("input[type=checkbox]#member_"+userid).prop("checked", true);
                            $("#duedate_"+userid).text(mfFormatDate(datum));
                            if(sequencetype == "AUTO") {
                                // vom Server ermittelter Sequenztyp (success:FRST bzw. success:RCUR)
                                sequencetype = data.split(":")[1] || "";
                            }

                            if(sequencetype == "FRST") {
                                $("#lastschrifttyp_"+userid).text("FRST");
                            }
                            else if(sequencetype == "RCUR") {
                                $("#lastschrifttyp_"+userid).text("RCUR");
                            }
                            else if(sequencetype == "FNAL") {
                                $("#lastschrifttyp_"+userid).text("FNAL");
                            }
                            else if(sequencetype == "OOFF") {
                                $("#lastschrifttyp_"+userid).text("OOFF");
                            }
                        }
                        else {
                            $("input[type=checkbox]#member_"+userid).prop("checked", false);
                            $("#duedate_"+userid).text("");
                        }
                    }
                    else {
                        checkbox.prop("checked", !member_checked);
                        alert(data);
                        return false;
                    }
                    return true;
                }
            );
        });';

    $page->addJavascript($javascriptCode, true);

    if (isset($_SESSION['pMembershipFee']['duedates_rol_sel'])) {
        $page->addHtml('<strong>' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE_ROLLQUERY_ACTIV') . '</strong>');
    }

    $form = new HtmlForm('duedates_filter_form', '', $page, array(
        'type' => 'navbar',
        'setFocus' => false
    ));

    // Vorschlag: naechster Bankarbeitstag ab heute (kein Wochenende, kein Feiertag)
    $datumtemp = \DateTime::createFromFormat('Y-m-d', mf_next_valid_duedate(DATE_NOW));
    $datum = $datumtemp->format($gSettingsManager->getString('system_date'));
    $form->addInput('datum', $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'), $datum, array(
        'type' => 'date',
        'helpTextId' => 'PLG_MEMBERSHIPFEE_DUEDATE_DESC'
    ));

    $selectBoxEntries = array(
        'AUTO' => $gL10n->get('PLG_MEMBERSHIPFEE_AUTO_DIRECT_DEBIT'),
        'RCUR' => $gL10n->get('PLG_MEMBERSHIPFEE_FOLLOW_DIRECT_DEBIT'),
        'FNAL' => $gL10n->get('PLG_MEMBERSHIPFEE_FINAL_DIRECT_DEBIT'),
        'OOFF' => $gL10n->get('PLG_MEMBERSHIPFEE_ONETIMES_DIRECT_DEBIT'),
        'FRST' => $gL10n->get('PLG_MEMBERSHIPFEE_FIRST_DIRECT_DEBIT')
    );
    $form->addSelectBox('lastschrifttyp', $gL10n->get('PLG_MEMBERSHIPFEE_SEQUENCETYPE'), $selectBoxEntries, array(
        'helpTextId' => 'PLG_MEMBERSHIPFEE_SEQUENCETYPE_SELECT_DESC',
        'defaultValue' => 'AUTO',
        'showContextDependentFirstEntry' => false,
        'firstEntry' => $gL10n->get('PLG_MEMBERSHIPFEE_NOT_CHANGE')
    ));

    $selectBoxEntries = array(
        '0' => $gL10n->get('SYS_SHOW_ALL_CONTACTS'),
        '1' => $gL10n->get('PLG_MEMBERSHIPFEE_WITH_DUEDATE'),
        '2' => $gL10n->get('PLG_MEMBERSHIPFEE_WITHOUT_DUEDATE')
    );
    $form->addSelectBox('mem_show', $gL10n->get('PLG_MEMBERSHIPFEE_FILTER'), $selectBoxEntries, array(
        'defaultValue' => $getMembersShow,
        'helpTextId' => 'PLG_MEMBERSHIPFEE_FILTER_DESC',
        'showContextDependentFirstEntry' => false
    ));

    $page->addHtml($form->show(false));
    $page->addHtml('<div class="mb-3">'
        . '<button type="button" class="btn btn-primary me-2 mb-2 mf-duedate-bulk" data-bulk="set">'
        . '<span class="spinner-border spinner-border-sm d-none me-1" role="status"></span><i class="bi bi-calendar-check"></i> Fälligkeit bei allen angezeigten Mitgliedern setzen</button>'
        . '<button type="button" class="btn btn-outline-secondary mb-2 mf-duedate-bulk" data-bulk="remove">'
        . '<span class="spinner-border spinner-border-sm d-none me-1" role="status"></span><i class="bi bi-calendar-x"></i> Fälligkeit bei allen entfernen</button>'
        . '<div class="form-text">Gesetzt wird das oben gewählte Datum mit dem gewählten Sequenztyp, nur bei Mitgliedern ohne Fälligkeit. '
        . 'Es zählen alle Mitglieder der Liste (Filter oben beachten), nicht nur die der aktuellen Seite.</div>'
        . '</div>');

    // create table object
    $table = new HtmlTable('tbl_duedates', $page, true, true, 'table table-condensed');
    $table->setMessageIfNoRowsFound('SYS_NO_ENTRIES');

    $columnAlign = array(
        'center'
    );
    $columnValues = array(
        '<input type="checkbox" id="change" name="change" class="change_checkbox admidio-icon-help" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE_CHANGE_ALL_DESC') . '"/>'
    );

    // headlines for columns
    foreach ($membersList as $member => $memberData) {
        foreach ($memberData as $usfId => $dummy) {
            if (! is_int($usfId)) {
                continue;
            }

            // Find name of the field
            $columnHeader = $gProfileFields->getPropertyById($usfId, 'usf_name');

            if ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'CHECKBOX' || $gProfileFields->getPropertyById($usfId, 'usf_name_intern') === 'GENDER') {
                $columnAlign[] = 'center';
            } elseif ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'NUMBER' || $gProfileFields->getPropertyById($usfId, 'usf_type') === 'DECIMAL') {
                $columnAlign[] = 'right';
            } else {
                $columnAlign[] = 'left';
            }
            $columnValues[] = $columnHeader;
        } // End-Foreach
        break; // Abbruch nach dem ersten Mitglied, da nur die usfIds eines Mitglieds benoetigt werden um die headlines zu erzeugen
    }

    $table->setColumnAlignByArray($columnAlign);
    $table->addRowHeadingByArray($columnValues);
    $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
    $table->disableDatatablesColumnsSort(array(
        1
    ));

    // usr_uuid aller angezeigten Benutzer in einer Abfrage lesen (statt den Benutzer je Feld neu zu laden)
    $userUuids = array();
    $memberIds = array_map('intval', array_keys($membersList));
    foreach (array_chunk($memberIds, 500) as $chunk) {
        $statement = $gDb->queryPrepared('SELECT usr_id, usr_uuid FROM ' . TBL_USERS . ' WHERE usr_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
        while ($row = $statement->fetch()) {
            $userUuids[(int) $row['usr_id']] = (string) $row['usr_uuid'];
        }
    }
    $usfIdCountry = (int) $gProfileFields->getProperty('COUNTRY', 'usf_id');
    $usfIdLastName = (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id');
    $usfIdFirstName = (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id');
    $usfIdSequenceType = (int) $gProfileFields->getProperty('SEQUENCETYPE' . $gCurrentOrgId, 'usf_id');
    $usfIdDuedate = (int) $gProfileFields->getProperty('DUEDATE' . $gCurrentOrgId, 'usf_id');

    // user data
    foreach ($membersList as $member => $memberData) {
        $memberUuid = $userUuids[(int) $member] ?? '';
        if (isset($memberData[$gProfileFields->getProperty('DUEDATE' . $gCurrentOrgId, 'usf_id')]) && strlen($memberData[$gProfileFields->getProperty('DUEDATE' . $gCurrentOrgId, 'usf_id')]) > 0) {
            $content = '<input type="checkbox" id="member_' . $member . '" name="member_' . $member . '" checked="checked" class="memlist_checkbox memlist_member" /><b id="loadindicator_member_' . $member . '"></b>';
        } else {
            $content = '<input type="checkbox" id="member_' . $member . '" name="member_' . $member . '" class="memlist_checkbox memlist_member" /><b id="loadindicator_member_' . $member . '"></b>';
        }

        $columnValues = array(
            $content
        );

        foreach ($memberData as $usfId => $content) {
            if (! is_int($usfId)) {
                continue;
            }

            /**
             * **************************************************************
             */
            // in some cases the content must have a special output format
            /**
             * **************************************************************
             */
            if ($usfId === $usfIdCountry) {
                $content = $gL10n->getCountryName($content);
            }

            $htmlValue = $gProfileFields->getHtmlValue($gProfileFields->getPropertyById($usfId, 'usf_name_intern'), $content, $memberUuid);

            if ($usfId === $usfIdLastName || $usfId === $usfIdFirstName) {
                $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                    'user_uuid' => $memberUuid
                )) . '">' . $htmlValue . '</a>';
            } elseif ($usfId === $usfIdSequenceType) {
                // leerer Sequenztyp bedeutet FRST (aeltere Eintraege); bei gesetzter Faelligkeit so anzeigen
                if ($htmlValue === '' && isset($memberData[$usfIdDuedate]) && strlen((string) $memberData[$usfIdDuedate]) > 0) {
                    $htmlValue = 'FRST';
                }
                $columnValues[] = '<div class="lastschrifttyp_' . $member . '" id="lastschrifttyp_' . $member . '">' . $htmlValue . '</div>';
            } elseif ($usfId === $usfIdDuedate) {
                $columnValues[] = '<div class="duedate_' . $member . '" id="duedate_' . $member . '">' . $htmlValue . '</div>';
            } else {
                $columnValues[] = $htmlValue;
            }
        }

        $table->addRowByArray($columnValues, 'userid_' . $member, array(
            'nobr' => 'true'
        ));

        $userArray[] = $member;
    } // End-foreach User

    $_SESSION['pMembershipFee']['duedates_user'] = $userArray;

    $page->addHtml($table->show(false));
    $page->addHtml('<p>' . $gL10n->get('SYS_CHECKBOX_AUTOSAVE') . '</p>');

    $page->show();
}
