<?php
declare(strict_types=1);

/**
 * Verifies that classes previously crashing Nextcloud's DI container with
 * "Could not resolve OCP\IL10N!" now inject OCP\L10N\IFactory instead.
 *
 * The container is unable to auto-wire OCP\IL10N because the L10N factory
 * needs to know the app id. We assert:
 *   - constructor first parameter is OCP\L10N\IFactory
 *   - constructor NEVER type-hints OCP\IL10N directly
 */
require_once __DIR__ . '/bootstrap.php';
if (!interface_exists(\OCP\IConfig::class)) {
    require_once __DIR__ . '/stubs.php';
}
require_once __DIR__ . '/extra_stubs.php';

require_once __DIR__ . '/../lib/AppInfo/Application.php';
require_once __DIR__ . '/../lib/Service/PMGException.php';
require_once __DIR__ . '/../lib/Service/PMGClient.php';
require_once __DIR__ . '/../lib/Dashboard/QuarantineWidget.php';
require_once __DIR__ . '/../lib/Search/QuarantineSearchProvider.php';

$targets = [
    \OCA\SouveraShield\Dashboard\QuarantineWidget::class,
    \OCA\SouveraShield\Search\QuarantineSearchProvider::class,
];

$ok = true;
foreach ($targets as $cls) {
    $ref = new ReflectionClass($cls);
    $ctor = $ref->getConstructor();
    if ($ctor === null) {
        echo "MISSING constructor on $cls\n";
        $ok = false;
        continue;
    }
    $params = $ctor->getParameters();
    if (count($params) === 0) {
        echo "EMPTY constructor on $cls\n";
        $ok = false;
        continue;
    }
    // 1. Assert no parameter uses OCP\IL10N directly
    foreach ($params as $p) {
        $t = $p->getType();
        $tn = $t instanceof ReflectionNamedType ? $t->getName() : (string)$t;
        if ($tn === 'OCP\\IL10N') {
            echo "REGRESSION $cls injects OCP\\IL10N directly (param \${$p->getName()})\n";
            $ok = false;
        }
    }
    // 2. Assert first parameter is OCP\L10N\IFactory
    $first = $params[0];
    $ft = $first->getType();
    $ftn = $ft instanceof ReflectionNamedType ? $ft->getName() : (string)$ft;
    if ($ftn !== 'OCP\\L10N\\IFactory') {
        echo "BAD first ctor param on $cls: expected OCP\\L10N\\IFactory, got $ftn\n";
        $ok = false;
    } else {
        echo "OK  $cls first ctor param is OCP\\L10N\\IFactory (\${$first->getName()})\n";
    }
}

echo $ok ? "L10N_DI_OK\n" : "L10N_DI_FAIL\n";
exit($ok ? 0 : 1);
