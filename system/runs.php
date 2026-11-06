<?php
/**
 ***********************************************************************************************
 * Einzugsuebersicht: alle erzeugten SEPA-Dateien; eine Datei als eingezogen markieren oder verwerfen
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * run_id : Einzug (ohne: Liste aller Einzuege)
 * mode   : show (Default) | collect (POST) | discard (POST) | download | share | share_send (POST) | share_revoke (POST)
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Email;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\StringUtils;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Bookings\BookingService;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;
use Plugins\MembershipFee\classes\Config\ConfigTable;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/bookings_function.php');

    if (! isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getRunId = admFuncVariableIsValid($_GET, 'run_id', 'int');
    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'show', 'validValues' => array('show', 'collect', 'discard', 'download', 'share', 'share_send', 'share_revoke')));

    $pPreferences = new ConfigTable();
    $pPreferences->read();
    $bookingService = new BookingService($pPreferences->config);

    $selfUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/runs.php';

    $run = null;
    if ($getRunId > 0) {
        $run = BookingStore::findRun($getRunId);
        if ($run === null) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        // Ablaeufe (eingezogen markieren, verwerfen) im Buchungskreis des Einzugs
        $bookingService = $bookingService->withScope(CollectionScope::fromKey((string) ($run['mfr_kind'] ?? ''), (int) $gCurrentOrgId));
    }

    // ------------------------------------------------------------------
    // Aktionen
    // ------------------------------------------------------------------
    if ($getMode === 'collect' && $run !== null) {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        $date = mf_parse_date((string) ($_POST['collect_date'] ?? ''));
        if ($date === '') {
            throw new Exception('SYS_DATE_INVALID', array($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_DATE'), $gSettingsManager->getString('system_date')));
        }
        $usrIds = array_map('intval', (array) ($_POST['collect_user'] ?? array()));
        $gDb->startTransaction();
        $count = $bookingService->markRunCollected($getRunId, $usrIds, $date);
        $gDb->endTransaction();
        $_SESSION['pMembershipFee']['booking_message'] = $gL10n->get('PLG_MEMBERSHIPFEE_RUN_COLLECTED_DONE', array($count));
        admRedirect(SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId)));
        // => EXIT
    }
    if ($getMode === 'discard' && $run !== null) {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        $gDb->startTransaction();
        $bookingService->discardRun($getRunId);
        $gDb->endTransaction();
        $_SESSION['pMembershipFee']['booking_message'] = $gL10n->get('PLG_MEMBERSHIPFEE_RUN_DISCARDED_DONE');
        admRedirect($selfUrl);
        // => EXIT
    }

    if ($getMode === 'download' && $run !== null) {
        $kind = (($_GET['file'] ?? '') === BookingStore::FILE_CONTROL) ? BookingStore::FILE_CONTROL : BookingStore::FILE_XML;
        $file = BookingStore::getRunFile($getRunId, $kind);
        if ($file === null) {
            throw new Exception('PLG_MEMBERSHIPFEE_RUN_FILE_MISSING');
        }
        mf_send_stored_file($file);
        // => EXIT
    }
    if ($getMode === 'share_revoke' && $run !== null) {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        BookingStore::revokeShare($getRunId);
        $_SESSION['pMembershipFee']['booking_message'] = $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_REVOKED');
        admRedirect(SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId)));
        // => EXIT
    }
    if ($getMode === 'share_send' && $run !== null) {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        if (BookingStore::findRunFileMeta($getRunId) === null) {
            throw new Exception('PLG_MEMBERSHIPFEE_RUN_FILE_MISSING');
        }
        $recipients = array();
        foreach (preg_split('/[\s,;]+/', (string) ($_POST['share_email'] ?? '')) as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            if (!StringUtils::strValidCharacters($address, 'email')) {
                throw new Exception('SYS_EMAIL_INVALID', array($gL10n->get('PLG_MEMBERSHIPFEE_SHARE_RECIPIENTS')));
            }
            $recipients[] = $address;
        }
        $days = (int) ($_POST['share_days'] ?? 0);
        if ($days < 1 || $days > 365) {
            $days = $bookingService->getShareSettings()['days'];
        }
        $share = $bookingService->createShare($getRunId, implode(', ', $recipients), $days);

        $result = '';
        if (count($recipients) > 0) {
            $senderEmail = (string) $gCurrentUser->getValue('EMAIL');
            $senderName = trim($gCurrentUser->getValue('FIRST_NAME') . ' ' . $gCurrentUser->getValue('LAST_NAME'));
            if ($senderEmail === '') {
                $senderEmail = (string) $gCurrentOrganization->getValue('org_email_administrator');
                $senderName = (string) $gCurrentOrganization->getValue('org_longname');
            }
            $links = mf_language_plain_text('PLG_MEMBERSHIPFEE_SHARE_LINK_XML') . ":\n" . $share['url'];
            if ($share['url_control'] !== '') {
                $links .= "\n\n" . mf_language_plain_text('PLG_MEMBERSHIPFEE_SHARE_LINK_CONTROL') . ":\n" . $share['url_control'];
            }
            $body = trim((string) ($_POST['share_message'] ?? ''));
            $body .= ($body !== '' ? "\n\n" : '')
                . mf_language_plain_text('PLG_MEMBERSHIPFEE_SHARE_MAIL_BODY', array(
                    (string) $run['mfr_file_name'],
                    (string) $run['mfr_due_dates'],
                    (int) $run['mfr_count'],
                    mf_format_amount($run['mfr_amount']),
                    $links,
                    mf_format_datetime($share['expires'])
                ));

            $email = new Email();
            $senderResult = $email->setSender($senderEmail, $senderName);
            if ($senderResult !== true) {
                throw new Exception($senderResult);
            }
            // Testbetrieb: an den Testempfaenger statt an die eingetragenen Empfaenger
            $testPrefix = mf_test_email() !== '' ? mf_mail_target(implode(', ', $recipients), '')['subject_prefix'] : '';
            $email->setSubject($testPrefix . mf_language_plain_text('PLG_MEMBERSHIPFEE_SHARE_MAIL_SUBJECT', array((string) $gCurrentOrganization->getValue('org_shortname'), (string) $run['mfr_file_name'])));
            if ($testPrefix !== '') {
                $email->addRecipient(mf_test_email());
            } else {
                foreach ($recipients as $address) {
                    $email->addRecipient($address);
                }
            }
            $email->setText($body);
            $sendResult = $email->sendEmail();
            if ($sendResult !== true) {
                // der Link bleibt gueltig und kann von Hand weitergegeben werden
                $result = $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_MAIL_FAILED', array(trim(html_entity_decode(strip_tags((string) $sendResult), ENT_QUOTES, 'UTF-8'))));
            } else {
                $result = $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_MAIL_SENT', array(implode(', ', $recipients)));
            }
        }
        // Der Link wird nur dieses eine Mal angezeigt (gespeichert ist nur sein Hash)
        $_SESSION['pMembershipFee']['share_link'] = array('run_id' => $getRunId, 'url' => $share['url'], 'url_control' => $share['url_control'], 'expires' => $share['expires'], 'message' => $result);
        admRedirect(SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId)));
        // => EXIT
    }
    if ($getMode === 'share' && $run !== null) {
        if (BookingStore::findRunFileMeta($getRunId) === null) {
            throw new Exception('PLG_MEMBERSHIPFEE_RUN_FILE_MISSING');
        }
        $shareSettings = $bookingService->getShareSettings();
        $headline = $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_FILE');
        $gNavigation->addUrl(CURRENT_URL, $headline);
        $page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-run-share', $headline . ' - ' . $run['mfr_file_name']);

        $form = new HtmlForm('run_share_form', SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId, 'mode' => 'share_send')), $page);
        $form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_SHARE_DESC'));
        $form->addInput('share_email', $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_RECIPIENTS'), implode(', ', $shareSettings['emails']), array(
            'helpTextId' => 'PLG_MEMBERSHIPFEE_SHARE_RECIPIENTS_DESC'
        ));
        $form->addInput('share_days', $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_DAYS'), (string) $shareSettings['days'], array(
            'type' => 'number',
            'minNumber' => 1,
            'maxNumber' => 365,
            'step' => 1,
            'property' => HtmlForm::FIELD_REQUIRED
        ));
        $form->addMultilineTextInput('share_message', $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_MESSAGE'), '', 4);
        $form->addSubmitButton('btn_share_send', $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_SEND'), array('icon' => 'bi-send', 'class' => 'btn-primary'));
        $page->addHtml($form->show(false));
        $page->show();
        exit();
    }

    // ------------------------------------------------------------------
    // Anzeige
    // ------------------------------------------------------------------
    // Liste: Titel wie im Menue (3.0 Lastschrifteinzug); einzelner Einzug: Einzugsuebersicht der Datei
    $headline = $run !== null
        ? $gL10n->get('PLG_MEMBERSHIPFEE_RUN_OPEN') . ' - ' . $run['mfr_file_name']
        : mf_page_headline($gL10n->get('PLG_MEMBERSHIPFEE_RUNS'));
    $gNavigation->addUrl(CURRENT_URL, $headline);

    $page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-runs', $headline);
    $page->setContentFullWidth();

    if (isset($_SESSION['pMembershipFee']['booking_message'])) {
        $page->addHtml('<div class="alert alert-info" role="alert">' . SecurityUtils::encodeHTML($_SESSION['pMembershipFee']['booking_message']) . '</div>');
        unset($_SESSION['pMembershipFee']['booking_message']);
    }

    if ($run === null) {
        // SEPA-Dateien erzeugen (frueher eigener Menuepunkt 3.0): je Buchungskreis mit offenen Lastschriften;
        // Mitgliedsbeitrag immer (sonst Hinweis), Arbeitsdienst nur mit Faelligkeiten
        if (!isset($pPreferences)) {
            $pPreferences = new \Plugins\MembershipFee\classes\Config\ConfigTable();
            $pPreferences->read();
        }
        foreach (array(CollectionScope::membership((int) $gCurrentOrgId), CollectionScope::work((int) $gCurrentOrgId)) as $sepaScope) {
            if (!$sepaScope->isMembership() && count(mf_sepa_duedate_overview($sepaScope)) === 0) {
                continue;
            }
            $page->addHtml('<div class="card mb-4"><div class="card-header"><i class="bi bi-filetype-xml"></i> '
                . $gL10n->get('PLG_MEMBERSHIPFEE_SEPA_CREATE_FILE') . ' &ndash; ' . $gL10n->get($sepaScope->isMembership() ? 'PLG_MEMBERSHIPFEE_SCOPE_MF' : 'PLG_MEMBERSHIPFEE_SCOPE_AD') . ' ' . SecurityUtils::encodeHTML(mf_collection_year_label($sepaScope)) . '</div>'
                . '<div class="card-body">' . mf_sepa_create_block($page, $sepaScope) . '</div></div>');
        }
        $page->addHtml('<h4 class="mt-2">' . $gL10n->get('PLG_MEMBERSHIPFEE_RUNS') . '</h4>');

        // Liste aller Einzuege
        $runs = BookingStore::getRuns();
        if (count($runs) === 0) {
            $page->addHtml('<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_RUNS_NONE') . '</p>');
        } else {
            $runFiles = BookingStore::getRunFilesMeta();
            // Spalte "Art" nur, wenn es Einzuege aus mehr als einem Buchungskreis gibt
            $runKinds = array();
            foreach ($runs as $row) {
                $runKinds[CollectionScope::fromKey((string) ($row['mfr_kind'] ?? ''), (int) $gCurrentOrgId)->key()] = true;
            }
            $showKind = count($runKinds) > 1;
            $table = new HtmlTable('table_runs', $page, true, false, 'table table-condensed');
            $table->setColumnAlignByArray(array_merge(array('left'), $showKind ? array('left') : array(), array('left', 'left', 'right', 'right', 'left', 'left')));
            $table->addRowHeadingByArray(array_merge(array(
                $gL10n->get('PLG_MEMBERSHIPFEE_RUN_CREATED_AT')), $showKind ? array($gL10n->get('PLG_MEMBERSHIPFEE_RUN_KIND')) : array(), array(
                $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_SEPA_FILE'),
                $gL10n->get('PLG_MEMBERSHIPFEE_RUN_DUEDATES'),
                $gL10n->get('PLG_MEMBERSHIPFEE_NUMBER_TRANSACTIONS'),
                $gL10n->get('PLG_MEMBERSHIPFEE_CONTROL_SUM'),
                $gL10n->get('PLG_MEMBERSHIPFEE_STATUS'),
                ''
            )));
            foreach ($runs as $row) {
                $status = mf_booking_label((string) $row['mfr_status']);
                if ($row['mfr_status'] === BookingStore::RUN_COLLECTED) {
                    $status .= ' ' . mf_format_date($row['mfr_paid_date']);
                }
                $details = array();
                if ((int) $row['count_exported'] > 0 && $row['mfr_status'] !== BookingStore::RUN_CREATED) {
                    $details[] = $gL10n->get('PLG_MEMBERSHIPFEE_RUN_STILL_OPEN', array((int) $row['count_exported']));
                }
                if ((int) $row['count_cancelled'] > 0) {
                    $details[] = $gL10n->get('PLG_MEMBERSHIPFEE_RUN_CANCELLED_COUNT', array((int) $row['count_cancelled'], mf_format_amount($row['amount_cancelled'])));
                }
                if (count($details) > 0) {
                    $status .= '<br/><small>' . implode('<br/>', $details) . '</small>';
                }
                $detailUrl = SecurityUtils::encodeUrl($selfUrl, array('run_id' => $row['mfr_id']));
                $fileIcons = '';
                $xmlMeta = $runFiles[(int) $row['mfr_id']][BookingStore::FILE_XML] ?? null;
                if ($xmlMeta !== null) {
                    $fileIcons = ' <a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl($selfUrl, array('run_id' => $row['mfr_id'], 'mode' => 'download')) . '">'
                        . '<i class="bi bi-download" data-bs-toggle="tooltip" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_FILE_DOWNLOAD') . '"></i></a>';
                    if (isset($runFiles[(int) $row['mfr_id']][BookingStore::FILE_CONTROL])) {
                        $fileIcons .= ' <a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl($selfUrl, array('run_id' => $row['mfr_id'], 'mode' => 'download', 'file' => BookingStore::FILE_CONTROL)) . '">'
                            . '<i class="bi bi-file-earmark-spreadsheet" data-bs-toggle="tooltip" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_CONTROL_DOWNLOAD') . '"></i></a>';
                    }
                    if (mf_share_active($xmlMeta)) {
                        $fileIcons .= ' <i class="bi bi-share text-success" data-bs-toggle="tooltip" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_ACTIVE_UNTIL', array(mf_format_datetime($xmlMeta['mff_share_expires']))) . '"></i>';
                    }
                }
                $kindLabel = $gL10n->get(CollectionScope::fromKey((string) ($row['mfr_kind'] ?? ''), (int) $gCurrentOrgId)->isMembership() ? 'PLG_MEMBERSHIPFEE_SCOPE_MF' : 'PLG_MEMBERSHIPFEE_SCOPE_AD');
                $table->addRowByArray(array_merge(array(
                    mf_format_date(substr((string) $row['mfr_timestamp_create'], 0, 10))), $showKind ? array($kindLabel) : array(), array(
                    '<a href="' . $detailUrl . '">' . SecurityUtils::encodeHTML((string) $row['mfr_file_name']) . '</a>' . $fileIcons . '<br/><small class="text-muted">' . SecurityUtils::encodeHTML((string) $row['mfr_msg_id']) . '</small>',
                    SecurityUtils::encodeHTML((string) $row['mfr_due_dates']),
                    (int) $row['mfr_count'],
                    mf_format_amount($row['mfr_amount']),
                    $status,
                    // ein Button fuer alle Einzuege: oeffnet die Einzugsuebersicht (Positionen, Teilen, als eingezogen markieren)
                    '<a class="btn btn-sm btn-secondary text-nowrap" href="' . $detailUrl . '"><i class="bi bi-list-check"></i> '
                        . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_OPEN') . '</a>'
                )));
            }
            $page->addHtml($table->show(false));
        }
        $page->show();
        exit();
    }

    // Detail eines Einzugs (zurueck zur Liste ueber das Menue "5.0 Beitragseinzug" oder die Brotkrumen)

    $page->addHtml('<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_DUEDATES') . ': <strong>' . SecurityUtils::encodeHTML((string) $run['mfr_due_dates']) . '</strong> &middot; '
        . $gL10n->get('PLG_MEMBERSHIPFEE_NUMBER_TRANSACTIONS') . ': <strong>' . (int) $run['mfr_count'] . '</strong> &middot; '
        . $gL10n->get('PLG_MEMBERSHIPFEE_CONTROL_SUM') . ': <strong>' . mf_format_amount($run['mfr_amount']) . '</strong> &middot; '
        . $gL10n->get('PLG_MEMBERSHIPFEE_STATUS') . ': <strong>' . mf_booking_label((string) $run['mfr_status']) . ($run['mfr_paid_date'] ? ' ' . mf_format_date($run['mfr_paid_date']) : '') . '</strong> &middot; '
        . $gL10n->get('PLG_MEMBERSHIPFEE_MESSAGE_ID') . ': ' . SecurityUtils::encodeHTML((string) $run['mfr_msg_id']) . '</p>');

    // gespeicherte SEPA-Datei: Herunterladen, Teilen, Link widerrufen
    $runFile = BookingStore::findRunFileMeta($getRunId);
    $fileHtml = '<div class="card admidio-field-group mb-3"><div class="card-header">' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_FILE') . '</div><div class="card-body">';
    if (isset($_SESSION['pMembershipFee']['share_link']) && (int) $_SESSION['pMembershipFee']['share_link']['run_id'] === $getRunId) {
        $shareLink = $_SESSION['pMembershipFee']['share_link'];
        unset($_SESSION['pMembershipFee']['share_link']);
        if ($shareLink['message'] !== '') {
            $fileHtml .= '<div class="alert alert-info" role="alert">' . SecurityUtils::encodeHTML($shareLink['message']) . '</div>';
        }
        $linkFields = array('pmb_share_link' => array($gL10n->get('PLG_MEMBERSHIPFEE_SHARE_LINK_XML'), $shareLink['url']));
        if (($shareLink['url_control'] ?? '') !== '') {
            $linkFields['pmb_share_link_ctl'] = array($gL10n->get('PLG_MEMBERSHIPFEE_SHARE_LINK_CONTROL'), $shareLink['url_control']);
        }
        $fileHtml .= '<div class="alert alert-success" role="alert">' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_LINK_CREATED', array(mf_format_datetime($shareLink['expires'])));
        foreach ($linkFields as $fieldId => $linkData) {
            $fileHtml .= '<div class="mt-2"><small>' . $linkData[0] . '</small><div class="input-group"><input type="text" class="form-control" id="' . $fieldId . '" readonly="readonly" value="' . SecurityUtils::encodeHTML($linkData[1]) . '" />'
                . '<button class="btn btn-secondary pmb-share-copy" type="button" data-target="' . $fieldId . '"><i class="bi bi-clipboard"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_COPY') . '</button></div></div>';
        }
        $fileHtml .= '</div>';
        $page->addJavascript('
            $(".pmb-share-copy").click(function() {
                var field = document.getElementById($(this).data("target"));
                field.select();
                if (navigator.clipboard) { navigator.clipboard.writeText(field.value); } else { document.execCommand("copy"); }
            });', true);
    }
    if ($runFile === null) {
        $fileHtml .= '<p class="mb-0">' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_FILE_NOT_STORED') . '</p>';
    } else {
        $fileHtml .= '<p>' . SecurityUtils::encodeHTML((string) $runFile['mff_file_name']) . ' &middot; ' . number_format(((int) $runFile['mff_size']) / 1024, 1, ',', '.') . ' KB &middot; '
            . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_FILE_STORED_UNTIL', array((int) $runFile['mff_year'] + 1)) . '</p>';
        if (mf_share_active($runFile)) {
            $fileHtml .= '<p><i class="bi bi-share text-success"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_ACTIVE_UNTIL', array(mf_format_datetime($runFile['mff_share_expires'])))
                . ((string) $runFile['mff_share_email'] !== '' ? ' &middot; ' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_SENT_TO', array(SecurityUtils::encodeHTML((string) $runFile['mff_share_email']))) : '')
                . ' &middot; ' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_DOWNLOADS', array((int) $runFile['mff_downloads']))
                . ($runFile['mff_last_download'] ? ' (' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_LAST_DOWNLOAD', array(mf_format_datetime($runFile['mff_last_download']))) . ')' : '')
                . '</p>';
        } elseif ((int) $runFile['mff_downloads'] > 0) {
            $fileHtml .= '<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_DOWNLOADS', array((int) $runFile['mff_downloads']))
                . ($runFile['mff_last_download'] ? ' (' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_LAST_DOWNLOAD', array(mf_format_datetime($runFile['mff_last_download']))) . ')' : '') . '</p>';
        }
        $controlFile = BookingStore::findRunFileMeta($getRunId, BookingStore::FILE_CONTROL);
        if ($controlFile !== null) {
            $fileHtml .= '<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTROL_FILE') . ': ' . SecurityUtils::encodeHTML((string) $controlFile['mff_file_name']) . ' &middot; ' . number_format(((int) $controlFile['mff_size']) / 1024, 1, ',', '.') . ' KB</p>';
        }
        $fileHtml .= '<a class="btn btn-primary me-2" href="' . SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId, 'mode' => 'download')) . '"><i class="bi bi-download"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_FILE_DOWNLOAD') . '</a>';
        if ($controlFile !== null) {
            $fileHtml .= '<a class="btn btn-primary me-2" href="' . SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId, 'mode' => 'download', 'file' => BookingStore::FILE_CONTROL)) . '"><i class="bi bi-file-earmark-spreadsheet"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_CONTROL_DOWNLOAD') . '</a>';
        }
        $fileHtml .= '<a class="btn btn-secondary me-2" href="' . SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId, 'mode' => 'share')) . '"><i class="bi bi-share"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_FILE') . '</a>';
        if (mf_share_active($runFile)) {
            $fileHtml .= '<form class="d-inline" method="post" action="' . SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId, 'mode' => 'share_revoke')) . '">'
                . '<input type="hidden" name="adm_csrf_token" value="' . $gCurrentSession->getCsrfToken() . '" />'
                . '<button type="submit" class="btn btn-secondary"><i class="bi bi-x-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_SHARE_REVOKE') . '</button></form>';
        }
    }
    $fileHtml .= '</div></div>';
    $page->addHtml($fileHtml);

    $byUser = BookingStore::getByRun($getRunId);
    $names = array();
    if (count($byUser) > 0) {
        $names = list_members(array('LAST_NAME', 'FIRST_NAME'), 2, 'AND mem_usr_id IN (' . implode(',', array_map('intval', array_keys($byUser))) . ')');
    }
    $user = new Admidio\Users\Entity\User($gDb, $gProfileFields);

    $hasExported = false;
    $rowsHtml = '';
    $sortedUserIds = array_keys($byUser);
    usort($sortedUserIds, static function ($a, $b) use ($names) {
        return strcmp(($names[$a]['LAST_NAME'] ?? '') . ($names[$a]['FIRST_NAME'] ?? ''), ($names[$b]['LAST_NAME'] ?? '') . ($names[$b]['FIRST_NAME'] ?? ''));
    });
    foreach ($sortedUserIds as $usrId) {
        $positions = $byUser[$usrId];
        $user->readDataById((int) $usrId);
        $sum = 0.0;
        $statuses = array();
        $details = array();
        $reference = '';
        $dueDate = '';
        $cancelId = 0;
        foreach ($positions as $position) {
            $statuses[$position['mfb_status']] = true;
            if ($cancelId === 0 && $position['mfb_status'] !== BookingStore::STATUS_CANCELLED) {
                $cancelId = (int) $position['mfb_id'];
            }
            if ($position['mfb_status'] !== BookingStore::STATUS_CANCELLED) {
                $sum += (float) $position['mfb_amount'];
            }
            $details[] = '<span' . ($position['mfb_status'] === BookingStore::STATUS_CANCELLED ? ' style="text-decoration: line-through;"' : '') . '>'
                . SecurityUtils::encodeHTML($position['mfb_rol_name']) . ' ' . mf_format_amount($position['mfb_amount']) . '</span>';
            $reference = (string) $position['mfb_reference'];
            $dueDate = (string) $position['mfb_due_date'];
        }
        $exported = isset($statuses[BookingStore::STATUS_EXPORTED]);
        $hasExported = $hasExported || $exported;
        $statusText = implode(', ', array_map('mf_booking_label', array_keys($statuses)));
        $name = SecurityUtils::encodeHTML(($names[$usrId]['LAST_NAME'] ?? '') . ', ' . ($names[$usrId]['FIRST_NAME'] ?? ''));
        $historyUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bookings.php', array('user_uuid' => $user->getValue('usr_uuid')));

        $rowsHtml .= '<tr>
            <td>' . ($exported ? '<input type="checkbox" class="form-check-input pmb-collect" name="collect_user[]" value="' . (int) $usrId . '" checked="checked" />' : '') . '</td>
            <td><a href="' . $historyUrl . '">' . $name . '</a></td>
            <td>' . implode('<br/>', $details) . '</td>
            <td style="text-align: right;">' . mf_format_amount($sum) . '</td>
            <td>' . mf_format_date($dueDate) . '</td>
            <td>' . SecurityUtils::encodeHTML($reference) . '</td>
            <td style="white-space: nowrap;">' . $statusText
                . ($cancelId > 0 ? ' <a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/booking_edit.php', array('mode' => 'cancel', 'mfb_id' => $cancelId, 'return_run' => (int) $run['mfr_id'])) . '">'
                    . '<i class="bi bi-x-octagon" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL') . '"></i></a>' : '') . '</td>
        </tr>';
    }

    // Suchfeld ueber der Tabelle: blendet nicht passende Zeilen nur aus (Haken ausgeblendeter Zeilen bleiben erhalten)
    $tableHtml = '<div class="d-flex justify-content-end align-items-center gap-2 mb-2">'
        . '<label for="pmb_run_search" class="mb-0">' . $gL10n->get('PLG_MEMBERSHIPFEE_SEARCH') . ':</label>'
        . '<input type="search" id="pmb_run_search" class="form-control form-control-sm" style="width: auto; min-width: 12rem;" placeholder="' . $gL10n->get('PLG_MEMBERSHIPFEE_SEARCH_PLACEHOLDER') . '" autocomplete="off" />'
        . '<small id="pmb_run_search_info" class="text-muted text-nowrap"></small></div>'
        . '<div class="table-responsive"><table id="pmb_run_positions" class="table table-condensed table-hover"><thead><tr>
        <th>' . ($hasExported ? '<input type="checkbox" class="form-check-input" id="pmb_collect_all" checked="checked" title="' . $gL10n->get('SYS_ALL') . '" />' : '') . '</th>
        <th>' . $gL10n->get('SYS_NAME') . '</th>
        <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ROLE') . '</th>
        <th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_AMOUNT') . '</th>
        <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE') . '</th>
        <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_REFERENCE') . '</th>
        <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_STATUS') . '</th>
        </tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>';

    $page->addJavascript('
        $("#pmb_run_search").on("input", function() {
            var needle = $(this).val().toLowerCase().trim();
            var rows = $("#pmb_run_positions tbody tr");
            var shown = 0;
            rows.each(function() {
                var match = needle === "" || $(this).text().toLowerCase().indexOf(needle) !== -1;
                $(this).toggle(match);
                if (match) { shown++; }
            });
            $("#pmb_run_search_info").text(needle === "" ? "" : shown + " / " + rows.length);
        }).on("keydown", function(event) {
            // Enter im Suchfeld darf das Formular (als eingezogen markieren) nicht absenden
            if (event.key === "Enter") { event.preventDefault(); }
        });', true);

    if ($hasExported) {
        $page->addJavascript('
            // "Alle" wirkt nur auf die angezeigten Zeilen (Suche beachten)
            $("#pmb_collect_all").change(function() {
                $("#pmb_run_positions tbody tr:visible input.pmb-collect").prop("checked", $(this).prop("checked"));
            });', true);

        // „Als eingezogen markieren“ und „Einzug verwerfen“ nebeneinander, oberhalb und unterhalb der Liste.
        // Verwerfen sendet dasselbe Formular per formaction an mode=discard (die Haken werden dort ignoriert).
        $canDiscard = ($run['mfr_status'] === BookingStore::RUN_CREATED);
        $discardUrl = SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId, 'mode' => 'discard'));
        $actionButtons = '<div class="mb-3">'
            . '<button type="submit" class="btn btn-primary me-2 mb-2" name="btn_collect"><i class="bi bi-check2-all"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_COLLECT') . '</button>'
            . ($canDiscard ? '<button type="submit" class="btn btn-secondary mb-2 pmb-run-discard" name="btn_discard" formaction="' . $discardUrl . '" formnovalidate="formnovalidate"><i class="bi bi-x-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_DISCARD') . '</button>' : '')
            . '</div>';

        $form = new HtmlForm('run_collect_form', SecurityUtils::encodeUrl($selfUrl, array('run_id' => $getRunId, 'mode' => 'collect')), $page);
        $form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_RUN_COLLECT_DESC') . ($canDiscard ? '<br/>' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_DISCARD_DESC') : ''));
        $form->addInput('collect_date', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_DATE'), DATE_NOW, array(
            'type' => 'date',
            'property' => HtmlForm::FIELD_REQUIRED
        ));
        $form->addHtml($actionButtons);
        $form->addHtml($tableHtml);
        $form->addHtml($actionButtons);
        $page->addHtml($form->show(false));

        if ($canDiscard) {
            $page->addJavascript('
                $(".pmb-run-discard").click(function() {
                    return confirm("' . $gL10n->get('PLG_MEMBERSHIPFEE_RUN_DISCARD_CONFIRM') . '");
                });', true);
        }
    } else {
        $page->addHtml($tableHtml);
    }

    $page->show();
} catch (Exception $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    $gMessage->show($e->getMessage());
}
