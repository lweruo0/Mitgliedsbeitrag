<?php
/**
 * Test-Bootstrap: Autoloader fuer die Plugin-Klassen (Namespace Plugins\MembershipFee\ = Plugin-Ordner)
 * und die Test-Klassen. Ein laufendes Admidio wird nicht benoetigt; getestet werden die Klassen
 * ohne Datenbankzugriff.
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Plugins\\MembershipFee\\Tests\\' => __DIR__ . '/',
        'Plugins\\MembershipFee\\'        => dirname(__DIR__) . '/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});

// Admidio-Konstanten, die in den Klassen verwendet werden
foreach ([
    'TABLE_PREFIX' => 'adm',
    'DATETIME_NOW' => '2026-11-01 12:00:00',
    'DATE_NOW'     => '2026-11-01',
    'TBL_CATEGORIES'  => 'adm_categories',
    'TBL_USER_FIELDS' => 'adm_user_fields',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
