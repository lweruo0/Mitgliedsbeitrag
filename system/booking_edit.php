<?php
/**
 ***********************************************************************************************
 * Bearbeiten der Buchungshistorie: manuelle Position anlegen/bearbeiten/loeschen,
 * Referenz nachtragen, Position stornieren
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode      : add | edit | delete | reference | cancel      (Formular anzeigen)
 *             save_add | save_edit | save_delete | save_reference | save_cancel (Speichern, POST)
 * user_uuid : Mitglied (mode add)
 * return_run: optional, Einzug, in dessen Einzugsuebersicht nach dem Speichern zurueckgesprungen wird
 * scope     : payments = fuer alle in den Beitragszahlungen markierten Mitglieder (mode add)
 * mfb_id    : Position (alle anderen Modi)
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingService;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Config\ConfigTable;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/bookings_function.php');

    if (! isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'requireValue' => true,
        'validValues' => array('add', 'edit', 'delete', 'reference', 'cancel', 'save_add', 'save_edit', 'save_delete', 'save_reference', 'save_cancel')
    ));
    $getUserUuid = admFuncVariableIsValid($_GET, 'user_uuid', 'uuid');
    $getScope = admFuncVariableIsValid($_GET, 'scope', 'string', array('validValues' => array('', 'payments')));
    $getBookingId = admFuncVariableIsValid($_GET, 'mfb_id', 'int');
    $getReturnRun = admFuncVariableIsValid($_GET, 'return_run', 'int'); // Ruecksprung in die Einzugsuebersicht (Einzug)

    $pPreferences = new ConfigTable();
    $pPreferences->read();
    // Buchungskreis: bei einer vorhandenen Position deren Buchungskreis (siehe BookingService::cancel), sonst Parameter scope
    $bookingService = new BookingService($pPreferences->config, mf_collection_scope());

    $user = new User($gDb, $gProfileFields);

    // Position und Mitglied ermitteln
    $position = null;
    if ($getBookingId > 0) {
        $position = BookingStore::find($getBookingId);
        if ($position === null) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $user->readDataById((int) $position['mfb_usr_id']);
    } elseif ($getUserUuid !== '') {
        $user->readDataByUuid($getUserUuid);
    }

    // Ziel-Mitglieder fuer "Position hinzufuegen"
    $targetUserIds = array();
    if ($getScope === 'payments') {
        $targetUserIds = array_map('intval', array_keys($_SESSION['pMembershipFee']['checkedArray'] ?? array()));
    } elseif ((int) $user->getValue('usr_id') > 0) {
        $targetUserIds = array((int) $user->getValue('usr_id'));
    }

    $returnUrl = ($getScope === 'payments')
        ? ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/payments.php'
        : SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bookings.php', array('user_uuid' => $user->getValue('usr_uuid')));
    if ($getReturnRun > 0) {
        $returnUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/runs.php', array('run_id' => $getReturnRun));
    }

    $selfUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/booking_edit.php';

    // ------------------------------------------------------------------
    // Speichern
    // ------------------------------------------------------------------
    if (strpos($getMode, 'save_') === 0) {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        $message = '';

        switch ($getMode) {
            case 'save_add':
                if (count($targetUserIds) === 0) {
                    throw new Exception('PLG_MEMBERSHIPFEE_NOTHING_SELECTED');
                }
                $label = trim((string) ($_POST['booking_label'] ?? ''));
                $amount = mf_parse_amount((string) ($_POST['booking_amount'] ?? ''));
                if ($label === '' || $amount === null) {
                    throw new Exception('PLG_MEMBERSHIPFEE_BOOKING_AMOUNT_INVALID');
                }
                $text = trim((string) ($_POST['booking_text'] ?? ''));
                $text = ($text === '') ? trim($bookingService->getPrefix() . ' ' . $label) : $text;
                $year = (int) ($_POST['booking_year'] ?? getContributionYear());
                $gDb->startTransaction();
                $count = $bookingService->addManual($targetUserIds, $label, $amount, $text, $year > 0 ? $year : getContributionYear(), trim((string) ($_POST['booking_comment'] ?? '')));
                $gDb->endTransaction();
                $message = $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ADDED', array($count));
                break;

            case 'save_edit':
                if ($position === null || $position['mfb_status'] !== BookingStore::STATUS_OPEN || $position['mfb_source'] !== BookingStore::SOURCE_MANUAL) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                if ((string) ($position['mfb_invoice_no'] ?? '') !== '') {
                    throw new Exception($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ON_INVOICE', array($position['mfb_invoice_no'])));
                }
                $label = trim((string) ($_POST['booking_label'] ?? ''));
                $amount = mf_parse_amount((string) ($_POST['booking_amount'] ?? ''));
                if ($label === '' || $amount === null) {
                    throw new Exception('PLG_MEMBERSHIPFEE_BOOKING_AMOUNT_INVALID');
                }
                BookingStore::update((int) $position['mfb_id'], array(
                    'mfb_rol_name' => $label,
                    'mfb_amount' => $amount,
                    'mfb_text' => trim((string) ($_POST['booking_text'] ?? '')),
                    'mfb_year' => (int) ($_POST['booking_year'] ?? $position['mfb_year']),
                    'mfb_comment' => trim((string) ($_POST['booking_comment'] ?? ''))
                ));
                $bookingService->syncWorkFields((int) $position['mfb_usr_id']);
                break;

            case 'save_delete':
                if ($position === null || $position['mfb_status'] !== BookingStore::STATUS_OPEN || $position['mfb_source'] !== BookingStore::SOURCE_MANUAL) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                if ((string) ($position['mfb_invoice_no'] ?? '') !== '') {
                    throw new Exception($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ON_INVOICE', array($position['mfb_invoice_no'])));
                }
                BookingStore::delete((int) $position['mfb_id']);
                $bookingService->syncWorkFields((int) $position['mfb_usr_id']);
                break;

            case 'save_reference':
                if ($position === null || $position['mfb_status'] !== BookingStore::STATUS_COLLECTED) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                $data = array(
                    'mfb_reference' => trim((string) ($_POST['booking_reference'] ?? '')),
                    'mfb_comment' => trim((string) ($_POST['booking_comment'] ?? ''))
                );
                if ($position['mfb_method'] === BookingStore::METHOD_MANUAL) {
                    $date = mf_parse_date((string) ($_POST['booking_date'] ?? ''));
                    if ($date !== '') {
                        $data['mfb_booking_date'] = $date;
                    }
                }
                BookingStore::update((int) $position['mfb_id'], $data);
                break;

            case 'save_cancel':
                if ($position === null || $position['mfb_status'] === BookingStore::STATUS_CANCELLED) {
                    throw new Exception('SYS_INVALID_PAGE_VIEW');
                }
                $date = mf_parse_date((string) ($_POST['storno_date'] ?? ''));
                if ($date === '') {
                    throw new Exception('SYS_DATE_INVALID', array($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_DATE'), $gSettingsManager->getString('system_date')));
                }
                $reasons = array('Rücklastschrift', 'Korrektur', 'Sonstiges');
                $reason = in_array($_POST['storno_reason'] ?? '', $reasons, true) ? $_POST['storno_reason'] : 'Sonstiges';
                $template = (isset($_POST['storno_template']) && $_POST['storno_template'] !== '') ? (int) $_POST['storno_template'] : null;
                $gDb->startTransaction();
                $newDueDate = '';
                if (isset($_POST['storno_recollect']) && trim((string) ($_POST['storno_duedate'] ?? '')) !== '') {
                    $newDueDate = mf_parse_date((string) $_POST['storno_duedate']);
                    if ($newDueDate === '') {
                        throw new Exception('SYS_DATE_INVALID', array($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_NEW_DUEDATE'), $gSettingsManager->getString('system_date')));
                    }
                }
                // ohne "Alle Positionen dieser Lastschrift": die angehakten Positionen der Lastschrift
                $wholeDebit = isset($_POST['storno_whole_debit']);
                $positionIds = null;
                if (!$wholeDebit && isset($_POST['storno_ids'])) {
                    $positionIds = array_map('intval', (array) $_POST['storno_ids']);
                    if (count($positionIds) === 0) {
                        throw new Exception($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_NONE_SELECTED'));
                    }
                } elseif (!$wholeDebit && isset($_POST['storno_ids_present'])) {
                    throw new Exception($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_NONE_SELECTED'));
                }
                $message = $bookingService->cancel((int) $position['mfb_id'], $date, $reason, trim((string) ($_POST['storno_comment'] ?? '')), false, $template, $wholeDebit,
                    isset($_POST['storno_recollect']), $newDueDate, $positionIds);
                $gDb->endTransaction();
                break;
        }

        if ($message !== '') {
            $_SESSION['pMembershipFee']['booking_message'] = $message;
        }
        admRedirect($returnUrl);
        // => EXIT
    }

    // ------------------------------------------------------------------
    // Formulare
    // ------------------------------------------------------------------
    $headlines = array(
        'add' => 'PLG_MEMBERSHIPFEE_BOOKING_ADD',
        'edit' => 'PLG_MEMBERSHIPFEE_BOOKING_EDIT',
        'delete' => 'PLG_MEMBERSHIPFEE_BOOKING_DELETE',
        'reference' => 'PLG_MEMBERSHIPFEE_BOOKING_REFERENCE_EDIT',
        'cancel' => 'PLG_MEMBERSHIPFEE_BOOKING_CANCEL'
    );
    $headline = $gL10n->get($headlines[$getMode]);
    $gNavigation->addUrl(CURRENT_URL, $headline);

    $page = new HtmlPage('plg-mitgliedsbeitrag-booking-edit', $headline);

    // fuer wen?
    if ($getMode === 'add' && $getScope === 'payments') {
        if (count($targetUserIds) === 0) {
            $page->addHtml('<div class="alert alert-warning" role="alert">' . $gL10n->get('PLG_MEMBERSHIPFEE_NOTHING_SELECTED') . '</div>');
            $page->show();
            exit();
        }
        $names = list_members(array('LAST_NAME', 'FIRST_NAME'), 2, 'AND mem_usr_id IN (' . implode(',', $targetUserIds) . ')');
        $nameList = array();
        foreach ($names as $data) {
            $nameList[] = $data['LAST_NAME'] . ', ' . $data['FIRST_NAME'];
        }
        sort($nameList);
        $page->addHtml('<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ADD_FOR', array(count($targetUserIds))) . '<br/><small>' . SecurityUtils::encodeHTML(implode('; ', $nameList)) . '</small></p>');
    } elseif ((int) $user->getValue('usr_id') > 0) {
        $page->addHtml('<p><strong>' . SecurityUtils::encodeHTML($user->getValue('FIRST_NAME') . ' ' . $user->getValue('LAST_NAME')) . '</strong></p>');
    } else {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }

    if ($position !== null) {
        $page->addHtml('<p>' . SecurityUtils::encodeHTML($position['mfb_rol_name']) . ' &middot; ' . mf_format_amount($position['mfb_amount'])
            . ' &middot; ' . mf_booking_label($position['mfb_status'])
            . ($position['mfb_reference'] ? ' &middot; ' . SecurityUtils::encodeHTML($position['mfb_reference']) : '') . '</p>');
    }

    $urlParams = array('mode' => 'save_' . $getMode);
    if ($getReturnRun > 0) {
        $urlParams['return_run'] = $getReturnRun;
    }
    if ($position !== null) {
        $urlParams['mfb_id'] = $position['mfb_id'];
    } elseif ($getScope === 'payments') {
        $urlParams['scope'] = 'payments';
    } else {
        $urlParams['user_uuid'] = $user->getValue('usr_uuid');
    }
    $form = new HtmlForm('booking_edit_form', SecurityUtils::encodeUrl($selfUrl, $urlParams), $page);

    switch ($getMode) {
        case 'add':
        case 'edit':
            if ($getMode === 'add') {
                $templates = $bookingService->getTemplates();
                if (count($templates) > 0) {
                    $entries = array();
                    foreach ($templates as $index => $template) {
                        $entries[$index] = $template['name'] . ($template['amount'] !== '' ? ' (' . mf_format_amount($template['amount']) . ')' : '');
                    }
                    $form->addSelectBox('booking_template', $gL10n->get('PLG_MEMBERSHIPFEE_MANUAL_TEMPLATE'), $entries, array(
                        'showContextDependentFirstEntry' => true
                    ));
                    $templateJson = array();
                    foreach ($templates as $index => $template) {
                        $templateJson[$index] = array(
                            'name' => $template['name'],
                            'amount' => $template['amount'] !== '' ? number_format((float) $template['amount'], 2, ',', '') : '',
                            'text' => $template['text'] !== '' ? $template['text'] : trim($bookingService->getPrefix() . ' ' . $template['name'])
                        );
                    }
                    $page->addJavascript('
                        var pmbTemplates = ' . json_encode($templateJson) . ';
                        $("#booking_template").change(function() {
                            var t = pmbTemplates[$(this).val()];
                            if (t) {
                                $("#booking_label").val(t.name);
                                $("#booking_amount").val(t.amount);
                                $("#booking_text").val(t.text);
                            }
                        });', true);
                }
            }
            $form->addInput('booking_label', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_LABEL'), $position['mfb_rol_name'] ?? '', array(
                'property' => HtmlForm::FIELD_REQUIRED,
                'maxLength' => 100
            ));
            $form->addInput('booking_amount', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_AMOUNT') . ' ' . $gSettingsManager->getString('system_currency'), $position !== null ? number_format((float) $position['mfb_amount'], 2, ',', '') : '', array(
                'property' => HtmlForm::FIELD_REQUIRED,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_BOOKING_AMOUNT_DESC'
            ));
            $form->addInput('booking_text', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_TEXT'), $position['mfb_text'] ?? '', array(
                'maxLength' => 140,
                'helpTextId' => 'PLG_MEMBERSHIPFEE_BOOKING_TEXT_DESC'
            ));
            $form->addInput('booking_year', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_YEAR'), (string) ($position['mfb_year'] ?? getContributionYear()), array(
                'type' => 'number',
                'minNumber' => 2000,
                'maxNumber' => 9999
            ));
            $form->addMultilineTextInput('booking_comment', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_COMMENT'), $position['mfb_comment'] ?? '', 2);
            $form->addSubmitButton('btn_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));
            break;

        case 'delete':
            $form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_DELETE_CONFIRM'));
            $form->addSubmitButton('btn_delete', $gL10n->get('SYS_DELETE'), array('icon' => 'bi-trash'));
            break;

        case 'reference':
            $form->addInput('booking_reference', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_REFERENCE'), (string) $position['mfb_reference'], array(
                'maxLength' => 35
            ));
            if ($position['mfb_method'] === BookingStore::METHOD_MANUAL) {
                $form->addInput('booking_date', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_DATE'), (string) $position['mfb_booking_date'], array(
                    'type' => 'date'
                ));
            }
            $form->addMultilineTextInput('booking_comment', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_COMMENT'), (string) $position['mfb_comment'], 2);
            $form->addSubmitButton('btn_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));
            break;

        case 'cancel':
            $form->addInput('storno_date', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_DATE'), DATE_NOW, array(
                'type' => 'date',
                'property' => HtmlForm::FIELD_REQUIRED
            ));
            $form->addSelectBox('storno_reason', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_REASON'), array(
                'Rücklastschrift' => $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_REASON_RETURN'),
                'Korrektur' => $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_REASON_CORRECTION'),
                'Sonstiges' => $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_REASON_OTHER')
            ), array(
                'defaultValue' => 'Rücklastschrift',
                'showContextDependentFirstEntry' => false
            ));
            $form->addMultilineTextInput('storno_comment', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_COMMENT'), '', 2, array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_BOOKING_CANCEL_COMMENT_DESC'
            ));
            $debitPositions = $bookingService->debitPositions($position);
            if (count($debitPositions) > 1) {
                $form->addCheckbox('storno_whole_debit', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_WHOLE_DEBIT'), true, array(
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_BOOKING_CANCEL_WHOLE_DEBIT_HELP'
                ));
                // Auswahl der Positionen dieser Lastschrift (wirksam, wenn "Alle Positionen" abgewaehlt ist)
                $items = '<input type="hidden" name="storno_ids_present" value="1" />';
                foreach ($debitPositions as $item) {
                    $id = (int) $item['mfb_id'];
                    $items .= '<div class="form-check"><input class="form-check-input storno-id" type="checkbox" name="storno_ids[]" id="storno_id_' . $id . '" value="' . $id . '" checked="checked" />'
                        . '<label class="form-check-label" for="storno_id_' . $id . '">' . SecurityUtils::encodeHTML((string) $item['mfb_rol_name'])
                        . ' &ndash; ' . mf_format_amount($item['mfb_amount'])
                        . (!in_array((string) $item['mfb_text'], array('', (string) $item['mfb_rol_name']), true) ? ' <small class="text-muted">' . SecurityUtils::encodeHTML((string) $item['mfb_text']) . '</small>' : '')
                        . '</label></div>';
                }
                $form->addCustomContent($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_POSITIONS'), '<div id="storno_positions">' . $items . '</div>');
                $page->addJavascript('
                    var stornoMainId = ' . (int) $position['mfb_id'] . ';
                    function stornoPositionsToggle(initial) {
                        var all = $("#storno_whole_debit").prop("checked");
                        $("#storno_positions input.storno-id").prop("disabled", all);
                        if (all) {
                            $("#storno_positions input.storno-id").prop("checked", true);
                        } else if (!initial) {
                            // beim Abwaehlen zunaechst nur die Position, ueber die storniert wird
                            $("#storno_positions input.storno-id").each(function() { $(this).prop("checked", parseInt($(this).val(), 10) === stornoMainId); });
                        }
                    }
                    $("#storno_whole_debit").change(function() { stornoPositionsToggle(false); });
                    stornoPositionsToggle(true);', true);
            }
            $form->addCheckbox('storno_recollect', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_RECOLLECT'), true, array(
                'helpTextId' => 'PLG_MEMBERSHIPFEE_BOOKING_CANCEL_RECOLLECT_DESC'
            ));
            $form->addInput('storno_duedate', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_NEW_DUEDATE'), '', array(
                'type' => 'date',
                'helpTextId' => 'PLG_MEMBERSHIPFEE_BOOKING_CANCEL_NEW_DUEDATE_DESC'
            ));
            // "Erneut einziehen" passt zur Ruecklastschrift; bei Korrektur/Sonstiges abwaehlen
            $page->addJavascript('
                function stornoRecollectToggle() {
                    var on = $("#storno_recollect").prop("checked");
                    $("#storno_duedate").prop("disabled", !on); $("#storno_duedate_group").toggle(on);
                }
                $("#storno_reason").change(function() {
                    $("#storno_recollect").prop("checked", $(this).val() === "Rücklastschrift");
                    stornoRecollectToggle();
                });
                $("#storno_recollect").change(stornoRecollectToggle);
                stornoRecollectToggle();', true);
            $templates = $bookingService->getTemplates();
            if (count($templates) > 0) {
                $entries = array();
                $preselect = '';
                foreach ($templates as $index => $template) {
                    $entries[$index] = $template['name'] . ($template['amount'] !== '' ? ' (' . mf_format_amount($template['amount']) . ')' : '');
                }
                $form->addSelectBox('storno_template', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL_TEMPLATE'), $entries, array(
                    'defaultValue' => $preselect,
                    'showContextDependentFirstEntry' => true,
                    'helpTextId' => 'PLG_MEMBERSHIPFEE_BOOKING_CANCEL_TEMPLATE_DESC'
                ));
            }
            $form->addSubmitButton('btn_cancel', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL'), array('icon' => 'bi-x-octagon'));
            break;
    }

    $page->addHtml($form->show(false));
    $page->show();
} catch (Exception $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    $gMessage->show($e->getMessage());
}
