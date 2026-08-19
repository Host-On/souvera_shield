<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Search;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\Service\PMGException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Plug Souvera Shield quarantine messages into the Nextcloud unified search
 * (the bar at the top of every page / the global Ctrl+K palette).
 *
 * NOTE on DI: `OCP\IL10N` cannot be auto-wired (the factory needs the app id),
 * so we inject the `IFactory` and derive the localised instance in the ctor.
 */
class QuarantineSearchProvider implements IProvider {

    private readonly IL10N $l;

    public function __construct(
        IFactory $l10nFactory,
        private readonly IURLGenerator $url,
        private readonly PMGClient $pmg,
    ) {
        $this->l = $l10nFactory->get(Application::APP_ID);
    }

    public function getId(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l->t('Spam quarantine');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return str_starts_with($route, Application::APP_ID . '.') ? -1 : 80;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $email = $user->getEMailAddress();
        if ($email === null || $email === '' || !$this->pmg->isAllowedDomain($email)) {
            return SearchResult::complete($this->getName(), []);
        }

        $term = mb_strtolower($query->getTerm());
        if ($term === '') {
            return SearchResult::complete($this->getName(), []);
        }

        try {
            $rows = $this->pmg->getSpamQuarantine($email, true)['data'];
        } catch (PMGException) {
            return SearchResult::complete($this->getName(), []);
        }

        $entries = [];
        foreach ($rows as $row) {
            $subject = (string)($row['subject'] ?? '');
            $from    = (string)($row['from'] ?? $row['sender'] ?? '');
            $haystack = mb_strtolower($subject . ' ' . $from);
            if (!str_contains($haystack, $term)) {
                continue;
            }
            $entries[] = new SearchResultEntry(
                $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'appicon.svg')),
                $subject !== '' ? $subject : $from,
                $from,
                $this->url->linkToRouteAbsolute('souvera_shield.page.quarantine'),
                '',
                true,
            );
            if (count($entries) >= $query->getLimit()) {
                break;
            }
        }
        return SearchResult::complete($this->getName(), $entries);
    }
}
