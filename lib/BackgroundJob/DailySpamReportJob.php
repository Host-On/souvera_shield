<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\CentralSettings;
use OCA\SouveraShield\Service\IdentityDiscoveryService;
use OCA\SouveraShield\Service\MailTestService;
use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\Service\PMGException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Daily spam report for Souvera mailboxes — replaces the PMG built-in
 * daily report (which is disabled globally via /config/spam reportstyle).
 *
 * Runs hourly:
 *   1. Enforces `reportstyle = none` on the PMG host when the Central
 *      setting `settings.shield.pmg_report_disable` is active.
 *   2. When `settings.shield.daily_summary` is enabled and the configured
 *      report hour (settings.shield.report_hour, default 6) has been
 *      reached, sends every user a plain-text mail listing the spam,
 *      virus and attachment quarantine entries of the last 24 hours.
 *
 * The report is sent through the workspace's Stalwart relay (anonymous
 * trusted-relay submission, sender spam-report@<domain>) so it lands in
 * the mailbox through normal delivery (Sieve applies, DKIM signed).
 */
class DailySpamReportJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly IUserManager $userManager,
        private readonly IConfig $config,
        private readonly PMGClient $pmg,
        private readonly CentralSettings $central,
        private readonly IdentityDiscoveryService $identityDiscovery,
        private readonly MailTestService $mailTest,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(3600); // hourly — the actual send happens once per day
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $this->enforcePmgReportDisabled();

        if (!$this->central->dailySummaryEnabled()) {
            return;
        }
        if ((int)date('G') < $this->central->reportHour()) {
            return;
        }

        $today = date('Y-m-d');
        $quarantineUrl = $this->urlGenerator->linkToRouteAbsolute('souvera_shield.page.quarantine');
        $this->userManager->callForSeenUsers(function (IUser $user) use ($today, $quarantineUrl): void {
            $this->processUser($user, $today, $quarantineUrl);
        });
    }

    private function enforcePmgReportDisabled(): void {
        if (!$this->central->pmgReportDisableEnabled()) {
            return;
        }
        try {
            $style = $this->pmg->getSpamReportStyle();
            if ($style !== null && $style !== 'none') {
                $this->pmg->setSpamReportStyle('none');
                $this->logger->info(
                    'DailySpamReportJob: disabled PMG built-in spam report (reportstyle=none)',
                    ['app' => Application::APP_ID]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DailySpamReportJob: could not disable PMG spam report: ' . $e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }
    }

    private function processUser(IUser $user, string $today, string $quarantineUrl): void {
        $uid = $user->getUID();
        $email = $user->getEMailAddress();
        if ($email === null || $email === '' || !$this->pmg->isAllowedDomain($email)) {
            return;
        }
        if ($this->config->getUserValue($uid, Application::APP_ID, 'spam_report_sent', '') === $today) {
            return; // already sent today
        }

        $minScore = $this->central->minSpamScore();

        // The report covers EVERY identity of the user — primary address,
        // aliases and shared mailboxes each have their own PMG quarantine.
        $identities = [];
        try {
            $identities = $this->identityDiscovery->discover();
        } catch (\Throwable $e) {
            $this->logger->warning('DailySpamReportJob: identity discovery failed for ' . $uid . ': ' . $e->getMessage());
        }
        if ($identities === []) {
            $identities = [$email];
        }

        $identitySections = [];
        $grandTotal = 0;
        foreach ($identities as $identity) {
            if (!$this->pmg->isAllowedDomain($identity)) {
                continue; // PMG may not manage this domain — skip quietly
            }
            $sections = [
                $this->buildSection('Spam-Quarantäne', fn(): array => $this->fetchQuarantineRows($identity, fn() => $this->pmg->getSpamQuarantine($identity)), $minScore),
                $this->buildSection('Viren-Quarantäne', fn(): array => $this->fetchQuarantineRows($identity, fn() => $this->pmg->getVirusQuarantine($identity)), null),
                $this->buildSection('Anhang-Quarantäne', fn(): array => $this->fetchQuarantineRows($identity, fn() => $this->pmg->getAttachmentQuarantine($identity)), null),
            ];
            $identityTotal = 0;
            foreach ($sections as $section) {
                $identityTotal += $section['count'];
            }
            if ($identityTotal === 0) {
                continue; // keep the report compact
            }
            $grandTotal += $identityTotal;
            $identitySections[] = [
                'email' => $identity,
                'sections' => $sections,
                'count' => $identityTotal,
            ];
        }

        if ($grandTotal === 0) {
            return; // nothing to report — stay quiet
        }

        $body = "Souvera Spam-Report vom " . date('d.m.Y') . "\n\n";
        $body .= "In den letzten 24 Stunden wurden insgesamt "
            . $grandTotal . " Nachricht(en) zurückgehalten:\n\n";

        foreach ($identitySections as $block) {
            $body .= str_repeat('=', 60) . "\n";
            $body .= "Postfach: " . $block['email'] . " (" . $block['count'] . ")\n";
            $body .= str_repeat('=', 60) . "\n";
            foreach ($block['sections'] as $section) {
                if ($section['count'] === 0) {
                    continue;
                }
                $body .= "  " . $section['title'] . ' (' . $section['count'] . ")\n";
                $body .= str_repeat('-', 56) . "\n";
                foreach ($section['rows'] as $row) {
                    $body .= $row . "\n";
                }
                $body .= "\n";
            }
            $body .= "\n";
        }

        $body .= "Quarantäne ansehen, freigeben oder löschen:\n" . $quarantineUrl . "\n";

        try {
            $this->mailTest->sendReportMail(
                $email,
                'spam-report',
                'Souvera Spam-Report – ' . date('d.m.Y'),
                $body,
            );
            $this->config->setUserValue($uid, Application::APP_ID, 'spam_report_sent', $today);
        } catch (\Throwable $e) {
            $this->logger->error(
                'DailySpamReportJob: send failed for ' . $uid . ': ' . $e->getMessage(),
                ['app' => Application::APP_ID, 'exception' => $e]
            );
        }
    }

    /**
     * @param callable():array{data:mixed} $fetch
     * @return array{count:int, rows:list<string>}
     */
    private function buildSection(string $title, callable $fetch, ?float $minScore): array {
        try {
            $rows = $fetch();
        } catch (PMGException $e) {
            $this->logger->warning('DailySpamReportJob: ' . $title . ' fetch failed: ' . $e->getMessage());
            return ['title' => $title, 'count' => 0, 'rows' => []];
        }
        $lines = [];
        $count = 0;
        foreach ($rows as $r) {
            $score = (float)($r['spamlevel'] ?? $r['spam'] ?? $r['score'] ?? 0);
            if ($minScore !== null && $score < $minScore) {
                continue;
            }
            $count++;
            $time = is_numeric($r['time'] ?? null) ? date('d.m. H:i', (int)$r['time']) : (string)($r['time'] ?? '?');
            $from = trim((string)($r['from'] ?? $r['sender'] ?? ''));
            $subject = trim((string)($r['subject'] ?? ''));
            if (mb_strlen($subject) > 60) {
                $subject = mb_substr($subject, 0, 57) . '…';
            }
            $lines[] = sprintf('  %s  %s  %s', $time, $from !== '' ? $from : '(unbekannt)', $subject !== '' ? $subject : '(ohne Betreff)');
        }
        return ['title' => $title, 'count' => $count, 'rows' => $lines];
    }

    /**
     * @param callable():array{data:mixed} $fetch
     * @return list<array<string,mixed>>
     */
    private function fetchQuarantineRows(string $email, callable $fetch): array {
        try {
            $res = $fetch();
        } catch (PMGException $e) {
            throw $e;
        }
        $data = $res['data'] ?? [];
        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }
}
