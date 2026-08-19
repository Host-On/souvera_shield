<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Dashboard;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\Service\PMGException;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * Nextcloud dashboard widget displaying the user's current quarantine count.
 *
 * Implements the modern dashboard interfaces:
 *   - {@see IAPIWidget}     : legacy items endpoint (kept for compat)
 *   - {@see IAPIWidgetV2}   : NC 27.1+ items-with-empty-state endpoint
 *                             (fixes the permanent loading spinner that
 *                             appears when `items: []` is returned via
 *                             the legacy endpoint without an empty-state
 *                             message).
 *   - {@see IIconWidget}    : absolute icon URL (NC 25+). Fixes the
 *                             "no icon shown" issue – the previous
 *                             `getIconClass()` returned an undefined
 *                             CSS class `icon-shield`, so Dashboard.vue
 *                             rendered no icon at all.
 *
 * NOTE on DI: Nextcloud's container cannot auto-wire `OCP\IL10N` because the
 * factory needs to know the app id. We therefore inject `IFactory` and derive
 * the localised instance ourselves. This mirrors what Nextcloud core apps do.
 */
class QuarantineWidget implements IAPIWidget, IAPIWidgetV2, IIconWidget {

    private readonly IL10N $l;

    public function __construct(
        IFactory $l10nFactory,
        private readonly IURLGenerator $url,
        private readonly IUserSession $userSession,
        private readonly PMGClient $pmg,
    ) {
        $this->l = $l10nFactory->get(Application::APP_ID);
    }

    public function getId(): string {
        return Application::APP_ID;
    }

    public function getTitle(): string {
        return $this->l->t('Mail Quarantine');
    }

    public function getOrder(): int {
        return 30;
    }

    /**
     * CSS class name for the widget icon.
     *
     * Nextcloud registers `.icon-<appid>` automatically for apps with a
     * navigation icon. We provide a defined fallback here so clients that
     * ignore {@see IIconWidget::getIconUrl()} still get a usable class.
     */
    public function getIconClass(): string {
        return 'icon-souvera_shield';
    }

    /**
     * Absolute URL to the widget icon (NC 25+ modern API).
     *
     * Dashboard.vue uses this in preference to `getIconClass()`. We serve
     * a dedicated *black* SVG (`img/dashboard.svg`) instead of the white
     * navigation icon because the dashboard applies the
     * `--background-invert-if-dark` CSS filter:
     *
     *   - light mode → filter is none  → black icon on light widget bg
     *   - dark  mode → filter inverts  → white icon on dark  widget bg
     *
     * The navigation icon (`appicon.svg`) is white on purpose because the
     * Souvera sidebar theme inverts colour relative to the main content
     * area – the same rule would produce unreadable icons if reused here.
     */
    public function getIconUrl(): string {
        return $this->url->getAbsoluteURL(
            $this->url->imagePath(Application::APP_ID, 'dashboard.svg')
        );
    }

    public function getUrl(): ?string {
        return $this->url->linkToRouteAbsolute('souvera_shield.page.quarantine');
    }

    public function load(): void {
        // Nothing to enqueue – the widget JSON is consumed by Nextcloud.
    }

    /**
     * @inheritDoc
     * @return WidgetItem[]
     */
    public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
        return $this->fetchWidgetItems($userId, $limit);
    }

    /**
     * NC 27.1+ dashboard items endpoint.
     *
     * Returning `WidgetItems` (rather than a bare array) lets us provide
     * an explicit empty-state message – Dashboard.vue then renders the
     * "no items" content instead of staying stuck on the loading spinner.
     */
    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        return new WidgetItems(
            items:                   $this->fetchWidgetItems($userId, $limit),
            emptyContentMessage:     $this->l->t('Your quarantine is currently empty.'),
            halfEmptyContentMessage: '',
        );
    }

    /**
     * Shared item fetch used by both the legacy and V2 endpoints.
     *
     * @return WidgetItem[]
     */
    private function fetchWidgetItems(string $userId, int $limit): array {
        $user = $this->userSession->getUser();
        if ($user === null || $user->getUID() !== $userId) {
            return [];
        }
        $email = $user->getEMailAddress();
        if ($email === null || $email === '' || !$this->pmg->isAllowedDomain($email)) {
            return [];
        }
        try {
            // Match the 90-day window the app view uses (all=1) so the
            // widget reflects the same data set. Without $includeOlder
            // PMG only returns *today's* quarantined mails and the
            // widget looked "always empty" for users who last got hit
            // yesterday or earlier.
            $res = $this->pmg->getSpamQuarantine($email, true);
        } catch (PMGException) {
            return [];
        }
        $rows = $res['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        usort($rows, static fn(array $a, array $b) => (int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0));

        $iconUrl = $this->getIconUrl();
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $items[] = new WidgetItem(
                (string)($row['from'] ?? $row['sender'] ?? ''),
                (string)($row['subject'] ?? ''),
                $this->url->linkToRouteAbsolute('souvera_shield.page.quarantine'),
                $iconUrl,
                isset($row['time']) ? (string)(int)$row['time'] : '0',
            );
        }
        return $items;
    }
}
