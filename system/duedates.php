<?php
/**
 ***********************************************************************************************
 * Frueherer Schritt "Faelligkeit setzen" des Admidio-Plugins Mitgliedsbeitrag
 *
 * Das Faelligkeitsdatum (Einzugsdatum) wird seit Version 6.6.0 beim Erstellen der Rechnung gesetzt
 * (bill.php, Dialog "Rechnungen erstellen"). Diese Datei leitet alte Links und Lesezeichen dorthin um.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Utils\SecurityUtils;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

$collection = admFuncVariableIsValid($_GET, 'collection', 'string', array(
    'defaultValue' => '',
    'validValues' => array('', 'mf', 'ad')
));

admRedirect(SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bill.php',
    $collection !== '' ? array('collection' => $collection) : array()));
