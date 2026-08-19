<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Db\MailTestMapper;
use OCA\SouveraShield\Service\Reputation\AnalysisRunner;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the full lifecycle of a mail-test session:
 *
 *   1. Create a session with provider.tools (POST /mail-test)
 *
 *   2. Send the reputation e-mail via {@see SmtpMailTestRelay} straight
 *      through the **customer's own Stalwart** – this is the only way
 *      the provider.tools headers reflect the *customer's* server IP,
 *      PTR, SPF, DKIM and DMARC.
 *
 *      ─── v3.8.0 — anonymous submission via trusted-IP relay ───
 *
 *      Stalwart on Souvera-managed workspaces trusts the Nextcloud
 *      worker IP (CloudManager provisions the submission listener that
 *      way), so Shield sends **without SMTP-AUTH** by default. The
 *      envelope sender is `postmaster@<workspace-domain>` – Stalwart
 *      accepts it because the source IP is on the trusted-relay list;
 *      DMARC alignment on the customer domain still holds because
 *      Stalwart signs and relays the mail as usual.
 *
 *      The static config.php keys
 *      `souvera_central.stalwart_mailtest_user/-password` remain as an
 *      opt-in escape hatch: any deployment that cannot expose a
 *      trusted-IP submission listener can pre-provision a mailbox and
 *      re-enable SMTP-AUTH by simply setting both keys.
 *
 *   3. Poll for the result later on – either explicitly through the
 *      `refreshResult()` method (frontend "reload") or through the
 *      {@see \OCA\SouveraShield\BackgroundJob\PollPendingMailTestsJob}.
 *
 * All persistence happens through {@see MailTestMapper} so the class stays
 * unit-testable and the DB is the single source of truth.
 */
class MailTestService {

    private readonly IL10N $l10n;

    public function __construct(
        private readonly ProviderToolsClient $provider,
        private readonly MailTestMapper $tests,
        private readonly SouveraCentralConfig $central,
        private readonly SmtpMailTestRelay $relay,
        private readonly IAppConfig $appConfig,
        private readonly ManagedDomainService $managedDomains,
        private readonly AnalysisRunner $analysisRunner,
        private readonly LoggerInterface $logger,
        IFactory $l10nFactory,
    ) {
        $this->l10n = $l10nFactory->get(Application::APP_ID);
    }

    /**
     * Kick off a new mail-test against the given domain.
     *
     * The record is written to the DB regardless of whether mail delivery
     * succeeds so we always see the attempt in the history.
     */
    public function run(
        DmarcDomain $domain,
        string $triggerType,
        ?string $triggeredBy,
    ): MailTest {
        $entity = new MailTest();
        $entity->setDomainId((int)$domain->getId());
        $entity->setStatus(MailTest::STATUS_PENDING);
        $entity->setTriggerType($triggerType);
        $entity->setTriggeredBy($triggeredBy);
        $entity->setCreatedAt(time());

        try {
            $session = $this->provider->createMailTest();
        } catch (ProviderToolsException $e) {
            $entity->setStatus(MailTest::STATUS_ERROR);
            $entity->setErrorMessage($this->truncateForDb($e->getMessage()));
            $entity->setTestId('');
            $entity->setTestEmail('');
            $this->logger->error('mail-test session could not be created', [
                'app' => Application::APP_ID,
                'domain' => $domain->getDomain(),
                'exception' => $e,
            ]);
            $saved = $this->tests->insert($entity);
            $this->triggerIncidentDetection();
            return $saved;
        }

        $entity->setTestId($session['testId']);
        $entity->setTestEmail($session['testEmail']);

        try {
            $this->dispatchEmail($domain, $session['testEmail']);
            $entity->setStatus(MailTest::STATUS_SENT);
            $entity->setSentAt(time());
        } catch (\Throwable $e) {
            $entity->setStatus(MailTest::STATUS_ERROR);
            $entity->setErrorMessage($this->truncateForDb($this->l10n->t('Mail dispatch failed: %s', [$e->getMessage()])));
            $this->logger->error('mail-test e-mail dispatch failed', [
                'app' => Application::APP_ID,
                'domain' => $domain->getDomain(),
                'exception' => $e,
            ]);
        }

        $saved = $this->tests->insert($entity);
        if ($saved->getStatus() === MailTest::STATUS_ERROR) {
            $this->triggerIncidentDetection();
        }
        return $saved;
    }

    /**
     * Refresh a single test row: poll provider.tools and – if the run
     * has finished server-side – persist the outcome.
     *
     * provider.tools status values:
     *   - "pending"  → mail not yet received
     *   - "received" → mail received & analysed (final state)
     *   - "expired"  → 1 h passed, no mail received (final state)
     */
    public function refreshResult(MailTest $entity): MailTest {
        if ($entity->getStatus() === MailTest::STATUS_COMPLETED
            || $entity->getStatus() === MailTest::STATUS_ERROR) {
            return $entity;
        }
        if ($entity->getTestId() === '') {
            return $entity;
        }

        try {
            $data = $this->provider->getMailTest($entity->getTestId());
        } catch (ProviderToolsException $e) {
            $entity->setErrorMessage($this->truncateForDb($e->getMessage()));
            return $this->tests->update($entity);
        }

        $status = strtolower((string)($data['status'] ?? 'pending'));

        // Server-side final: never received.
        if ($status === 'expired') {
            $entity->setStatus(MailTest::STATUS_ERROR);
            $entity->setErrorMessage($this->truncateForDb(
                $this->l10n->t('The test expired – the e-mail was not received within 1 hour.')
            ));
            $entity->setCompletedAt(time());
            $entity->setRawResult(json_encode($data, JSON_UNESCAPED_SLASHES));
            $saved = $this->tests->update($entity);
            $this->triggerIncidentDetection();
            return $saved;
        }

        // Still waiting – nothing to do (safety-net: also fail after 65 min
        // locally so we don't poll forever if the server never flips to
        // expired for some reason).
        if ($status === 'pending' || $status === 'waiting') {
            $age = time() - (int)$entity->getCreatedAt();
            if ($age > 65 * 60) {
                $entity->setStatus(MailTest::STATUS_ERROR);
                $entity->setErrorMessage($this->truncateForDb(
                    $this->l10n->t('Timed out waiting for the test mail to be received.')
                ));
                $entity->setCompletedAt(time());
                $saved = $this->tests->update($entity);
                $this->triggerIncidentDetection();
                return $saved;
            }
            return $entity;
        }

        // status === "received"  →  we have a full analysis.
        $entity->setStatus(MailTest::STATUS_COMPLETED);
        $entity->setCompletedAt(time());
        $entity->setScore($this->pickFloat($data, ['score', 'total', 'points']));
        $entity->setSpfResult(
            $this->pickResult($data, ['spfResult', 'spf_result'])
            ?? $this->pickNested($data, ['analysis', 'spf'])
        );
        $entity->setDkimResult(
            $this->pickResult($data, ['dkimResult', 'dkim_result'])
            ?? $this->pickNested($data, ['analysis', 'dkim'])
        );
        $entity->setDmarcResult(
            $this->pickResult($data, ['dmarcResult', 'dmarc_result'])
            ?? $this->pickNested($data, ['analysis', 'dmarc'])
        );
        $entity->setRawResult(json_encode($data, JSON_UNESCAPED_SLASHES));

        $saved = $this->tests->update($entity);
        $this->triggerIncidentDetection();
        return $saved;
    }

    /**
     * Run the full incident detection right after a mail-test reached a
     * final state (completed / error), so problems like a failed DKIM
     * signature show up on the "Vorfälle" page immediately instead of
     * waiting for the daily analysis job.
     */
    private function triggerIncidentDetection(): void {
        try {
            $domain = $this->managedDomains->getOrCreate();
            if ($domain === null) {
                return;
            }
            $this->analysisRunner->run($domain, refreshChecks: true);
        } catch (\Throwable $e) {
            $this->logger->warning('Automatic incident detection after mail-test failed', [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);
        }
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * Resolve the SMTP sender + optional AUTH credentials.
     *
     * v3.8.0 flow:
     *   - Default: **anonymous submission**. `smtpUser`/`smtpPassword`
     *     empty, `authRequired=false` in the resulting relay config;
     *     `fromAddress = postmaster@<workspace-domain>`. Stalwart
     *     accepts the mail because the Nextcloud worker IP is on the
     *     trusted-relay list (provisioned by CloudManager).
     *   - Escape hatch: if BOTH `souvera_central.stalwart_mailtest_user`
     *     AND `souvera_central.stalwart_mailtest_password` are set,
     *     Shield falls back to authenticated submission with that
     *     mailbox – used by deployments that cannot expose a
     *     trusted-IP submission listener.
     *
     * @return array{fromAddress:string,smtpUser:string,smtpPassword:string,source:string}
     * @throws MailTestRelayException on domain/user mismatch
     */
    private function resolveCredentials(DmarcDomain $domain): array {
        $staticUser = strtolower(trim((string)($this->central->read('stalwart_mailtest_user') ?? '')));
        $staticPass = (string)($this->central->read('stalwart_mailtest_password') ?? '');

        if ($staticUser !== '' && $staticPass !== '') {
            $suffix = '@' . strtolower($domain->getDomain());
            if (!str_ends_with($staticUser, $suffix)) {
                throw new MailTestRelayException(
                    $this->l10n->t(
                        'souvera_central.stalwart_mailtest_user ("%1$s") does not belong to workspace '
                        . 'domain %2$s. The reputation test must be sent from the tested domain '
                        . '(SPF/DKIM/DMARC alignment) – please use a mailbox such as mailtest%3$s.',
                        [$staticUser, $domain->getDomain(), $suffix],
                    ),
                    MailTestRelayException::STAGE_CONFIG,
                );
            }
            return [
                'fromAddress' => $staticUser,
                'smtpUser'    => $staticUser,
                'smtpPassword'=> $staticPass,
                'source'      => 'static',
            ];
        }

        // Anonymous default: trusted-IP relay on Stalwart accepts our
        // Nextcloud worker without SMTP-AUTH. Envelope sender is the
        // postmaster of the workspace domain.
        return [
            'fromAddress' => $this->deriveSenderAddress($domain),
            'smtpUser'    => '',
            'smtpPassword'=> '',
            'source'      => 'anonymous',
        ];
    }

    private function dispatchEmail(DmarcDomain $domain, string $testEmail): void {
        $stalwartUrl  = $this->central->read('stalwart_api_url');
        $portOverride = $this->central->read('stalwart_smtp_port');
        $hostOverride = $this->central->read('stalwart_smtp_host');

        $creds = $this->resolveCredentials($domain);
        $fromAddress = $creds['fromAddress'];
        $smtpUser    = $creds['smtpUser'];
        $smtpPass    = $creds['smtpPassword'];
        $useStatic   = $creds['source'] === 'static';

        $subject   = 'Souvera Shield – weekly mail reputation check for ' . $domain->getDomain();
        $plainBody =
            "This is an automated test message sent by Souvera Shield to\n"
            . "verify the mail-server reputation for domain " . $domain->getDomain() . ".\n\n"
            . "You can safely discard this message – no human interaction is\n"
            . "required. The receiving mailbox is a special test\n"
            . "endpoint that will analyse the message headers.\n";

        // Port auto-detection: Stalwart's DEFAULT deployment opens
        // listeners on 25 (smtp) and 465 (submissions/implicit TLS) but
        // NOT on 587 – so a fixed 587 default fails with a connect error
        // even though the server is perfectly reachable. We therefore try
        // the candidates in order and remember the first port that
        // accepts a TCP connection. An explicit
        // souvera_central.stalwart_smtp_port override disables detection.
        $ports = $this->relayPortCandidates($portOverride);
        $connectErrors = [];
        $relayConfig = null;
        foreach ($ports as $port) {
            $relayConfig = MailTestRelayConfig::fromStalwart($stalwartUrl, $port, $smtpUser, $smtpPass, $hostOverride);
            if ($relayConfig === null) {
                throw new MailTestRelayException(
                    $this->l10n->t(
                        'Stalwart relay is not configured. The reputation test must be sent through the '
                        . 'customer\'s Stalwart server (so the test measures the correct IP, SPF, DKIM '
                        . 'and DMARC). Please set `souvera_central.stalwart_api_url` (or '
                        . '`souvera_central.stalwart_smtp_host`) in the Nextcloud `config.php`.'
                    ),
                    MailTestRelayException::STAGE_CONFIG,
                );
            }

            try {
                $this->relay->send(
                    config:      $relayConfig,
                    fromAddress: $fromAddress,
                    to:          $testEmail,
                    fromDisplay: 'Souvera Shield',
                    subject:     $subject,
                    plainBody:   $plainBody,
                );
                $this->rememberRelayPort($port);
                return;
            } catch (MailTestRelayException $e) {
                if ($e->stage === MailTestRelayException::STAGE_CONNECT) {
                    $connectErrors[] = 'Port ' . $port . ': ' . $e->getMessage();
                    continue; // try the next candidate port
                }
                throw new \RuntimeException(
                    $this->interpretMailerFailure($e->getMessage(), $testEmail, $e->stage, $relayConfig, $fromAddress, $useStatic),
                    0,
                    $e,
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    $this->interpretMailerFailure($e->getMessage(), $testEmail, MailTestRelayException::STAGE_CONNECT, $relayConfig, $fromAddress, $useStatic),
                    0,
                    $e,
                );
            }
        }

        // Every candidate port failed at TCP connect.
        $this->logger->error('mail-test SMTP connect failed on every candidate port', [
            'app'    => Application::APP_ID,
            'host'   => $relayConfig?->smtpHost,
            'ports'  => $ports,
            'errors' => $connectErrors,
        ]);
        throw new \RuntimeException(
            $this->interpretMailerFailure(
                implode(' | ', $connectErrors),
                $testEmail,
                MailTestRelayException::STAGE_CONNECT,
                $relayConfig,
                $fromAddress,
                $useStatic,
                $ports,
            ),
        );
    }

    /**
     * Send an internal notification mail (e.g. the daily spam report)
     * through the workspace's Stalwart relay to a local mailbox.
     *
     * Sender = `spam-report@<recipient-domain>` via the anonymous
     * trusted-relay submission — the mail runs through normal delivery
     * (Sieve applies) and is signed with the workspace's DKIM. When the
     * static mail-test credentials are configured, those are used instead
     * (authenticated submission with sender alignment).
     *
     * @throws \RuntimeException on transport/config failure
     */
    public function sendReportMail(string $toEmail, string $fromLocal, string $subject, string $plainBody): void {
        $at = strrpos($toEmail, '@');
        if ($at === false || $at === 0) {
            throw new \InvalidArgumentException('Invalid recipient address');
        }
        $domain = strtolower(substr($toEmail, $at + 1));

        $stalwartUrl  = $this->central->read('stalwart_api_url');
        $portOverride = $this->central->read('stalwart_smtp_port');
        $hostOverride = $this->central->read('stalwart_smtp_host');

        $staticUser = strtolower(trim((string)($this->central->read('stalwart_mailtest_user') ?? '')));
        $staticPass = (string)($this->central->read('stalwart_mailtest_password') ?? '');

        $smtpUser = '';
        $smtpPass = '';
        if ($staticUser !== '' && $staticPass !== '') {
            $smtpUser = $staticUser;
            $smtpPass = $staticPass;
            $fromAddress = $staticUser;
        } else {
            $fromAddress = strtolower(trim($fromLocal)) . '@' . $domain;
        }

        $ports = $this->relayPortCandidates($portOverride);
        $connectErrors = [];
        $relayConfig = null;
        foreach ($ports as $port) {
            $relayConfig = MailTestRelayConfig::fromStalwart($stalwartUrl, $port, $smtpUser, $smtpPass, $hostOverride);
            if ($relayConfig === null) {
                throw new \RuntimeException(
                    'Stalwart relay is not configured. Set `souvera_central.stalwart_api_url` '
                    . '(or `souvera_central.stalwart_smtp_host`) in config.php.'
                );
            }
            try {
                $this->relay->send(
                    config:      $relayConfig,
                    fromAddress: $fromAddress,
                    to:          $toEmail,
                    fromDisplay: 'Souvera Shield',
                    subject:     $subject,
                    plainBody:   $plainBody,
                );
                $this->rememberRelayPort($port);
                return;
            } catch (MailTestRelayException $e) {
                if ($e->stage === MailTestRelayException::STAGE_CONNECT) {
                    $connectErrors[] = 'Port ' . $port . ': ' . $e->getMessage();
                    continue;
                }
                throw new \RuntimeException($e->getMessage(), 0, $e);
            }
        }
        throw new \RuntimeException(
            'SMTP connect failed on every candidate port: ' . implode(' | ', $connectErrors)
        );
    }

    /**
     * SMTP port candidates: an explicit override wins and disables
     * detection; otherwise the last known-good port (cached in the app
     * config) is tried first, then 587 (submission), 465 (implicit TLS,
     * Stalwart default), 25.
     *
     * @return list<int>
     */
    private function relayPortCandidates(int|string|null $override): array {
        if ($override !== null && $override !== '' && (int)$override > 0) {
            return [(int)$override];
        }
        $candidates = [];
        $cached = (int)$this->appConfig->getValueString(
            Application::APP_ID,
            'stalwart_smtp_port_detected',
            '',
            lazy: true,
        );
        if ($cached > 0) {
            $candidates[] = $cached;
        }
        foreach ([587, 465, 25] as $port) {
            if (!in_array($port, $candidates, true)) {
                $candidates[] = $port;
            }
        }
        return $candidates;
    }

    private function rememberRelayPort(int $port): void {
        $this->appConfig->setValueString(
            Application::APP_ID,
            'stalwart_smtp_port_detected',
            (string)$port,
            lazy: true,
        );
    }

    /**
     * Sender = AUTH principal. Defaults to `postmaster@<domain>`; the
     * `provider_tools_sender` override (already stored on the entity) is
     * honoured as long as it belongs to the domain under test – anything
     * else would break both Stalwart's sender-alignment and DMARC.
     */
    private function deriveSenderAddress(DmarcDomain $domain): string {
        $sender = strtolower(trim((string)$domain->getSenderAddress()));
        $suffix = '@' . strtolower($domain->getDomain());
        if ($sender === '' || !str_ends_with($sender, $suffix) || str_starts_with($sender, '@')) {
            return 'postmaster' . $suffix;
        }
        return $sender;
    }

    /**
     * Turn a raw SMTP / mailer error into a diagnostic message that tells
     * the operator *where* to fix the underlying deployment issue.
     *
     * v3.8.0 semantics: default flow is anonymous submission through the
     * trusted-IP Stalwart submission listener. Static config keys
     * (`souvera_central.stalwart_mailtest_user/-password`) opt back into
     * SMTP-AUTH. Diagnostic messages differentiate the two paths so the
     * operator sees the right runbook.
     */
    private function interpretMailerFailure(
        string $raw,
        string $testEmail,
        string $stage,
        MailTestRelayConfig $relay,
        string $fromAddress,
        bool $staticCredentials = false,
        array $triedPorts = [],
    ): string {
        $l = $this->l10n;
        $prefix = $l->t('Reputation check could not be sent.');
        $tail   = ' ' . $l->t('Error message: %s', [$raw]);
        $where  = ' [Stage: ' . $stage . ']';
        $authNote = $staticCredentials
            ? ' ' . $l->t('(SMTP-AUTH as %s)', [$fromAddress])
            : ' ' . $l->t('(anonymous / trusted-IP as %s)', [$fromAddress]);
        $relayInfo = ' ' . $l->t('Relay: %1$s:%2$d', [$relay->smtpHost, $relay->smtpPort]) . $authNote . '.';

        switch ($stage) {
            case MailTestRelayException::STAGE_CONFIG:
                return $prefix . $where . ' ' . $raw;

            case MailTestRelayException::STAGE_CONNECT:
                $portList = $triedPorts !== []
                    ? ' ' . $l->t('(tested ports: %s)', [implode(', ', $triedPorts)])
                    : '';
                return $prefix . $where . ' '
                    . $l->t('The Stalwart server is not reachable via SMTP')
                    . $portList . ': ' . $raw . ' '
                    . $l->t(
                        'Important: the connection must work from the Nextcloud webserver process '
                        . '(not just from the shell or other apps). If the SMTP host differs from '
                        . 'the API host (e.g. reverse proxy in front of souvera_central.stalwart_api_url), '
                        . 'set souvera_central.stalwart_smtp_host; port via '
                        . 'souvera_central.stalwart_smtp_port (default 587).'
                    )
                    . $relayInfo;

            case MailTestRelayException::STAGE_STARTTLS:
                return $prefix . $where . ' '
                    . $l->t(
                        'TLS handshake with Stalwart on port %d failed. Please verify the Stalwart '
                        . 'certificate or adjust the port (souvera_central.stalwart_smtp_port).',
                        [$relay->smtpPort],
                    )
                    . $relayInfo . $tail;

            case MailTestRelayException::STAGE_AUTH:
                if ($staticCredentials) {
                    return $prefix . $where . ' '
                        . $l->t(
                            'SMTP-AUTH as %s using the credentials from config.php '
                            . '(souvera_central.stalwart_mailtest_user / '
                            . 'souvera_central.stalwart_mailtest_password) failed. '
                            . 'Please verify the credentials – they must belong to an existing '
                            . 'mailbox in the (external) directory of the Stalwart server.',
                            [$fromAddress],
                        )
                        . $relayInfo . $tail;
                }
                return $prefix . $where . ' '
                    . $l->t(
                        'Stalwart requires SMTP-AUTH but Shield sends without AUTH (default since v3.8.0). '
                        . 'The Nextcloud worker IP does not seem to be on the trusted-relay list of the '
                        . 'Stalwart submission listener. Two options: (1) instruct CloudManager to add the '
                        . 'Nextcloud IP as a trusted source on the Stalwart listener on port %d, or '
                        . '(2) set souvera_central.stalwart_mailtest_user and '
                        . 'souvera_central.stalwart_mailtest_password in the Nextcloud config.php as a '
                        . 'transitional workaround.',
                        [$relay->smtpPort],
                    )
                    . $relayInfo . $tail;

            case MailTestRelayException::STAGE_FROM:
                $atPos    = strrpos($fromAddress, '@');
                $fromHost = $atPos === false ? '' : substr($fromAddress, $atPos + 1);
                $extra = $staticCredentials
                    ? $l->t('and whether sender-rewriting rules block the sender')
                    : $l->t('and whether the trusted-IP entry on the submission listener permits '
                        . 'sending on behalf of this domain');
                return $prefix . $where . ' '
                    . $l->t(
                        'Stalwart rejects sender "%1$s". Please verify in Stalwart whether the domain "%2$s" '
                        . 'is configured as a local sending domain %3$s.',
                        [$fromAddress, $fromHost, $extra],
                    )
                    . $relayInfo . $tail;

            case MailTestRelayException::STAGE_RCPT:
                $rcptExtra = $staticCredentials
                    ? $l->t(
                        'Please verify in Stalwart whether authenticated users are allowed to relay '
                        . 'externally (session/relay policy for the listener on port %d).',
                        [$relay->smtpPort],
                    )
                    : $l->t(
                        'The Nextcloud worker IP is accepted for the session but not allowed to relay '
                        . 'externally. Please verify in CloudManager that the trusted-IP entry on the '
                        . 'Stalwart listener on port %d includes the relay grant for external recipients.',
                        [$relay->smtpPort],
                    );
                return $prefix . $where . ' '
                    . $l->t('Stalwart rejects external recipient %s.', [$testEmail]) . ' '
                    . $rcptExtra
                    . $relayInfo . $tail;

            case MailTestRelayException::STAGE_DATA:
                return $prefix . $where . ' '
                    . $l->t(
                        'Stalwart rejected the message after DATA (content filter / spam policy). '
                        . 'Please check the DATA reject reason in the Stalwart log.'
                    )
                    . $relayInfo . $tail;

            default:
                return $prefix . $where . $relayInfo . $tail;
        }
    }

    /**
     * Bound the size of a diagnostic message before it is stored in
     * `error_message` (TEXT since the v2.4.2 migration). 1000 chars keep
     * the row small while preserving the per-port connect details that
     * the operator needs.
     */
    private function truncateForDb(?string $message): ?string {
        if ($message === null) {
            return null;
        }
        $limit = 1000;
        if (mb_strlen($message) <= $limit) {
            return $message;
        }
        return mb_substr($message, 0, $limit - 1) . '…';
    }

    /**
     * Look at a direct field like $data['spfResult'] which is either a
     * plain string ("pass", "fail", …) or an object containing a nested
     * `result` / `status` / `verdict` field.
     *
     * @param array<string,mixed> $data
     * @param string[]           $keys
     */
    private function pickResult(array $data, array $keys): ?string {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = $data[$key];
            if (is_array($value)) {
                foreach (['result', 'status', 'verdict'] as $nested) {
                    if (isset($value[$nested]) && is_string($value[$nested])) {
                        return strtolower($value[$nested]);
                    }
                }
                continue;
            }
            if (is_string($value)) {
                return strtolower($value);
            }
        }
        return null;
    }

    /**
     * Look at $data[$parent][$child]['result'] – used to reach into the
     * `analysis` sub-object which provider.tools exposes on "received"
     * results (e.g. analysis.spf.result).
     *
     * @param array<string,mixed> $data
     * @param array{0:string,1:string}   $path  [parentKey, childKey]
     */
    private function pickNested(array $data, array $path): ?string {
        [$parent, $child] = $path;
        $sub = $data[$parent] ?? null;
        if (!is_array($sub)) {
            return null;
        }
        $node = $sub[$child] ?? null;
        if (is_string($node)) {
            return strtolower($node);
        }
        if (is_array($node)) {
            foreach (['result', 'status', 'verdict'] as $nested) {
                if (isset($node[$nested]) && is_string($node[$nested])) {
                    return strtolower($node[$nested]);
                }
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @param string[]           $keys
     */
    private function pickFloat(array $data, array $keys): ?float {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float)$data[$key];
            }
        }
        return null;
    }
}
