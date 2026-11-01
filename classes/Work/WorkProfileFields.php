<?php
/**
 ***********************************************************************************************
 * Profilfelder des Arbeitsdienstes im Admidio-Plugin Mitgliedsbeitrag
 *
 * Kategorie "Arbeitsdienst" (cat_name_intern WORK_SERVICE<org>) mit
 *  - ADTARGET<org>       Sollstunden Arbeitsdienst (fuer das Mitglied im eigenen Profil sichtbar)
 *  - ADFEE<org>, ADTEXT<org>, ADDUEDATE<org>, ADSEQUENCETYPE<org>, ADPAID<org>
 *                        Arbeitsfelder des Buchungskreises Arbeitsdienst (siehe CollectionScope::work())
 *
 * Alle Felder sind nur von berechtigten Benutzern aenderbar (usf_disabled) und fuer andere Mitglieder
 * nicht sichtbar (usf_hidden), wie die Arbeitsfelder des Mitgliedsbeitrags.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Work;

use Admidio\Infrastructure\Entity\Entity;

class WorkProfileFields
{
    public static function categoryNameIntern(int $orgId): string
    {
        return 'WORK_SERVICE' . $orgId;
    }

    public static function targetField(int $orgId): string
    {
        return 'ADTARGET' . $orgId;
    }

    /**
     * @return array<string, array{name:string, type:string, description:string}> usf_name_intern => Definition
     */
    public static function definitions(int $orgId): array
    {
        return array(
            'ADTARGET' . $orgId => array('name' => 'Sollstunden Arbeitsdienst', 'type' => 'DECIMAL', 'description' => 'Arbeitsdienst-Stunden, die im Leistungsjahr zu leisten sind (wird mit „Sollstunden abgleichen“ gesetzt).'),
            'ADFEE' . $orgId => array('name' => 'Ersatzbeitrag Arbeitsdienst', 'type' => 'DECIMAL', 'description' => ''),
            'ADTEXT' . $orgId => array('name' => 'Text Ersatzbeitrag Arbeitsdienst', 'type' => 'TEXT', 'description' => ''),
            'ADDUEDATE' . $orgId => array('name' => 'Fälligkeit Arbeitsdienst', 'type' => 'DATE', 'description' => ''),
            'ADSEQUENCETYPE' . $orgId => array('name' => 'Sequenztyp Arbeitsdienst', 'type' => 'TEXT', 'description' => ''),
            'ADPAID' . $orgId => array('name' => 'Bezahlt Arbeitsdienst', 'type' => 'DATE', 'description' => '')
        );
    }

    /**
     * Fehlende Kategorie und Profilfelder anlegen (wie install_db.php ueber die Basisklasse Entity, damit auch
     * Mitglieder der Zugriffsrolle ohne Administratorrechte das ausloesen koennen). Wurde etwas angelegt,
     * werden die Profilfelder neu eingelesen und alle Sitzungen erneuert (Rueckgabe true).
     */
    public static function ensure(int $orgId): bool
    {
        global $gDb, $gProfileFields, $gCurrentSession;

        $created = false;
        $category = new Entity($gDb, TBL_CATEGORIES, 'cat');
        $category->readDataByColumns(array('cat_name_intern' => self::categoryNameIntern($orgId), 'cat_type' => 'USF'));
        if ((int) $category->getValue('cat_id') === 0) {
            $sequence = (int) $gDb->queryPrepared('SELECT COALESCE(MAX(cat_sequence), 0) FROM ' . TBL_CATEGORIES . ' WHERE cat_type = \'USF\'')->fetchColumn() + 1;
            $category = new Entity($gDb, TBL_CATEGORIES, 'cat');
            $category->setValue('cat_org_id', $orgId);
            $category->setValue('cat_type', 'USF');
            $category->setValue('cat_name', 'Arbeitsdienst');
            $category->setValue('cat_name_intern', self::categoryNameIntern($orgId));
            $category->setValue('cat_system', 0);
            $category->setValue('cat_sequence', $sequence);
            $category->save();
            $created = true;
        }
        $catId = (int) $category->getValue('cat_id');

        $sequence = (int) $gDb->queryPrepared('SELECT COALESCE(MAX(usf_sequence), 0) FROM ' . TBL_USER_FIELDS . ' WHERE usf_cat_id = ?', array($catId))->fetchColumn();
        foreach (self::definitions($orgId) as $nameIntern => $definition) {
            $exists = $gDb->queryPrepared('SELECT usf_id FROM ' . TBL_USER_FIELDS . ' WHERE usf_name_intern = ?', array($nameIntern))->fetchColumn();
            if ($exists) {
                continue;
            }
            $field = new Entity($gDb, TBL_USER_FIELDS, 'usf');
            $field->setValue('usf_cat_id', $catId);
            $field->setValue('usf_type', $definition['type']);
            $field->setValue('usf_name', $definition['name']);
            $field->setValue('usf_name_intern', $nameIntern);
            $field->setValue('usf_description', $definition['description']);
            $field->setValue('usf_system', 0);
            $field->setValue('usf_disabled', 1);
            $field->setValue('usf_hidden', 1);
            $field->setValue('usf_required_input', 0);
            $field->setValue('usf_sequence', ++$sequence);
            $field->save();
            $created = true;
        }

        if ($created) {
            if (isset($gProfileFields) && is_object($gProfileFields)) {
                $gProfileFields->readProfileFields($orgId);
            }
            if (isset($gCurrentSession) && is_object($gCurrentSession)) {
                $gCurrentSession->reloadAllSessions();
            }
        }
        return $created;
    }

    /**
     * @return array<int, string> vorhandene Felder (fuer die Deinstallation)
     */
    public static function existingFields(int $orgId): array
    {
        global $gDb;

        $fields = array();
        foreach (array_keys(self::definitions($orgId)) as $nameIntern) {
            if ($gDb->queryPrepared('SELECT usf_id FROM ' . TBL_USER_FIELDS . ' WHERE usf_name_intern = ?', array($nameIntern))->fetchColumn()) {
                $fields[] = $nameIntern;
            }
        }
        return $fields;
    }
}
