<?php
declare(strict_types=1);

/**
 * Ad-hoc reflection check for DmarcController + related classes.
 * Not part of PHPUnit – executed directly by the testing agent
 * to guarantee the method signatures required by review_request.
 */
require_once __DIR__ . '/bootstrap.php';
if (!interface_exists(\OCP\IConfig::class)) {
    require_once __DIR__ . '/stubs.php';
}
require_once __DIR__ . '/extra_stubs.php';

require_once __DIR__ . '/../lib/Service/ProviderToolsException.php';
require_once __DIR__ . '/../lib/Service/ProviderToolsClient.php';
require_once __DIR__ . '/../lib/Db/DmarcDomain.php';
require_once __DIR__ . '/../lib/Migration/Version2400Date20260215000000.php';
require_once __DIR__ . '/../lib/Controller/DmarcController.php';

$ok = true;

$ref = new ReflectionClass(\OCA\SouveraShield\Controller\DmarcController::class);
$expected = [
    'status', 'domain', 'register', 'verify', 'stats', 'reports',
    'listTests', 'triggerTest', 'refreshTest',
];
foreach ($expected as $m) {
    if (!$ref->hasMethod($m)) {
        echo "MISSING method: $m\n";
        $ok = false;
        continue;
    }
    $method = $ref->getMethod($m);
    $rt = $method->getReturnType();
    $rtName = $rt instanceof ReflectionNamedType ? $rt->getName() : (string)$rt;
    if ($rtName !== 'OCP\\AppFramework\\Http\\JSONResponse') {
        echo "BAD return type for $m: $rtName\n";
        $ok = false;
    }
    $attrs = $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class);
    if (count($attrs) === 0) {
        echo "MISSING #[NoAdminRequired] on $m\n";
        $ok = false;
    } else {
        echo "OK  DmarcController::$m -> JSONResponse [#NoAdminRequired]\n";
    }
}

if ($ref->hasMethod('checkDmarc')) {
    echo "REGRESSION: legacy checkDmarc() still exists on DmarcController\n";
    $ok = false;
}

$ptc = new ReflectionClass(\OCA\SouveraShield\Service\ProviderToolsClient::class);
$pExpected = [
    'registerDomain'         => ['domain'],
    'verifyDomain'           => ['providerDomainId'],
    'getDomainStats'         => ['providerDomainId', 'days'],
    'listAggregateReports'   => ['providerDomainId', 'page', 'limit'],
    'deleteDomain'           => ['providerDomainId'],
    'checkDmarc'             => ['domain'],
];
foreach ($pExpected as $m => $params) {
    if (!$ptc->hasMethod($m)) {
        echo "MISSING ProviderToolsClient::$m\n";
        $ok = false;
        continue;
    }
    $method = $ptc->getMethod($m);
    $actual = array_map(fn($p) => $p->getName(), $method->getParameters());
    if ($actual !== $params) {
        echo "SIGNATURE mismatch $m: expected [" . implode(',', $params) . "] got [" . implode(',', $actual) . "]\n";
        $ok = false;
    } else {
        echo "OK  ProviderToolsClient::$m(" . implode(', ', $actual) . ")\n";
    }
}

$doc = (new ReflectionClass(\OCA\SouveraShield\Db\DmarcDomain::class))->getDocComment();
foreach (['getProviderDomainId', 'setProviderDomainId', 'getVerificationTxt', 'setVerificationTxt',
          'getReportEmail', 'setReportEmail', 'getDmarcRecord', 'setDmarcRecord',
          'getRegisteredAt', 'setRegisteredAt'] as $ann) {
    if (!str_contains((string)$doc, $ann)) {
        echo "MISSING @method annotation: $ann\n";
        $ok = false;
    }
}
echo "OK  DmarcDomain @method annotations for new fields\n";

$mig = new ReflectionClass(\OCA\SouveraShield\Migration\Version2400Date20260215000000::class);
$parent = $mig->getParentClass();
if (!$parent || $parent->getName() !== 'OCP\\Migration\\SimpleMigrationStep') {
    echo "Migration is not a SimpleMigrationStep\n";
    $ok = false;
} else {
    echo "OK  Migration Version2400Date20260215000000 extends SimpleMigrationStep\n";
}

echo $ok ? "REFLECTION_OK\n" : "REFLECTION_FAIL\n";
exit($ok ? 0 : 1);
