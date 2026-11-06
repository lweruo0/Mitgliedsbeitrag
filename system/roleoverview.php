<?php
/**
 ***********************************************************************************************
 * Fruehere "Rollenuebersicht" des Admidio-Plugins Mitgliedsbeitrag
 *
 * Der Menuepunkt wurde in Version 6.8.1 entfernt. Diese Datei leitet alte Links und Lesezeichen
 * auf die Startseite des Plugins um.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Utils\SecurityUtils;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

admRedirect(SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php'));
