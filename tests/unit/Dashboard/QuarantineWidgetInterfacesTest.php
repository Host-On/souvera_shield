<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Tests\Unit\Dashboard;

use OCA\SouveraShield\Dashboard\QuarantineWidget;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the two v3.3.7 dashboard-widget fixes:
 *
 *   1. Icon is served through {@see IIconWidget::getIconUrl()} – prevents
 *      the "no icon shown" issue caused by an undefined `icon-shield`
 *      CSS class in older revisions.
 *   2. Items are served through {@see IAPIWidgetV2::getItemsV2()} which
 *      returns a `WidgetItems` value object with an explicit
 *      `emptyContentMessage` – prevents the permanent loading spinner
 *      Dashboard.vue would otherwise show on `items: []`.
 *   3. Title is the shorter "Mail Quarantine" (translated per locale).
 */
class QuarantineWidgetInterfacesTest extends TestCase {

    public function testWidgetImplementsAllRequiredInterfaces(): void {
        $ref = new \ReflectionClass(QuarantineWidget::class);
        $this->assertTrue($ref->implementsInterface(IAPIWidget::class),
            'Widget must implement IAPIWidget for legacy Dashboard clients');
        $this->assertTrue($ref->implementsInterface(IAPIWidgetV2::class),
            'Widget must implement IAPIWidgetV2 – fixes the permanent-spinner bug');
        $this->assertTrue($ref->implementsInterface(IIconWidget::class),
            'Widget must implement IIconWidget – fixes the missing-icon bug');
    }

    public function testWidgetHasGetItemsV2Method(): void {
        $ref = new \ReflectionClass(QuarantineWidget::class);
        $this->assertTrue($ref->hasMethod('getItemsV2'));
        $method = $ref->getMethod('getItemsV2');
        $rt = $method->getReturnType();
        $rtName = $rt instanceof \ReflectionNamedType ? $rt->getName() : (string)$rt;
        $this->assertSame('OCP\\Dashboard\\Model\\WidgetItems', $rtName);
    }

    public function testWidgetHasGetIconUrlMethod(): void {
        $ref = new \ReflectionClass(QuarantineWidget::class);
        $this->assertTrue($ref->hasMethod('getIconUrl'));
        $method = $ref->getMethod('getIconUrl');
        $rt = $method->getReturnType();
        $rtName = $rt instanceof \ReflectionNamedType ? $rt->getName() : (string)$rt;
        $this->assertSame('string', $rtName);
    }

    public function testGetIconUrlPointsAtDedicatedDashboardSvg(): void {
        // The widget must serve `dashboard.svg` (black) – not the
        // navigation icon `appicon.svg` (white) – so the dashboard's
        // invert-if-dark CSS filter produces the correct contrast in
        // both light and dark modes (v3.3.8 fix).
        $file = file_get_contents(__DIR__ . '/../../../lib/Dashboard/QuarantineWidget.php');
        $this->assertIsString($file);
        $this->assertStringContainsString("imagePath(Application::APP_ID, 'dashboard.svg')", $file);
        $this->assertStringNotContainsString("imagePath(Application::APP_ID, 'appicon.svg')", $file);
        $this->assertFileExists(__DIR__ . '/../../../img/dashboard.svg');
    }

    public function testGetTitleReturnsShorterMailQuarantineLabel(): void {
        // We assert the *source* string passed to $l->t() rather than the
        // translated output – the tokenised key is what l10n picks up.
        $file = file_get_contents(__DIR__ . '/../../../lib/Dashboard/QuarantineWidget.php');
        $this->assertIsString($file);
        $this->assertStringContainsString("\$this->l->t('Mail Quarantine')", $file);
        $this->assertStringNotContainsString("\$this->l->t('Quarantine in Souvera Shield')", $file);
    }
}
