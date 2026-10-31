<?php
/**
 ***********************************************************************************************
 * Registerkarte "Beitraege" im Mitgliedsprofil
 *
 * Admidio 5 bietet keine Schnittstelle, ueber die ein Plugin die Profilseite erweitert. Wie beim
 * Plugin Awards werden deshalb zwei Admidio-Dateien ergaenzt:
 *  - modules/profile/profile.php: bindet vor $page->show() die Datei system/profile_addin.php ein
 *  - <theme>/templates/modules/profile.view.tpl: Registerkarte und Akkordeon
 *
 * Alle Ergaenzungen sind mit Markierungen versehen und werden beim Entfernen gezielt geloescht.
 * So bleiben Ergaenzungen anderer Plugins (z. B. Awards) erhalten. Nach einem Admidio-Update
 * muss das Add-in erneut installiert werden.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Bookings;

class ProfileAddin
{
    private const MARK_START_PHP = '// PMB-BOOKINGS-ADDIN-START';
    private const MARK_END_PHP = '// PMB-BOOKINGS-ADDIN-END';
    private const MARK_START_TPL = '{* PMB-BOOKINGS-ADDIN-START *}';
    private const MARK_END_TPL = '{* PMB-BOOKINGS-ADDIN-END *}';

    public static function profilePhpFile(): string
    {
        return ADMIDIO_PATH . FOLDER_MODULES . '/profile/profile.php';
    }

    public static function templateFile(): string
    {
        $file = THEME_PATH . '/templates/modules/profile.view.tpl';
        if (!is_file($file)) {
            $file = ADMIDIO_PATH . FOLDER_THEMES . '/simple/templates/modules/profile.view.tpl';
        }
        return $file;
    }

    public static function isInstalled(): bool
    {
        $php = @file_get_contents(self::profilePhpFile());
        $tpl = @file_get_contents(self::templateFile());
        return is_string($php) && is_string($tpl)
            && strpos($php, self::MARK_START_PHP) !== false
            && strpos($tpl, self::MARK_START_TPL) !== false;
    }

    /**
     * Traegt das Add-in ein.
     * @param string $pluginFolder Name des Plugin-Ordners, z. B. "/Mitgliedsbeitrag"
     * @return string leer bei Erfolg, sonst Fehlerbeschreibung
     */
    public static function install(string $pluginFolder): string
    {
        // erst eventuelle Reste entfernen, dann neu eintragen
        $error = self::uninstall();
        if ($error !== '') {
            return $error;
        }

        $php = @file_get_contents(self::profilePhpFile());
        $tpl = @file_get_contents(self::templateFile());
        if (!is_string($php) || !is_string($tpl)) {
            return 'Die Admidio-Dateien konnten nicht gelesen werden.';
        }

        $phpNeedle = '$page->show();';
        $pos = strrpos($php, $phpNeedle);
        if ($pos === false) {
            return 'In profile.php wurde die Einfügestelle nicht gefunden.';
        }
        $addinFile = "ADMIDIO_PATH . FOLDER_PLUGINS . '" . $pluginFolder . "/system/profile_addin.php'";
        $phpInsert = self::MARK_START_PHP . "\n"
            . '    if (is_file(' . $addinFile . ')) {' . "\n"
            . '        include ' . $addinFile . ';' . "\n"
            . '    }' . "\n"
            . '    ' . self::MARK_END_PHP . "\n    ";
        $php = substr_replace($php, $phpInsert, $pos, 0);

        $tab = self::MARK_START_TPL . '
            {if isset($pmbBookingsHtml)}
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pmb_bookings_tab" data-bs-toggle="tab" data-bs-target="#pmb_bookings_pane" type="button" role="tab" aria-controls="pmb_bookings_pane" aria-selected="false">
                        {$pmbBookingsTitle}
                    </button>
                </li>
            {/if}
            ' . self::MARK_END_TPL . "\n            ";
        $pane = self::MARK_START_TPL . '
            {if isset($pmbBookingsHtml)}
                <div class="tab-pane fade" id="pmb_bookings_pane" role="tabpanel" aria-labelledby="pmb_bookings_tab">
                    <div class="card admidio-tabbed-field-group">
                        <div class="card-body">{$pmbBookingsHtml}</div>
                    </div>
                </div>
            {/if}
            ' . self::MARK_END_TPL . "\n            ";
        $accordion = self::MARK_START_TPL . '
        {if isset($pmbBookingsHtml)}
            <div class="accordion-item">
                <h2 class="accordion-header" id="pmb_bookings_accordion_heading">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pmb_bookings_accordion" aria-expanded="false" aria-controls="pmb_bookings_accordion">
                        {$pmbBookingsTitle}
                    </button>
                </h2>
                <div id="pmb_bookings_accordion" class="accordion-collapse collapse" aria-labelledby="pmb_bookings_accordion_heading" data-bs-parent="#adm_profile_accordion">
                    <div class="accordion-body">{$pmbBookingsHtml}</div>
                </div>
            </div>
        {/if}
        ' . self::MARK_END_TPL . "\n        ";

        $needles = array(
            '{if $showRelations}' => $tab,                 // erste Fundstelle: Registerkarten-Leiste
            '<!-- User Relations Tab -->' => $pane,
            '<!-- User Relations Accordion -->' => $accordion
        );
        foreach ($needles as $needle => $insert) {
            $pos = strpos($tpl, $needle);
            if ($pos === false) {
                return 'In profile.view.tpl wurde die Einfügestelle "' . $needle . '" nicht gefunden.';
            }
            $tpl = substr_replace($tpl, $insert, $pos, 0);
        }

        if (@file_put_contents(self::profilePhpFile(), $php) === false || @file_put_contents(self::templateFile(), $tpl) === false) {
            return 'Die Admidio-Dateien konnten nicht geschrieben werden (Schreibrechte prüfen).';
        }
        return '';
    }

    /**
     * Entfernt das Add-in (nur die eigenen, markierten Ergaenzungen).
     * @return string leer bei Erfolg, sonst Fehlerbeschreibung
     */
    public static function uninstall(): string
    {
        $files = array(
            self::profilePhpFile() => array(self::MARK_START_PHP, self::MARK_END_PHP),
            self::templateFile() => array(self::MARK_START_TPL, self::MARK_END_TPL)
        );
        foreach ($files as $file => $marks) {
            $content = @file_get_contents($file);
            if (!is_string($content)) {
                return 'Die Datei ' . basename($file) . ' konnte nicht gelesen werden.';
            }
            if (strpos($content, $marks[0]) === false) {
                continue;
            }
            $pattern = '/' . preg_quote($marks[0], '/') . '.*?' . preg_quote($marks[1], '/') . '\s*/s';
            $content = preg_replace($pattern, '', $content);
            if (@file_put_contents($file, $content) === false) {
                return 'Die Datei ' . basename($file) . ' konnte nicht geschrieben werden (Schreibrechte prüfen).';
            }
        }
        return '';
    }
}
