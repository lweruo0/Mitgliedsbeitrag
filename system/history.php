<?php
/**
 ***********************************************************************************************
 * Die fruehere Beitragshistorie (auf Basis des Profilfeld-Aenderungsprotokolls) wurde durch die
 * Buchungshistorie ersetzt. Diese Datei leitet auf die Gesamtuebersicht weiter.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

admRedirect(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/booking_overview.php');
