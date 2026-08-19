<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

/**
 * Immutable value object for the mail-test SMTP relay.
 *
 * ─── v3.8.0 — anonymous trusted-IP submission ───
 *
 * The reputation test must measure the customer's own outbound mail server
 * (Stalwart): its IP, PTR, SPF, DKIM and DMARC. The relay therefore always
 * targets the Stalwart host from `souvera_central.stalwart_api_url`.
 *
 * Default flow: `smtpUser` and `smtpPassword` are empty, `authRequired`
 * is false; Stalwart's submission listener trusts the Nextcloud worker
 * IP (provisioned by CloudManager) and accepts the mail unauthenticated
 * with `postmaster@<workspace-domain>` in MAIL FROM.
 *
 * Escape hatch: static config.php keys
 * `souvera_central.stalwart_mailtest_user/-password` opt back into
 * SMTP-AUTH – used by deployments where a trusted-IP listener is not
 * available.
 *
 * Config keys:
 *   souvera_central.stalwart_api_url   – URL of the Stalwart deployment
 *                                        (only the host part is used)
 *   souvera_central.stalwart_smtp_port – SMTP port override
 *                                        (default 587 = submission,
 *                                        465 = implicit TLS)
 */
final class MailTestRelayConfig {

    public function __construct(
        public readonly string $smtpHost,
        public readonly int $smtpPort,
        public readonly string $smtpUser,
        public readonly string $smtpPassword,
        public readonly bool $authRequired,
        public readonly string $securityMode, // 'none' | 'tls' | 'ssl'
    ) {
    }

    /**
     * Build the config from Souvera Central's Stalwart-URL plus the
     * optional port override and the SMTP-AUTH mailbox credentials.
     *
     * @param string|null $stalwartApiUrl e.g. `https://mail.souvera.eu:8080`
     * @param int|string|null $smtpPortOverride optional numeric port
     * @param string $smtpUser SMTP-AUTH principal (mailbox address); empty = no AUTH
     * @param string $smtpPassword SMTP-AUTH secret
     * @param string|null $smtpHostOverride optional dedicated SMTP host
     *        (`souvera_central.stalwart_smtp_host`) – needed when the API
     *        URL points at a reverse proxy that does not forward SMTP
     * @return self|null null if no usable host was found
     */
    public static function fromStalwart(
        ?string $stalwartApiUrl,
        int|string|null $smtpPortOverride = null,
        string $smtpUser = '',
        string $smtpPassword = '',
        ?string $smtpHostOverride = null,
    ): ?self {
        $host = null;
        if ($smtpHostOverride !== null && trim($smtpHostOverride) !== '') {
            $host = self::extractHost(trim($smtpHostOverride));
        }
        if ($host === null) {
            $stalwartApiUrl = trim((string)$stalwartApiUrl);
            if ($stalwartApiUrl === '') {
                return null;
            }
            $host = self::extractHost($stalwartApiUrl);
        }
        if ($host === null) {
            return null;
        }

        $port = 587;
        if ($smtpPortOverride !== null && $smtpPortOverride !== '') {
            $parsed = is_numeric($smtpPortOverride) ? (int)$smtpPortOverride : 0;
            if ($parsed > 0) {
                $port = $parsed;
            }
        }

        // Port 465 → implicit TLS. Everything else starts plain and
        // upgrades opportunistically via STARTTLS if the server offers
        // it. See SmtpMailTestRelay for the connect logic.
        $secure = $port === 465 ? 'ssl' : 'none';

        return new self(
            smtpHost:     $host,
            smtpPort:     $port,
            smtpUser:     $smtpUser,
            smtpPassword: $smtpPassword,
            authRequired: $smtpUser !== '',
            securityMode: $secure,
        );
    }

    /** True iff the configured port implies implicit TLS (SMTPS). */
    public function usesImplicitTls(): bool {
        return $this->securityMode === 'ssl' || $this->smtpPort === 465;
    }

    /**
     * Parse the host part out of a URL, IPv4:port, plain-host or
     * "host:port" string. Returns null if nothing usable was found.
     */
    private static function extractHost(string $raw): ?string {
        // Try to parse as a proper URL first.
        $parts = parse_url($raw);
        if (is_array($parts) && !empty($parts['host'])) {
            return $parts['host'];
        }
        // Strip any scheme prefix we might have missed.
        $stripped = preg_replace('~^[a-z][a-z0-9+\-.]*://~i', '', $raw) ?? $raw;
        // Strip path / query / trailing whitespace.
        $stripped = preg_replace('~[/?#].*$~', '', $stripped) ?? $stripped;
        // Strip explicit port suffix.
        $stripped = preg_replace('~:\d+$~', '', $stripped) ?? $stripped;
        $stripped = trim($stripped);
        return $stripped !== '' ? $stripped : null;
    }
}
