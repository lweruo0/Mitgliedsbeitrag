<?php
/**
 ***********************************************************************************************
 * Bruecke zur PDF-Bibliothek wp-tc-lib-pdf (adm_plugins/wp-tc-lib-pdf)
 *
 * Das Plugin greift nur ueber diese Klasse auf die Bibliothek zu (docs/konzept-rechnungen-pdf.md, Abschnitt 10).
 * Ohne Bibliothek, ohne Einrichtung (config.php mit secret) oder unter PHP 8.5 sind alle PDF-Funktionen aus;
 * Nummernvergabe und Export laufen weiter.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Invoices;

use Plugins\MembershipFee\classes\Bookings\CollectionScope;

final class PdfBridge
{
    public const TYPE_INVOICE = 'rechnung';
    public const TYPE_DUNNING = 'mahnung';

    private static ?bool $loaded = null;

    private static function bootstrapFile(): string
    {
        return ADMIDIO_PATH . FOLDER_PLUGINS . '/wp-tc-lib-pdf/adm-include/bootstrap.php';
    }

    /**
     * Bibliothek laden (ohne tc-lib-pdf selbst; das geschieht erst beim Erzeugen).
     */
    private static function load(): bool
    {
        if (self::$loaded === null) {
            self::$loaded = false;
            if (PHP_VERSION_ID >= 80500 && is_file(self::bootstrapFile())) {
                require_once self::bootstrapFile();
                self::$loaded = function_exists('tc_lib_pdf_adm_save');
            }
        }
        return self::$loaded;
    }

    /**
     * Grund, warum keine PDFs erzeugt werden koennen; leer = verfuegbar.
     */
    public static function unavailableReason(): string
    {
        if (PHP_VERSION_ID < 80500) {
            return 'PDF-Bibliothek benötigt PHP 8.5, Server läuft mit ' . PHP_VERSION;
        }
        if (!is_file(self::bootstrapFile())) {
            return 'PDF-Bibliothek wp-tc-lib-pdf ist nicht installiert';
        }
        if (!self::load()) {
            return 'PDF-Bibliothek konnte nicht geladen werden';
        }
        return (string) \tc_lib_pdf_adm_unavailable_reason();
    }

    public static function isAvailable(): bool
    {
        return self::unavailableReason() === '';
    }

    public static function templateId(string $kind, string $type): string
    {
        return 'adm_' . $type . '_' . ($kind === CollectionScope::WORK ? 'arbeitsdienst' : 'beitrag');
    }

    /**
     * Unterordner der Ablage je Buchungskreis.
     */
    public static function folder(string $kind): string
    {
        return $kind === CollectionScope::WORK ? 'arbeitsdienst' : 'beitrag';
    }

    /**
     * PDF erzeugen und ablegen.
     *
     * @return string Dateiname (ohne Pfad), z. B. rechnung_2026-B-0012.pdf
     * @throws \RuntimeException
     */
    public static function create(string $invoiceNo, string $kind, string $type = self::TYPE_INVOICE): string
    {
        $reason = self::unavailableReason();
        if ($reason !== '') {
            throw new \RuntimeException($reason);
        }
        $path = \tc_lib_pdf_adm_save(self::templateId($kind, $type), array('nr' => $invoiceNo));
        $file = basename($path);
        if (!InvoiceNumber::isValidFileName($file)) {
            throw new \RuntimeException('Unerwarteter Dateiname: ' . $file);
        }
        return $file;
    }

    /**
     * Absoluter Pfad einer abgelegten Datei, oder '' wenn sie fehlt oder der Name ungueltig ist.
     */
    public static function filePath(string $kind, string $fileName): string
    {
        if ($fileName === '' || !InvoiceNumber::isValidFileName($fileName) || !self::load()) {
            return '';
        }
        $dir = realpath(\PdfRuntime::platform()->getStorageBaseDir() . '/' . self::folder($kind));
        if ($dir === false) {
            return '';
        }
        $path = realpath($dir . '/' . $fileName);
        return ($path !== false && str_starts_with($path, $dir . DIRECTORY_SEPARATOR) && is_file($path)) ? $path : '';
    }

    /**
     * Abgelegte PDFs eines Buchungskreises zu einem Druck-PDF zusammenfuegen (PdfJoiner der Bibliothek,
     * Wurzel = Unterordner des Kreises; nichts wird gespeichert).
     *
     * @param list<string> $paths absolute Pfade aus filePath()
     * @throws \RuntimeException
     */
    public static function joinForPrint(array $paths, string $kind, bool $duplex, string $title): \PdfJoinResult
    {
        $reason = self::unavailableReason();
        if ($reason !== '') {
            throw new \RuntimeException($reason);
        }
        require_once TC_LIB_PDF_DIR . '/include/class-pdf-joiner.php';
        $root = realpath(\PdfRuntime::platform()->getStorageBaseDir() . '/' . self::folder($kind));
        if ($root === false) {
            throw new \RuntimeException('Ablageordner fehlt');
        }
        return \PdfJoiner::join($paths, array('duplex' => $duplex, 'title' => $title, 'root' => $root));
    }

    /**
     * Signierter Link (ohne Anmeldung gueltig bis $expires).
     */
    public static function signedUrl(string $invoiceNo, string $kind, string $type, string $expires): string
    {
        if (!self::isAvailable()) {
            return '';
        }
        return (string) \tc_lib_pdf_adm_create_pdf_url(self::templateId($kind, $type), array('nr' => $invoiceNo), $expires);
    }
}
