<?php
declare(strict_types=1);

/**
 * Live SMTP relay test against the local Stalwart:
 *   php tests/live_smtp_relay_test.php <appPassword>
 * Exercises the v3.5.9 connect logic: port 587 (closed locally) must fail
 * with a classified errno-111 message, port 465 (implicit TLS) must
 * authenticate and deliver.
 */

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/extra_stubs.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Service/MailTestRelayException.php';
require_once __DIR__ . '/../lib/Service/MailTestRelayConfig.php';
require_once __DIR__ . '/../lib/Service/SmtpMailTestRelay.php';

use OCA\SouveraShield\Service\MailTestRelayConfig;
use OCA\SouveraShield\Service\MailTestRelayException;
use OCA\SouveraShield\Service\SmtpMailTestRelay;

$secret = $argv[1] ?? '';
if ($secret === '') {
    fwrite(STDERR, "usage: php tests/live_smtp_relay_test.php <appPassword>\n");
    exit(1);
}

$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void {
        echo "   [$level] $message\n";
    }
};

require_once __DIR__ . '/extra_stubs.php';

$l10nStub = new class implements \OCP\IL10N {
    public function t(string $text, array $parameters = []): string {
        return $parameters === [] ? $text : (string)@vsprintf($text, $parameters);
    }
    public function n(string $s, string $p, int $c, array $params = []): string {
        return $c === 1 ? $s : $p;
    }
};
$factoryStub = new class ($l10nStub) implements \OCP\L10N\IFactory {
    public function __construct(private readonly \OCP\IL10N $l10n) {}
    public function get(string $app, ?string $lang = null): \OCP\IL10N { return $this->l10n; }
};

$relay = new SmtpMailTestRelay($logger, $factoryStub);
$from  = 'postmaster@kunde.test';

echo "=== 1) Port 587 (locally CLOSED) – expect classified connect error ===\n";
$cfg587 = MailTestRelayConfig::fromStalwart('http://127.0.0.1:8080', 587, $from, $secret);
try {
    $relay->send($cfg587, $from, $from, 'Souvera Shield', 'probe 587', "test\n");
    echo "FAIL: unexpected success on 587\n";
    exit(1);
} catch (MailTestRelayException $e) {
    echo "stage={$e->stage}\nmsg={$e->getMessage()}\n";
    if ($e->stage !== MailTestRelayException::STAGE_CONNECT
        || !str_contains($e->getMessage(), 'errno 111')) {
        echo "FAIL: expected connect/errno 111\n";
        exit(1);
    }
    echo "OK: classified as connection refused\n";
}

echo "\n=== 2) Host override (stalwart_smtp_host) takes precedence ===\n";
$cfgOvr = MailTestRelayConfig::fromStalwart('https://proxy.invalid:8080', 465, $from, $secret, '127.0.0.1');
echo 'smtpHost=' . $cfgOvr->smtpHost . ' port=' . $cfgOvr->smtpPort . "\n";
if ($cfgOvr->smtpHost !== '127.0.0.1') {
    echo "FAIL: override ignored\n";
    exit(1);
}

echo "\n=== 3) Port 465 (implicit TLS) – full AUTH + send ===\n";
try {
    $relay->send($cfgOvr, $from, $from, 'Souvera Shield', 'Souvera Shield relay live test', "Hello from the v3.5.9 relay.\n");
    echo "OK: mail accepted on 465 via host override\n";
} catch (\Throwable $e) {
    echo 'FAIL [' . ($e instanceof MailTestRelayException ? $e->stage : get_class($e)) . ']: ' . $e->getMessage() . "\n";
    exit(1);
}

echo "\nALL LIVE RELAY TESTS PASSED\n";
