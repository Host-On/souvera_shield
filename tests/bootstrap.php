<?php
declare(strict_types=1);

/**
 * Bootstrap for the Souvera Shield unit tests.
 *
 * Loads the composer autoloader (PHPUnit, etc.) and then falls back to the
 * inline OCP stubs whenever the official nextcloud/ocp dev dependency is
 * not present. This means the tests run in isolation from any Nextcloud
 * installation.
 */

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

if (!interface_exists(\OCP\IConfig::class)) {
    require_once $root . '/tests/stubs.php';
}
require_once $root . '/tests/extra_stubs.php';
require_once $root . '/tests/unit/L10NTestHelper.php';

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'OCA\\SouveraShield\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $root . '/lib/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
