<?php
/**
 ***********************************************************************************************
 * Arbeitsdienst erfassen: Einsaetze mit den geleisteten Stunden je Mitglied
 *
 * Aufrufen duerfen die Zugriffsrolle des Plugins und alle Rollen, die unter "Sichtbar für" beim
 * Menuepunkt "Arbeitsdienst erfassen" (system/work_input.php) eingetragen sind. Betraege, IBANs und
 * Buchungen werden hier nicht angezeigt. Ist ein Leistungsjahr schon abgerechnet, kann nur noch die
 * Zugriffsrolle des Plugins Stunden aendern (mit Hinweis auf die Gutschrift).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode   : show (Standard) | edit (Formular fuer einen Eintrag)
 *          save (POST: Einsatz fuer mehrere Mitglieder) | update (POST) | delete (POST)
 * year   : Leistungsjahr (Standard: aktuelles Jahr)
 * mfw_id : Eintrag (edit, update, delete)
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Work\WorkHoursStore;
use Plugins\MembershipFee\classes\Work\WorkRules;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/common_function.php');

    if (!mf_is_work_input_authorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }
    // Zugriffsrolle des Plugins: darf auch abgerechnete Jahre aendern
    $isPluginUser = isUserAuthorized();

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'show',
        'validValues' => array('show', 'edit', 'save', 'update', 'delete')
    ));
    $getYear = admFuncVariableIsValid($_GET, 'year', 'int', array('defaultValue' => (int) date('Y')));
    if ($getYear < 2000 || $getYear > 2100) {
        $getYear = (int) date('Y');
    }
    $getId = admFuncVariableIsValid($_GET, 'mfw_id', 'int');

    $selfUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/work_input.php';
    WorkHoursStore::ensureTable();

    /**
     * Darf das Leistungsjahr geaendert werden? Sonst Exception mit Hinweis.
     */
    $assertYearEditable = static function (int $year) use ($isPluginUser): void {
        if (!$isPluginUser && mf_work_year_locked($year)) {
            throw new Exception('PLG_MEMBERSHIPFEE_WORK_YEAR_LOCKED', array($year));
        }
    };

    // ------------------------------------------------------------------
    // Speichern
    // ------------------------------------------------------------------
    if (in_array($getMode, array('save', 'update', 'delete'), true)) {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        $messages = array();

        if ($getMode === 'save') {
            $date = mf_parse_date((string) ($_POST['work_date'] ?? ''));
            if ($date === '') {
                throw new Exception('SYS_DATE_INVALID', array($gL10n->get('SYS_DATE'), $gSettingsManager->getString('system_date')));
            }
            $activity = trim((string) ($_POST['work_activity'] ?? ''));
            if ($activity === '') {
                throw new Exception('SYS_FIELD_EMPTY', array($gL10n->get('PLG_MEMBERSHIPFEE_WORK_ACTIVITY')));
            }
            $hoursByUser = (array) ($_POST['work_hours'] ?? array());
            if (count($hoursByUser) === 0) {
                throw new Exception('PLG_MEMBERSHIPFEE_WORK_NO_MEMBERS');
            }
            $assertYearEditable((int) substr($date, 0, 4));
            $members = mf_work_members_of_year((int) substr($date, 0, 4));

            $gDb->startTransaction();
            $count = 0;
            foreach ($hoursByUser as $usrId => $hours) {
                $usrId = (int) $usrId;
                if (!isset($members[$usrId])) {
                    continue;
                }
                $hours = trim(str_replace(',', '.', (string) $hours));
                if ($hours === '' || (float) $hours <= 0) {
                    continue;
                }
                WorkHoursStore::insert(array(
                    'mfw_usr_id' => $usrId,
                    'mfw_date' => $date,
                    'mfw_hours' => $hours,
                    'mfw_activity' => $activity,
                    'mfw_comment' => (string) ($_POST['work_comment'] ?? '')
                ));
                $count++;
            }
            $gDb->endTransaction();
            $messages[] = $gL10n->get('PLG_MEMBERSHIPFEE_WORK_SAVED', array($count, $activity));
            $getYear = (int) substr($date, 0, 4);
        } else {
            $entry = WorkHoursStore::find($getId);
            if ($entry === null) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
            $assertYearEditable((int) $entry['mfw_year']);

            if ($getMode === 'update') {
                $date = mf_parse_date((string) ($_POST['work_date'] ?? ''));
                if ($date === '') {
                    throw new Exception('SYS_DATE_INVALID', array($gL10n->get('SYS_DATE'), $gSettingsManager->getString('system_date')));
                }
                $assertYearEditable((int) substr($date, 0, 4));
                WorkHoursStore::update($getId, array(
                    'mfw_date' => $date,
                    'mfw_hours' => (string) ($_POST['work_hours_single'] ?? ''),
                    'mfw_activity' => (string) ($_POST['work_activity'] ?? ''),
                    'mfw_comment' => (string) ($_POST['work_comment'] ?? '')
                ));
                $getYear = (int) substr($date, 0, 4);
            } else {
                WorkHoursStore::delete($getId);
                $getYear = (int) $entry['mfw_year'];
            }
            $messages[] = $gL10n->get('SYS_SAVE_DATA');
        }

        if ($isPluginUser && mf_work_year_locked($getYear)) {
            $messages[] = $gL10n->get('PLG_MEMBERSHIPFEE_WORK_YEAR_LOCKED_ADMIN', array($getYear));
        }
        $_SESSION['pMembershipFee']['work_message'] = implode(' ', $messages);
        admRedirect(SecurityUtils::encodeUrl($selfUrl, array('year' => $getYear)));
        // => EXIT
    }

    // ------------------------------------------------------------------
    // Anzeige
    // ------------------------------------------------------------------
    $headline = mf_page_headline($gL10n->get('PLG_MEMBERSHIPFEE_WORK_INPUT'));
    $gNavigation->addUrl(CURRENT_URL, $headline);
    $page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-work-input', $headline);
    $page->setContentFullWidth();

    if (!empty($_SESSION['pMembershipFee']['work_message'])) {
        $page->addHtml('<div class="alert alert-success" role="alert"><i class="bi bi-check-lg"></i> ' . SecurityUtils::encodeHTML($_SESSION['pMembershipFee']['work_message']) . '</div>');
        unset($_SESSION['pMembershipFee']['work_message']);
    }

    // einzelnen Eintrag bearbeiten
    if ($getMode === 'edit') {
        $entry = WorkHoursStore::find($getId);
        if ($entry === null) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $assertYearEditable((int) $entry['mfw_year']);
        $members = mf_work_members_of_year((int) $entry['mfw_year']);

        $page->addHtml('<p><strong>' . SecurityUtils::encodeHTML($members[(int) $entry['mfw_usr_id']]['name'] ?? ('#' . $entry['mfw_usr_id'])) . '</strong></p>');
        $form = new HtmlForm('work_edit_form', SecurityUtils::encodeUrl($selfUrl, array('mode' => 'update', 'mfw_id' => $getId)), $page);
        $form->addInput('work_date', $gL10n->get('SYS_DATE'), (string) $entry['mfw_date'], array(
            'type' => 'date',
            'property' => HtmlForm::FIELD_REQUIRED
        ));
        $form->addInput('work_activity', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_ACTIVITY'), (string) $entry['mfw_activity'], array(
            'property' => HtmlForm::FIELD_REQUIRED,
            'maxLength' => 255
        ));
        $form->addInput('work_hours_single', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_HOURS'), number_format((float) $entry['mfw_hours'], 2, ',', ''), array(
            'property' => HtmlForm::FIELD_REQUIRED
        ));
        $form->addMultilineTextInput('work_comment', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_COMMENT'), (string) ($entry['mfw_comment'] ?? ''), 2);
        $form->addSubmitButton('btn_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));
        $page->addHtml($form->show(false));
        $page->show();
        exit();
    }

    // Leistungsjahr waehlen
    $years = WorkHoursStore::getYears();
    foreach (array((int) date('Y'), (int) date('Y') - 1, $getYear) as $year) {
        if (!in_array($year, $years, true)) {
            $years[] = $year;
        }
    }
    rsort($years);
    $page->addHtml(mf_year_switch_html($getYear, $years, $selfUrl));

    $locked = mf_work_year_locked($getYear);
    if ($locked) {
        $page->addHtml('<div class="alert alert-warning" role="alert"><i class="bi bi-lock"></i> '
            . $gL10n->get($isPluginUser ? 'PLG_MEMBERSHIPFEE_WORK_YEAR_LOCKED_ADMIN' : 'PLG_MEMBERSHIPFEE_WORK_YEAR_LOCKED', array($getYear)) . '</div>');
    }

    $entries = WorkHoursStore::getByYear($getYear);
    $members = mf_work_members_of_year($getYear);

    // Einsatz erfassen (nicht in gesperrten Jahren ohne Plugin-Recht)
    if (!$locked || $isPluginUser) {
        $activities = array();
        foreach ($entries as $entry) {
            $activities[(string) $entry['mfw_activity']] = true;
        }
        $memberEntries = array();
        foreach ($members as $usrId => $member) {
            $memberEntries[$usrId] = $member['name'] . ($member['birthday'] !== '' ? ' (' . substr($member['birthday'], 0, 4) . ')' : '');
        }
        $defaultDate = ((int) date('Y') === $getYear) ? DATE_NOW : sprintf('%04d-12-31', $getYear);

        $page->addHtml('<h5 class="mt-3">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_NEW') . '</h5>');
        $form = new HtmlForm('work_input_form', SecurityUtils::encodeUrl($selfUrl, array('mode' => 'save')), $page);
        $form->addInput('work_date', $gL10n->get('SYS_DATE'), $defaultDate, array(
            'type' => 'date',
            'property' => HtmlForm::FIELD_REQUIRED
        ));
        $form->addInput('work_activity', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_ACTIVITY'), '', array(
            'property' => HtmlForm::FIELD_REQUIRED,
            'maxLength' => 255,
            'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_ACTIVITY_DESC'
        ));
        $form->addInput('work_default_hours', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_DEFAULT_HOURS'), '', array(
            'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_DEFAULT_HOURS_DESC'
        ));
        $form->addSelectBox('work_members', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_MEMBERS'), $memberEntries, array(
            'multiselect' => true,
            'showContextDependentFirstEntry' => false,
            'helpTextId' => 'PLG_MEMBERSHIPFEE_WORK_MEMBERS_DESC'
        ));
        $form->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_WORK_HOURS'), '<div id="work_rows"><span class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_NO_MEMBERS') . '</span></div>');
        $form->addMultilineTextInput('work_comment', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_COMMENT'), '', 2);
        $form->addSubmitButton('btn_save_work', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));
        $page->addHtml($form->show(false));

        // Vorschlaege fuer den Einsatz aus dem Jahr
        $page->addHtml('<datalist id="work_activity_list">' . implode('', array_map(static fn (string $activity): string => '<option value="' . SecurityUtils::encodeHTML($activity) . '"></option>', array_keys($activities))) . '</datalist>');

        // je ausgewaehltem Mitglied eine Zeile mit den Stunden (Vorgabe aus "Stunden fuer alle")
        $page->addJavascript('
            $("#work_activity").attr("list", "work_activity_list");
            var workHours = {};
            function renderWorkRows() {
                var selected = $("#work_members").val() || [];
                var defaultHours = $("#work_default_hours").val();
                $("#work_rows input.work-hours").each(function() {
                    workHours[$(this).data("usr")] = $(this).val();
                });
                if (selected.length === 0) {
                    $("#work_rows").html(' . json_encode('<span class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_NO_MEMBERS') . '</span>') . ');
                    return;
                }
                var html = "<table class=\"table table-sm\" style=\"max-width: 32rem;\"><tbody>";
                selected.forEach(function(usrId) {
                    var name = $("#work_members option[value=\"" + usrId + "\"]").text();
                    var value = (workHours[usrId] !== undefined && workHours[usrId] !== "") ? workHours[usrId] : defaultHours;
                    html += "<tr><td>" + $("<div>").text(name).html() + "</td><td style=\"width: 8rem;\">"
                        + "<input type=\"text\" inputmode=\"decimal\" class=\"form-control form-control-sm work-hours\" required"
                        + " name=\"work_hours[" + usrId + "]\" data-usr=\"" + usrId + "\" value=\"" + $("<div>").text(value || "").html() + "\" /></td></tr>";
                });
                html += "</tbody></table>";
                $("#work_rows").html(html);
            }
            $("#work_members").on("change", renderWorkRows);
            $("#work_default_hours").on("change", function() {
                var value = $(this).val();
                $("#work_rows input.work-hours").each(function() {
                    if ($(this).val() === "") {
                        $(this).val(value);
                    }
                });
            });', true);
    }

    // erfasste Einsaetze des Jahres, gruppiert nach Datum und Einsatz (Teilnehmer erst beim Aufklappen)
    $entriesHeading = '<h4 class="mb-0 me-auto"><i class="bi bi-list-check"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_ENTRIES', array($getYear)) . '</h4>';
    if (count($entries) === 0) {
        $page->addHtml('<div class="card mt-4 mb-3"><div class="card-body">' . $entriesHeading . '<p class="mt-2 mb-0">' . $gL10n->get('SYS_NO_ENTRIES') . '</p></div></div>');
    } else {
        $groups = array();
        $sumHours = 0.0;
        $sumMembers = array();
        foreach ($entries as $entry) {
            $key = (string) $entry['mfw_date'] . "\n" . (string) $entry['mfw_activity'];
            $groups[$key][] = $entry;
            $sumHours += (float) $entry['mfw_hours'];
            $sumMembers[(int) $entry['mfw_usr_id']] = true;
        }
        krsort($groups);    // neueste Einsaetze zuerst

        $canEdit = !$locked || $isPluginUser;
        $csrfToken = $gCurrentSession->getCsrfToken();
        // Ueberschrift mit Kennzahlen des Jahres
        $stat = static fn (string $value, string $label): string => '<div class="text-center px-2"><div class="fs-4 fw-bold lh-1">' . $value . '</div><div class="small text-muted">' . $label . '</div></div>';
        $page->addHtml('<div class="card mt-4 mb-2 border-primary"><div class="card-body d-flex flex-wrap align-items-center gap-3">'
            . $entriesHeading
            . $stat((string) count($groups), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_ASSIGNMENTS'))
            . $stat((string) count($sumMembers), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_MEMBERS'))
            . $stat(substr(WorkRules::formatHours($sumHours), 0, -2), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_HOURS'))
            . '<a href="#" class="btn btn-sm btn-secondary" id="work_groups_toggle">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_EXPAND_ALL') . '</a>'
            . '</div></div>');

        $html = '<div class="accordion" id="work_groups">';
        $index = 0;
        foreach ($groups as $groupEntries) {
            $index++;
            $first = $groupEntries[0];
            $groupHours = array_sum(array_map(static fn (array $entry): float => (float) $entry['mfw_hours'], $groupEntries));
            usort($groupEntries, static function (array $a, array $b) use ($members): int {
                return strcasecmp($members[(int) $a['mfw_usr_id']]['name'] ?? '', $members[(int) $b['mfw_usr_id']]['name'] ?? '');
            });

            $rows = '';
            foreach ($groupEntries as $entry) {
                $actions = '';
                if ($canEdit) {
                    $actions = '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'edit', 'mfw_id' => $entry['mfw_id'])) . '">'
                        . '<i class="bi bi-pencil-square" title="' . $gL10n->get('SYS_EDIT') . '"></i></a> '
                        // Loeschen als Symbol-Link wie "Bearbeiten" (ein Button wuerde vom Theme weiss eingefaerbt); sendet das versteckte Formular
                        . '<a class="admidio-icon-link work-delete" href="#" data-form="work_delete_' . (int) $entry['mfw_id'] . '"><i class="bi bi-trash" title="' . $gL10n->get('SYS_DELETE') . '"></i></a>'
                        . '<form method="post" class="d-none" id="work_delete_' . (int) $entry['mfw_id'] . '" action="' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'delete', 'mfw_id' => $entry['mfw_id'])) . '">'
                        . '<input type="hidden" name="adm_csrf_token" value="' . SecurityUtils::encodeHTML($csrfToken) . '" /></form>';
                }
                $rows .= '<tr>'
                    . '<td>' . SecurityUtils::encodeHTML($members[(int) $entry['mfw_usr_id']]['name'] ?? ('#' . $entry['mfw_usr_id'])) . '</td>'
                    . '<td style="text-align: right; white-space: nowrap;">' . WorkRules::formatHours((float) $entry['mfw_hours']) . '</td>'
                    . '<td>' . SecurityUtils::encodeHTML((string) ($entry['mfw_comment'] ?? '')) . '</td>'
                    . '<td style="white-space: nowrap; text-align: center;">' . $actions . '</td>'
                    . '</tr>';
            }

            $html .= '<div class="accordion-item">'
                . '<h2 class="accordion-header" id="work_group_head_' . $index . '">'
                . '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#work_group_' . $index . '" aria-expanded="false" aria-controls="work_group_' . $index . '">'
                . '<span class="me-3" style="min-width: 6.5rem;">' . mf_format_date((string) $first['mfw_date']) . '</span>'
                . '<strong class="me-3">' . SecurityUtils::encodeHTML((string) $first['mfw_activity']) . '</strong>'
                . '<span class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_GROUP_INFO', array(count($groupEntries), WorkRules::formatHours($groupHours))) . '</span>'
                . '</button></h2>'
                . '<div id="work_group_' . $index . '" class="accordion-collapse collapse" aria-labelledby="work_group_head_' . $index . '">'
                . '<div class="accordion-body p-2"><table class="table table-sm table-hover mb-0"><thead><tr>'
                . '<th>' . $gL10n->get('SYS_NAME') . '</th>'
                . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_HOURS') . '</th>'
                . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_COMMENT') . '</th>'
                . '<th></th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>'
                . '</div>';
        }
        $html .= '</div>';
        $page->addHtml($html);

        // alle Gruppen auf- bzw. zuklappen; Loeschen mit Rueckfrage
        $page->addJavascript('
            $("#work_groups").on("click", "a.work-delete", function(e) {
                e.preventDefault();
                if (confirm(' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_WORK_DELETE_CONFIRM')) . ')) {
                    document.getElementById($(this).data("form")).submit();
                }
            });
            $("#work_groups_toggle").on("click", function(e) {
                e.preventDefault();
                var open = $(this).data("open") === true;
                $("#work_groups .accordion-collapse").each(function() {
                    bootstrap.Collapse.getOrCreateInstance(this, {toggle: false})[open ? "hide" : "show"]();
                });
                $(this).data("open", !open).text(open ? ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_WORK_EXPAND_ALL')) . ' : ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_WORK_COLLAPSE_ALL')) . ');
            });', true);
    }

    $page->show();
} catch (Exception $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    $gMessage->show($e->getMessage());
} catch (\InvalidArgumentException $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    $gMessage->show($e->getMessage());
}
