<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit;

use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Passthrough IL10N/IFactory mocks for unit tests.
 *
 * Reproduces Nextcloud's fallback behaviour: when no translation is
 * available, IL10N::t() returns the source string with the `%…$s`
 * placeholders substituted via `vsprintf()`. This lets tests assert
 * against the *English source string* directly – exactly what a
 * fallback locale sees at run-time.
 */
trait L10NTestHelper {

    protected function passthroughL10N(): IL10N {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $params = []): string {
                if ($params === []) {
                    return $text;
                }
                // gettext-style: convert %s / %d / %1$s to sprintf placeholders.
                // We just call vsprintf directly; the source strings already
                // use RFC-3339 positional syntax (%1$s, %2$d) where needed.
                $formatted = @vsprintf($text, $params);
                return $formatted === false ? $text : $formatted;
            },
        );
        $l10n->method('n')->willReturnCallback(
            static function (string $singular, string $plural, int $count, array $params = []): string {
                $text = $count === 1 ? $singular : $plural;
                $params = array_merge([$count], $params);
                $formatted = @vsprintf(str_replace('%n', '%d', $text), $params);
                return $formatted === false ? $text : $formatted;
            },
        );
        return $l10n;
    }

    protected function l10nFactory(): IFactory {
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($this->passthroughL10N());
        return $factory;
    }
}
