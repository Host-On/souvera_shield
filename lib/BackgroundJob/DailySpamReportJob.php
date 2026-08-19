<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
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
 * Runs every 5 minutes (so the daily send lands within minutes of the
 * configured report hour):
 *   1. Enforces `reportstyle = none` on the PMG host when the Central
 *      setting `settings.shield.pmg_report_disable` is active.
 *   2. When `settings.shield.daily_summary` is enabled and the configured
 *      report hour (settings.shield.report_hour, default 6) has been
 *      reached — evaluated in the tenant's configured timezone — sends
 *      every user a mail (multipart text+HTML) listing the spam, virus
 *      and attachment quarantine entries of the last 24 hours for ALL
 *      their identities (primary address, aliases, shared mailboxes).
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
        // 5-minute ticks: the send window opens at the configured hour and
        // the marker below guarantees exactly one send per user per day.
        $this->setInterval(300);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $this->enforcePmgReportDisabled();

        if (!$this->central->dailySummaryEnabled()) {
            return;
        }

        $now = $this->nowInTenantTimezone();
        if ((int)$now->format('G') < $this->central->reportHour()) {
            return;
        }

        $today = $now->format('Y-m-d');
        $displayDate = $now->format('d.m.Y');
        $quarantineUrl = $this->urlGenerator->linkToRouteAbsolute('souvera_shield.page.quarantine');
        $this->userManager->callForSeenUsers(function (IUser $user) use ($today, $displayDate, $quarantineUrl): void {
            $this->processUser($user, $today, $displayDate, $quarantineUrl);
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

    /**
     * Current date/time in the tenant's timezone. The report hour is a
     * tenant setting and must therefore be interpreted in the tenant's
     * timezone (Nextcloud `default_timezone`), not in the server's PHP
     * default — otherwise tenants in other zones get their report at the
     * wrong local time.
     */
    private function nowInTenantTimezone(): DateTimeImmutable {
        $tz = $this->config->getSystemValueString('default_timezone', date_default_timezone_get());
        try {
            $zone = new DateTimeZone($tz);
        } catch (\Throwable $e) {
            $this->logger->warning('DailySpamReportJob: invalid default_timezone "' . $tz . '" — falling back to PHP default');
            $zone = new DateTimeZone(date_default_timezone_get());
        }
        return new DateTimeImmutable('now', $zone);
    }

    private function processUser(IUser $user, string $today, string $displayDate, string $quarantineUrl): void {
        $uid = $user->getUID();
        $email = $user->getEMailAddress();
        if ($email === null || $email === '' || !$this->pmg->isAllowedDomain($email)) {
            return;
        }
        if ($this->config->getUserValue($uid, Application::APP_ID, 'spam_report_sent', '') === $today) {
            return; // already sent today
        }

        $minScore = $this->central->minSpamScore();
        $zone = $this->nowInTenantTimezone()->getTimezone();

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
                $this->buildSection('Spam-Quarantäne', fn(): array => $this->fetchQuarantineRows($identity, fn() => $this->pmg->getSpamQuarantine($identity)), $minScore, $zone),
                $this->buildSection('Viren-Quarantäne', fn(): array => $this->fetchQuarantineRows($identity, fn() => $this->pmg->getVirusQuarantine($identity)), null, $zone),
                $this->buildSection('Anhang-Quarantäne', fn(): array => $this->fetchQuarantineRows($identity, fn() => $this->pmg->getAttachmentQuarantine($identity)), null, $zone),
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

        $plainBody = $this->renderPlain($displayDate, $grandTotal, $identitySections, $quarantineUrl);
        $htmlBody = $this->renderHtml($displayDate, $grandTotal, $identitySections, $quarantineUrl);

        try {
            $this->mailTest->sendReportMail(
                $email,
                'spam-report',
                'Souvera Spam-Report – ' . $displayDate,
                $plainBody,
                $htmlBody,
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
     * @param list<array{email:string, sections:list<array{title:string, count:int, rows:list<array{time:string, from:string, subject:string}>}>, count:int}> $identitySections
     */
    private function renderPlain(string $displayDate, int $grandTotal, array $identitySections, string $quarantineUrl): string {
        $body = "Souvera Spam-Report vom " . $displayDate . "\n\n";
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
                    $from = $row['from'] !== '' ? $row['from'] : '(unbekannt)';
                    $subject = $row['subject'] !== '' ? $row['subject'] : '(ohne Betreff)';
                    $body .= '  ' . $row['time'] . '  ' . $from . '  ' . $subject . "\n";
                }
                $body .= "\n";
            }
            $body .= "\n";
        }

        $body .= "Quarantäne ansehen, freigeben oder löschen:\n" . $quarantineUrl . "\n";
        return $body;
    }

    /**
     * @param list<array{email:string, sections:list<array{title:string, count:int, rows:list<array{time:string, from:string, subject:string}>}>, count:int}> $identitySections
     */
    private function renderHtml(string $displayDate, int $grandTotal, array $identitySections, string $quarantineUrl): string {
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = $h($quarantineUrl);

        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Souvera Spam-Report</title></head>'
            . '<body style="margin:0;padding:0;background:#f2f4f8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1d2530;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f8;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e3e8ef;">';

        // Header band
        $html .= '<tr><td style="background:#0a5cf5;padding:22px 28px;">'
            . '<div style="font-size:20px;font-weight:700;color:#ffffff;">Souvera Shield</div>'
            . '<div style="font-size:13px;color:#cfe0ff;margin-top:2px;">Täglicher Spam-Report</div>'
            . '</td></tr>';

        // Summary
        $plural = $grandTotal === 1 ? '' : 'n';
        $html .= '<tr><td style="padding:24px 28px 8px 28px;">'
            . '<div style="font-size:16px;font-weight:600;">Hallo,</div>'
            . '<div style="font-size:14px;line-height:1.6;margin-top:8px;">'
            . 'hier ist Ihr Spam-Report vom <strong>' . $h($displayDate) . '</strong>. In den letzten 24 Stunden '
            . 'wurde' . $plural . ' insgesamt <strong>' . $grandTotal . ' Nachricht' . $plural . '</strong> für Sie zurückgehalten.'
            . '</div></td></tr>';

        // Mailbox blocks
        foreach ($identitySections as $block) {
            $html .= '<tr><td style="padding:20px 28px 4px 28px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e8ef;border-radius:10px;">'
                . '<tr><td style="background:#f7f9fc;border-radius:10px 10px 0 0;padding:12px 16px;">'
                . '<span style="font-size:14px;font-weight:700;">' . $h($block['email']) . '</span>'
                . '&nbsp;&nbsp;<span style="font-size:12px;font-weight:600;color:#ffffff;background:#64748b;border-radius:999px;padding:2px 10px;display:inline-block;">'
                . $block['count'] . '</span>'
                . '</td></tr>';

            foreach ($block['sections'] as $section) {
                if ($section['count'] === 0) {
                    continue;
                }
                $html .= '<tr><td style="padding:14px 16px 4px 16px;font-size:12px;font-weight:700;color:#0a5cf5;text-transform:uppercase;letter-spacing:0.04em;">'
                    . $h($section['title']) . ' (' . $section['count'] . ')</td></tr>';
                $html .= '<tr><td style="padding:6px 16px 14px 16px;">'
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
                    . '<tr>'
                    . '<th align="left" style="font-size:11px;color:#94a3b8;font-weight:600;padding:6px 8px;border-bottom:1px solid #eef2f7;white-space:nowrap;">ZEIT</th>'
                    . '<th align="left" style="font-size:11px;color:#94a3b8;font-weight:600;padding:6px 8px;border-bottom:1px solid #eef2f7;white-space:nowrap;">VON</th>'
                    . '<th align="left" style="font-size:11px;color:#94a3b8;font-weight:600;padding:6px 8px;border-bottom:1px solid #eef2f7;">BETREFF</th>'
                    . '</tr>';
                foreach ($section['rows'] as $row) {
                    $from = $row['from'] !== '' ? $row['from'] : '(unbekannt)';
                    $subject = $row['subject'] !== '' ? $row['subject'] : '(ohne Betreff)';
                    $html .= '<tr>'
                        . '<td style="font-size:13px;padding:6px 8px;border-bottom:1px solid #f4f6f9;white-space:nowrap;color:#64748b;">' . $h($row['time']) . '</td>'
                        . '<td style="font-size:13px;padding:6px 8px;border-bottom:1px solid #f4f6f9;color:#334155;">' . $h($from) . '</td>'
                        . '<td style="font-size:13px;padding:6px 8px;border-bottom:1px solid #f4f6f9;color:#0f172a;">' . $h($subject) . '</td>'
                        . '</tr>';
                }
                $html .= '</table></td></tr>';
            }
            $html .= '</table></td></tr>';
        }

        // CTA
        $html .= '<tr><td align="center" style="padding:24px 28px 4px 28px;">'
            . '<a href="' . $url . '" style="display:inline-block;background:#0a5cf5;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 24px;border-radius:8px;">'
            . 'Quarantäne öffnen'
            . '</a>'
            . '</td></tr>';

        // Footer
        $html .= '<tr><td style="padding:20px 28px 28px 28px;">'
            . '<div style="font-size:12px;color:#94a3b8;line-height:1.5;border-top:1px solid #eef2f7;padding-top:14px;">'
            . 'Diese Nachricht wurde automatisch erstellt. In der Quarantäne können Sie zurückgehaltene Nachrichten prüfen, freigeben oder löschen.<br>'
            . 'Antworten auf diese E-Mail werden nicht gelesen.'
            . '</div></td></tr>';

        $html .= '</table></td></tr></table></body></html>';
        return $html;
    }

    /**
     * @param callable():array{data:mixed} $fetch
     * @return array{title:string, count:int, rows:list<array{time:string, from:string, subject:string}>}
     */
    private function buildSection(string $title, callable $fetch, ?float $minScore, DateTimeZone $zone): array {
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
            $time = is_numeric($r['time'] ?? null)
                ? (new DateTimeImmutable('@' . (int)$r['time']))->setTimezone($zone)->format('d.m. H:i')
                : (string)($r['time'] ?? '?');
            $from = trim((string)($r['from'] ?? $r['sender'] ?? ''));
            $subject = trim((string)($r['subject'] ?? ''));
            if (mb_strlen($subject) > 60) {
                $subject = mb_substr($subject, 0, 57) . '…';
            }
            $lines[] = ['time' => $time, 'from' => $from, 'subject' => $subject];
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
