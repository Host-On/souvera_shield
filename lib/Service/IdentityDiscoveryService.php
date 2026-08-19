<?php

declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Discovers all e-mail addresses the current NC user has access to
 * (primary + aliases + shared mailboxes).
 *
 * Uses souvera_central's StalwartService (admin-credentialed JMAP) —
 * no OIDC dependency, no H2CK requirement. The Stalwart admin API
 * can read any account's data, so identity resolution is always available
 * as long as souvera_central is installed.
 *
 * Flow:
 *  1. NC-User-ID → NC-E-Mail
 *  2. StalwartService::getEmails($ncEmail) → primary + aliases
 *  3. StalwartService::findAccountId() → user's Stalwart account
 *  4. Read user's memberGroupIds → find shared mailbox groups
 *  5. Account/get for each group → get group e-mail addresses
 *  6. Deduplicate + return
 */
class IdentityDiscoveryService
{
    private const CENTRAL_STALWART_FQN = 'OCA\\SouveraCentral\\Service\\StalwartService';

    private array $cache = [];

    public function __construct(
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns all unique e-mail addresses for the current user.
     *
     * @return string[] Empty array when nothing can be resolved.
     */
    public function discover(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $userId = $user->getUID();
        $cacheKey = 'identities:' . $userId;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $ncEmail = $user->getEMailAddress();
        if ($ncEmail === null || $ncEmail === '') {
            return [];
        }

        $emails = [$ncEmail];

        if ($this->centralAvailable()) {
            try {
                // Set a time limit so discover() never hangs the request.
                $deadline = \time() + 15;

                $stalwart = \OCP\Server::get(self::CENTRAL_STALWART_FQN);

                // 1. User's own addresses (primary + aliases)
                $userEmails = $stalwart->getEmails($ncEmail);
                foreach ($userEmails as $e) {
                    if ($e !== '' && !\in_array($e, $emails, true)) {
                        $emails[] = $e;
                    }
                }

                if (\time() > $deadline) {
                    throw new \RuntimeException('Identity discovery timeout after getEmails');
                }

                // 2. Shared mailbox groups via memberGroupIds
                $accountId = $stalwart->findAccountId($ncEmail, 'User');
                if ($accountId !== null) {
                    $account = $stalwart->getAccountById($accountId);
                    $groupIds = \is_array($account['memberGroupIds'] ?? null)
                        ? \array_keys($account['memberGroupIds'])
                        : [];

                    if (!empty($groupIds)) {
                        $groups = $stalwart->jmapSingle('x:Account/get', [
                            'ids' => $groupIds,
                            'properties' => ['emailAddress', 'name'],
                        ]);
                        foreach ($groups['list'] ?? [] as $group) {
                            $gEmail = \trim((string) ($group['emailAddress'] ?? ''));
                            if ($gEmail !== '' && !\in_array($gEmail, $emails, true)) {
                                $emails[] = $gEmail;
                            }
                        }
                    }
                }

                $this->logger->info(
                    'Shield: discovered ' . \count($emails)
                    . ' identities for ' . $userId
                    . ' (' . \implode(', ', $emails) . ')'
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Shield: central identity discovery failed: ' . $e->getMessage()
                );
                // Fall through — at least NC email is already in $emails
            }
        } else {
            $this->logger->info(
                'Shield: souvera_central not available — using NC email only for ' . $userId
            );
        }

        return $this->cache[$cacheKey] = $emails;
    }

    private function centralAvailable(): bool
    {
        return \class_exists(self::CENTRAL_STALWART_FQN);
    }
}
