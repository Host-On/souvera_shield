<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\SetupChecks;

use OCA\SouveraShield\SetupChecks\FileIntegrityCheck;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class FileIntegrityCheckTest extends TestCase {

    private string $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/shield_integrity_' . uniqid();
        mkdir($this->root . '/appinfo', 0777, true);
        mkdir($this->root . '/lib/Service', 0777, true);
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function check(): FileIntegrityCheck {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn(string $text, array $parameters = []) => vsprintf($text, $parameters),
        );
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l10n);
        return new FileIntegrityCheck($factory, $this->root);
    }

    private function writeManifest(array $files): void {
        file_put_contents(
            $this->root . '/appinfo/manifest.json',
            json_encode(['version' => '9.9.9', 'files' => $files]),
        );
    }

    private function writeFile(string $rel, string $content): string {
        file_put_contents($this->root . '/' . $rel, $content);
        return hash('sha256', $content);
    }

    public function testSuccessWhenAllFilesMatch(): void {
        $hash = $this->writeFile('lib/Service/Relay.php', '<?php // ok');
        $this->writeManifest(['lib/Service/Relay.php' => $hash]);

        $result = $this->check()->run();
        $this->assertSame('success', $result->getSeverity());
        $this->assertStringContainsString('9.9.9', (string)$result->getDescription());
    }

    public function testMissingFileYieldsErrorNamingTheFile(): void {
        $hash = $this->writeFile('lib/Service/Relay.php', '<?php // ok');
        $this->writeManifest([
            'lib/Service/Relay.php' => $hash,
            'lib/Service/SmtpMailTestRelay.php' => str_repeat('a', 64),
        ]);

        $result = $this->check()->run();
        $this->assertSame('error', $result->getSeverity());
        $this->assertStringContainsString('SmtpMailTestRelay.php', (string)$result->getDescription());
        $this->assertStringContainsString('PHP-FPM', (string)$result->getDescription());
    }

    public function testModifiedFileYieldsWarning(): void {
        $this->writeFile('lib/Service/Relay.php', '<?php // stale content');
        $this->writeManifest(['lib/Service/Relay.php' => str_repeat('b', 64)]);

        $result = $this->check()->run();
        $this->assertSame('warning', $result->getSeverity());
        $this->assertStringContainsString('Relay.php', (string)$result->getDescription());
    }

    public function testMissingManifestYieldsWarning(): void {
        $result = $this->check()->run();
        $this->assertSame('warning', $result->getSeverity());
        $this->assertStringContainsString('manifest', (string)$result->getDescription());
    }
}
