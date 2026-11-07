<?php
/**
 ***********************************************************************************************
 * Seite des Plugins mit Menue in der linken Spalte (Aufbau wie die Admidio-Einstellungen)
 *
 * Wird anstelle von HtmlPage verwendet. Beim Anzeigen steht links das gruppierte Menue des Plugins
 * (mf_menu_groups()), rechts der Inhalt der Seite. Der aktive Eintrag ergibt sich aus dem Dateinamen
 * der Seite und dem Buchungskreis (mf_menu_active_key()) oder wird mit setActiveMenuEntry() gesetzt.
 *
 * Das Menue sehen nur Mitglieder der Zugriffsrolle des Plugins; andere Benutzer (z. B. ein
 * Arbeitsdienstleiter, der nur Stunden erfassen darf) bekommen die Seite ohne Menue.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Ui;

class SidebarPage extends \HtmlPage
{
    private ?string $activeMenuEntry = null;

    public function setActiveMenuEntry(string $key): void
    {
        $this->activeMenuEntry = $key;
    }

    public function show()
    {
        if (function_exists('mf_menu_groups') && isUserAuthorized()) {
            $groups = mf_menu_groups();
            $active = $this->activeMenuEntry ?? mf_menu_active_key($groups);
            // gemeinsame Einzugsseiten: Umschaltung Mitgliedsbeitrag/Arbeitsdienst ueber dem Inhalt
            foreach ($groups as $group) {
                foreach ($group['entries'] as $entry) {
                    if ($entry['key'] === $active && !empty($entry['switch'])) {
                        $this->pageContent = mf_collection_switch_html() . $this->pageContent;
                    }
                }
            }
            $this->pageContent = mf_layout_html($groups, $active, $this->pageContent);
            // mit Menue braucht der Inhalt die volle Breite; Darstellung wie die Admidio-Einstellungen
            $this->setContentFullWidth();
            $this->addCssFile(ADMIDIO_URL . FOLDER_LIBS . '/bootstrap-tabs-x/css/bootstrap-tabs-x-admidio.css');
            $this->addHeader('<style>
                .mf-tabs > .tab-content { min-width: 0; }
                .mf-tabs .group-heading h5 { hyphens: auto; overflow-wrap: break-word; }
                /* aufklappbare Tabellenzeilen: Pfeil und Auswahlkaestchen nebeneinander statt untereinander */
                .mf-tab-content table.dataTable > tbody > tr > td.dtr-control { white-space: nowrap; }
                /* Menue: 25 % der Breite wie bisher, aber nie breiter als der laengste Eintrag (breite Bildschirme) */
                @media (min-width: 768px) {
                    .mf-tabs.tabs-left > .nav-tabs { width: 25%; min-width: 15rem; max-width: max-content; flex: 0 0 auto !important; }
                }
                /* Handy-Menue im Stil des Admidio-Hauptmenues */
                .mf-mobile-menu { background-color: var(--bs-secondary); border-radius: var(--bs-border-radius); }
                .mf-mobile-menu .admidio-headline-mobile-menu { letter-spacing: 1px; }
                .mf-mobile-menu .admidio-menu-list .nav-link.active { color: var(--admidio-menu-font-color-hover); background-color: var(--admidio-menu-bg-color-hover); }
                /* Umschalter Buchungskreis/Leistungsjahr bleiben auch auf dem Handy nebeneinander (Admidio stapelt btn-groups) */
                .mf-switch .btn-group { display: inline-flex; }
                @media (max-width: 500px) {
                    .mf-switch > span { width: 100%; }
                    .mf-switch .btn-group { display: flex; width: 100%; }
                    .mf-switch .btn-group .btn { flex: 1 1 0; padding-left: .5rem !important; padding-right: .5rem !important; font-size: .9rem; }
                }
                @media (max-width: 767.98px) {
                    .mf-tabs.tabs-left { display: block; }
                    .mf-tabs > .tab-content { margin-left: 0; border-left: none; padding: 0; width: 100%; }
                }
            </style>');
        }
        parent::show();
    }
}
