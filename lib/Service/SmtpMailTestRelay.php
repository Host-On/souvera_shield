<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCA\SouveraShield\AppInfo\Application;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * A tiny purpose-built SMTP client that sends the reputation test mail
 * through Souvera's Stalwart relay, authenticated as the shared admin
 * identity exposed by Souvera Central.
 *
 * Design notes (v3.3.3):
 *
 *  - Souvera's Stalwart deployments use SMTPS on port 465 (implicit
 *    TLS) by default. Hoster can override via config.php key
 *    `souvera_central.stalwart_smtp_port`.
 *
 *  - Stalwart typically lives on an *internal* IP with a self-signed
 *    TLS cert. We therefore relax cert verification
 *    (verify_peer=false, allow_self_signed=true) both for implicit
 *    TLS (465) and any STARTTLS upgrade on plain-TCP ports.
 *
 *  - On plain-TCP ports (25/587) STARTTLS is opportunistic:
 *      • connect plain TCP
 *      • parse EHLO reply for STARTTLS capability
 *      • upgrade if advertised; fall through to plain otherwise
 *        (operator explicitly accepted plain-in-LAN risk).
 *
 *  - For port 465 we open ssl:// directly – the stream wrapper handles
 *    the whole TLS handshake before EHLO.
 *
 *  - Every AUTH exchange still requires an EHLO afterwards (RFC 3207).
 */
class SmtpMailTestRelay {

    private const CONNECT_TIMEOUT = 8;
    private const IO_TIMEOUT      = 30;

    /** @var resource|null */
    private $stream = null;

    private bool $tlsUpgraded = false;

    private readonly IL10N $l10n;

    public function __construct(
        private readonly LoggerInterface $logger,
        IFactory $l10nFactory,
    ) {
        $this->l10n = $l10nFactory->get(Application::APP_ID);
    }

    /**
     * Send a single plain-text mail. Throws {@see MailTestRelayException}
     * with the stage label filled in for the operator.
     *
     * @param string $fromAddress  Envelope + header From. Must equal (or
     *                             be an alias of) the SMTP-AUTH principal
     *                             so Stalwart's sender-alignment accepts
     *                             the message.
     */
    public function send(
        MailTestRelayConfig $config,
        string $fromAddress,
        string $to,
        string $fromDisplay,
        string $subject,
        string $plainBody,
    ): void {
        try {
            $this->connect($config);
            $this->readReply(220, MailTestRelayException::STAGE_GREETING);

            $ehloHost = $this->deriveEhloHost($fromAddress);

            // First EHLO – get server capabilities.
            $this->writeLine("EHLO {$ehloHost}");
            $ehloReply = $this->readReply(250, MailTestRelayException::STAGE_EHLO);

            // Opportunistic STARTTLS – but only if we're not already
            // on implicit TLS (SMTPS/465) and the server advertises it.
            if (!$config->usesImplicitTls() && $this->supportsStartTls($ehloReply)) {
                if ($this->tryStartTls($config)) {
                    $this->tlsUpgraded = true;
                    // RFC 3207 § 4.2: re-issue EHLO after TLS.
                    $this->writeLine("EHLO {$ehloHost}");
                    $this->readReply(250, MailTestRelayException::STAGE_EHLO);
                }
            }

            if ($config->authRequired) {
                $this->writeLine('AUTH LOGIN');
                $this->readReply(334, MailTestRelayException::STAGE_AUTH);
                $this->writeLine(base64_encode($config->smtpUser));
                $this->readReply(334, MailTestRelayException::STAGE_AUTH);
                $this->writeLine(base64_encode($config->smtpPassword));
                $this->readReply(235, MailTestRelayException::STAGE_AUTH);
            }

            $this->writeLine('MAIL FROM:<' . $fromAddress . '>');
            $this->readReply(250, MailTestRelayException::STAGE_FROM);

            $this->writeLine('RCPT TO:<' . $to . '>');
            $this->readReply(250, MailTestRelayException::STAGE_RCPT);

            $this->writeLine('DATA');
            $this->readReply(354, MailTestRelayException::STAGE_DATA);

            $message = $this->buildMessage(
                fromAddress: $fromAddress,
                fromDisplay: $fromDisplay,
                to:          $to,
                subject:     $subject,
                plainBody:   $plainBody,
            );
            $this->writeRaw($message . "\r\n.\r\n");
            $this->readReply(250, MailTestRelayException::STAGE_DATA);

            $this->writeLine('QUIT');
            // 221 is expected but non-fatal if the server just closes.
            @$this->readReply(221, MailTestRelayException::STAGE_QUIT);
        } finally {
            $this->closeStream();
        }
    }

    /** True iff STARTTLS was actually enabled during the last send() call. */
    public function wasTlsUpgraded(): bool {
        return $this->tlsUpgraded;
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    private function connect(MailTestRelayConfig $config): void {
        if (!function_exists('stream_socket_client') && !function_exists('fsockopen')) {
            throw new MailTestRelayException(
                $this->l10n->t(
                    'The PHP functions stream_socket_client/fsockopen are disabled on the Nextcloud host '
                    . '(php.ini disable_functions) – outbound SMTP connections are therefore impossible.'
                ),
                MailTestRelayException::STAGE_CONNECT,
            );
        }

        $errno = 0;
        $errstr = '';
        $stream = $this->openSocket($config, $config->smtpHost, $errno, $errstr);

        // Hostname (kein IP-Literal) und Fehlschlag → einmal explizit über
        // den IPv4-A-Record verbinden (defekte AAAA-/IPv6-Routen kommen vor).
        if ($stream === false
            && filter_var($config->smtpHost, FILTER_VALIDATE_IP) === false) {
            $ipv4 = gethostbyname($config->smtpHost);
            if ($ipv4 !== $config->smtpHost && filter_var($ipv4, FILTER_VALIDATE_IP) !== false) {
                $this->logger->info('SMTP connect via hostname failed – retrying with IPv4 A record', [
                    'host' => $config->smtpHost,
                    'ipv4' => $ipv4,
                    'port' => $config->smtpPort,
                ]);
                $errno4 = 0;
                $errstr4 = '';
                $stream = $this->openSocket($config, $ipv4, $errno4, $errstr4);
            }
        }

        if ($stream === false) {
            throw new MailTestRelayException(
                $this->describeConnectFailure($config, $errno, $errstr),
                MailTestRelayException::STAGE_CONNECT,
            );
        }
        stream_set_timeout($stream, self::IO_TIMEOUT);
        $this->stream = $stream;
        $this->tlsUpgraded = false;
    }

    /**
     * Open the raw client socket. Prefers stream_socket_client (context
     * support for implicit TLS); falls back to fsockopen when the former
     * is disabled via php.ini.
     *
     * @return resource|false
     */
    private function openSocket(MailTestRelayConfig $config, string $host, int &$errno, string &$errstr) {
        $errno = 0;
        $errstr = '';
        if (function_exists('stream_socket_client')) {
            $prefix  = $config->usesImplicitTls() ? 'ssl://' : 'tcp://';
            $context = stream_context_create(
                $config->usesImplicitTls() ? $this->tlsContextOptions() : []
            );
            return @stream_socket_client(
                $prefix . $host . ':' . $config->smtpPort,
                $errno,
                $errstr,
                self::CONNECT_TIMEOUT,
                STREAM_CLIENT_CONNECT,
                $context,
            );
        }
        // fsockopen fallback: ssl:// wrapper uses the default context –
        // acceptable, cert verification is relaxed globally by NC anyway.
        return @fsockopen(
            ($config->usesImplicitTls() ? 'ssl://' : '') . $host,
            $config->smtpPort,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
        );
    }

    /**
     * Compact, errno-classified description of a failed TCP connect so
     * the operator diagnostic pinpoints the layer (SELinux, firewall,
     * closed port, routing) instead of a generic "not reachable".
     */
    private function describeConnectFailure(MailTestRelayConfig $config, int $errno, string $errstr): string {
        $url = ($config->usesImplicitTls() ? 'ssl://' : 'tcp://')
            . $config->smtpHost . ':' . $config->smtpPort;
        if ($errno === 0 && $errstr === '') {
            return $url . ': ' . $this->l10n->t(
                'Timeout after %ds without reply [packets are being dropped – check firewall/routing '
                . 'between Nextcloud host and Stalwart (DROP rule, Docker/K8s network)]',
                [self::CONNECT_TIMEOUT],
            );
        }
        $hint = match ($errno) {
            13  => ' ' . $this->l10n->t(
                '[Permission denied: the webserver process is not allowed to open SMTP ports – '
                . 'typically SELinux (on the Nextcloud host: setsebool -P httpd_can_sendmail on) '
                . 'or an AppArmor/container profile]'
            ),
            110 => ' ' . $this->l10n->t(
                '[Timeout: packets are being dropped – check firewall/routing between Nextcloud host '
                . 'and Stalwart]'
            ),
            111 => ' ' . $this->l10n->t(
                '[Connection refused: no service is listening on that port on this host, or a firewall '
                . 'actively rejects the connection]'
            ),
            113 => ' ' . $this->l10n->t(
                '[No route to host: the network route from the Nextcloud host is missing]'
            ),
            default => '',
        };
        return $url . ': ' . ($errstr !== '' ? $errstr : $this->l10n->t('unknown error'))
            . ' (errno ' . $errno . ')' . $hint;
    }

    /**
     * Look for "250-STARTTLS" or "250 STARTTLS" in the EHLO multi-line
     * reply. Extra whitespace tolerated for robustness.
     */
    private function supportsStartTls(string $ehloReply): bool {
        foreach (preg_split("/\r\n|\n/", $ehloReply) ?: [] as $line) {
            $line = trim($line);
            // "250-STARTTLS" (intermediate) or "250 STARTTLS" (final)
            if (preg_match('/^250[- ]STARTTLS\b/i', $line) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Attempt to upgrade the current plain socket to TLS. On failure we
     * log a warning and return false – the caller then continues in
     * plain, which the operator explicitly accepted for the internal
     * Stalwart relay.
     */
    private function tryStartTls(MailTestRelayConfig $config): bool {
        $this->writeLine('STARTTLS');
        try {
            $this->readReply(220, MailTestRelayException::STAGE_STARTTLS);
        } catch (MailTestRelayException $e) {
            $this->logger->warning('Stalwart refused STARTTLS – continuing plain', [
                'host'  => $config->smtpHost,
                'port'  => $config->smtpPort,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Apply relaxed TLS context to the existing stream and negotiate.
        foreach ($this->tlsContextOptions()['ssl'] as $optKey => $optVal) {
            @stream_context_set_option($this->stream, 'ssl', $optKey, $optVal);
        }
        error_clear_last(); // so error_get_last() below is precise
        $enabled = @stream_socket_enable_crypto(
            $this->stream,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT,
        );
        if ($enabled !== true) {
            $lastError = error_get_last();
            $this->logger->warning('STARTTLS handshake failed – continuing plain', [
                'host'  => $config->smtpHost,
                'port'  => $config->smtpPort,
                'error' => $lastError['message'] ?? 'unknown',
            ]);
            return false;
        }
        return true;
    }

    /**
     * Stream context options that make TLS work against Stalwart's
     * self-signed internal cert. Trust boundary is the internal LAN.
     *
     * @return array{ssl: array<string, mixed>}
     */
    private function tlsContextOptions(): array {
        return [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'SNI_enabled'       => true,
            ],
        ];
    }

    private function writeLine(string $line): void {
        $this->writeRaw($line . "\r\n");
    }

    private function writeRaw(string $raw): void {
        if (!is_resource($this->stream)) {
            throw new MailTestRelayException(
                'SMTP stream is not open',
                MailTestRelayException::STAGE_CONNECT,
            );
        }
        $written = @fwrite($this->stream, $raw);
        if ($written === false) {
            throw new MailTestRelayException(
                'Failed to write to SMTP stream',
                MailTestRelayException::STAGE_CONNECT,
            );
        }
    }

    private function readReply(int $expected, string $stage): string {
        if (!is_resource($this->stream)) {
            throw new MailTestRelayException(
                'SMTP stream closed unexpectedly',
                $stage,
            );
        }
        $buffer = '';
        // SMTP multi-line replies use "250-continuation" then "250 final".
        while (true) {
            $line = fgets($this->stream, 1024);
            if ($line === false) {
                throw new MailTestRelayException(
                    'SMTP read timed out or connection closed',
                    $stage,
                );
            }
            $buffer .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($buffer, 0, 3);
        if ($code !== $expected) {
            throw new MailTestRelayException(
                'SMTP unexpected reply (expected ' . $expected . ', got ' . $code . '): ' . trim($buffer),
                $stage,
            );
        }
        return $buffer;
    }

    private function closeStream(): void {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    /**
     * Derive a syntactically valid EHLO host. Some relays reject EHLOs
     * without a dot – so we always emit the from-address' domain part.
     */
    private function deriveEhloHost(string $fromAddress): string {
        $at = strrpos($fromAddress, '@');
        if ($at === false || $at === strlen($fromAddress) - 1) {
            return 'shield.souvera.local';
        }
        return substr($fromAddress, $at + 1);
    }

    private function buildMessage(
        string $fromAddress,
        string $fromDisplay,
        string $to,
        string $subject,
        string $plainBody,
    ): string {
        $date = gmdate('D, d M Y H:i:s') . ' +0000';
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $this->deriveEhloHost($fromAddress) . '>';

        // Simple RFC 5322 message, ASCII-safe subject via 7bit encoding.
        $lines = [
            'Date: ' . $date,
            'Message-ID: ' . $messageId,
            'From: ' . $this->encodeMailbox($fromAddress, $fromDisplay),
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeaderIfNeeded($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Souvera-Shield: mail-test',
            '',
            // RFC 5321 § 4.5.2 - dot-stuff any line starting with a period.
            $this->dotStuff($plainBody),
        ];
        return implode("\r\n", $lines);
    }

    private function encodeMailbox(string $address, string $display): string {
        $encodedDisplay = $this->encodeHeaderIfNeeded($display);
        return $encodedDisplay . ' <' . $address . '>';
    }

    private function encodeHeaderIfNeeded(string $value): string {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function dotStuff(string $body): string {
        $body = str_replace("\r\n", "\n", $body);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        return str_replace("\n", "\r\n", $body);
    }
}
