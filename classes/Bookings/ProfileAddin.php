<?php
/**
 ***********************************************************************************************
 * Registerkarten "Beitraege" und "Arbeitsdienst" im Mitgliedsprofil
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

    /**
     * Ist die eingebaute Fassung aktuell (mit Registerkarte "Arbeitsdienst")?
     */
    public static function isCurrent(): bool
    {
        $tpl = @file_get_contents(self::templateFile());
        return self::isInstalled() && is_string($tpl) && strpos($tpl, 'pmbWorkHtml') !== false;
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

        $tab = self::MARK_START_TPL . self::tab('pmb_bookings', 'pmbBookings') . self::tab('pmb_work', 'pmbWork') . self::MARK_END_TPL . "\n            ";
        $pane = self::MARK_START_TPL . self::pane('pmb_bookings', 'pmbBookings') . self::pane('pmb_work', 'pmbWork') . self::MARK_END_TPL . "\n            ";
        $accordion = self::MARK_START_TPL . self::accordion('pmb_bookings', 'pmbBookings') . self::accordion('pmb_work', 'pmbWork') . self::MARK_END_TPL . "\n        ";

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

    private static function tab(string $id, string $var): string
    {
        return '
            {if isset($' . $var . 'Html)}
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="' . $id . '_tab" data-bs-toggle="tab" data-bs-target="#' . $id . '_pane" type="button" role="tab" aria-controls="' . $id . '_pane" aria-selected="false">
                        {$' . $var . 'Title}
                    </button>
                </li>
            {/if}
            ';
    }

    private static function pane(string $id, string $var): string
    {
        return '
            {if isset($' . $var . 'Html)}
                <div class="tab-pane fade" id="' . $id . '_pane" role="tabpanel" aria-labelledby="' . $id . '_tab">
                    <div class="card admidio-tabbed-field-group">
                        <div class="card-body">{$' . $var . 'Html}</div>
                    </div>
                </div>
            {/if}
            ';
    }

    private static function accordion(string $id, string $var): string
    {
        return '
        {if isset($' . $var . 'Html)}
            <div class="accordion-item">
                <h2 class="accordion-header" id="' . $id . '_accordion_heading">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#' . $id . '_accordion" aria-expanded="false" aria-controls="' . $id . '_accordion">
                        {$' . $var . 'Title}
                    </button>
                </h2>
                <div id="' . $id . '_accordion" class="accordion-collapse collapse" aria-labelledby="' . $id . '_accordion_heading" data-bs-parent="#adm_profile_accordion">
                    <div class="accordion-body">{$' . $var . 'Html}</div>
                </div>
            </div>
        {/if}
        ';
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
