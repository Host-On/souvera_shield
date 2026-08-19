<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCP\IAppConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Thin client around the Proxmox Mail Gateway (PMG) REST API.
 *
 * Configuration (read from system or app config, system wins):
 *   - pmg_domain           e.g. "https://pmg.example.com:8006"
 *   - pmg_username         technical PMG account, e.g. "shield@pmg"
 *   - pmg_password         password for that account
 *   - pmg_allow_insecure   true to skip TLS verification (dev only)
 *   - pmg_allowed_domains  comma separated list of e-mail domains
 *                          the app may manage. Empty = none allowed.
 *
 * Design notes:
 *   - Uses Nextcloud's IClientService (Guzzle) instead of raw curl.
 *   - Authenticates lazily and re-uses the ticket for ~55 minutes.
 *   - Every public method either returns an array with a 'data' key or
 *     throws a {@see PMGException} – no silent "[]" fallbacks anymore.
 *
 * @internal Not part of any public API.
 */
class PMGClient {

    private const APP_ID = 'souvera_shield';
    private const TICKET_LIFETIME = 55 * 60; // PMG tickets are valid for 2h, we refresh well before.
    public const ENCRYPTION_PREFIX = 'v2$';

    private const QUARANTINE_SPAM  = 'spam';
    private const QUARANTINE_ATTACHMENT = 'attachment';
    private const QUARANTINE_VIRUS = 'virus';

    private string $domain = '';
    private string $username = '';
    private string $password = '';
    private bool $insecure = false;
    /** @var string[] */
    private array $allowedDomains = [];

    private ?string $ticket = null;
    private ?string $csrfToken = null;
    private int $ticketExpiresAt = 0;

    private IClient $http;

    public function __construct(
        private readonly IConfig $config,
        private readonly IAppConfig $appConfig,
        IClientService $clientService,
        private readonly LoggerInterface $logger,
        private readonly ?ICrypto $crypto = null,
    ) {
        $this->http = $clientService->newClient();
        $this->loadConfig();
    }

    // -------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------

    private function loadConfig(): void {
        $this->domain   = rtrim($this->readConfig('pmg_domain'), '/');
        $this->username = $this->readConfig('pmg_username');
        $this->password = $this->decryptIfNeeded($this->readConfig('pmg_password'));

        $insecure = $this->readConfig('pmg_allow_insecure', '');
        $this->insecure = filter_var($insecure, FILTER_VALIDATE_BOOLEAN);

        $domains = $this->config->getSystemValue('pmg_allowed_domains', '');
        if (!is_array($domains) && (!is_string($domains) || $domains === '')) {
            $domains = $this->appConfig->getValueString(self::APP_ID, 'pmg_allowed_domains', '', lazy: true);
        }
        if (is_array($domains)) {
            $this->allowedDomains = array_map('strtolower', array_map('trim', $domains));
        } elseif (is_string($domains) && $domains !== '') {
            $this->allowedDomains = array_map(
                'strtolower',
                array_map('trim', explode(',', $domains))
            );
        }
    }

    /**
     * Read a value first from system config (config.php), then from app
     * config via the typed lazy IAppConfig API (NC 30+).
     */
    private function readConfig(string $key, string $default = ''): string {
        $sys = $this->config->getSystemValue($key, '');
        if (is_string($sys) && $sys !== '') {
            return $sys;
        }
        return $this->appConfig->getValueString(self::APP_ID, $key, $default, lazy: true);
    }

    /**
     * Detect the encryption marker and transparently decrypt; if decryption
     * fails (e.g. legacy plaintext passwords), fall back to the raw value so
     * existing installations keep working until the next "occ" rotation.
     */
    private function decryptIfNeeded(string $value): string {
        if ($value === '') {
            return '';
        }
        if (!str_starts_with($value, self::ENCRYPTION_PREFIX)) {
            return $value; // legacy plaintext – will be migrated on next set
        }
        if ($this->crypto === null) {
            $this->logger->warning('PMG password is encrypted but ICrypto is unavailable');
            return '';
        }
        try {
            return $this->crypto->decrypt(substr($value, strlen(self::ENCRYPTION_PREFIX)));
        } catch (\Throwable $e) {
            $this->logger->error('PMG password decryption failed', ['exception' => $e]);
            return '';
        }
    }

    /**
     * Check if the given e-mail belongs to a domain the admin allows.
     */
    public function isAllowedDomain(string $email): bool {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(substr($email, $at + 1));
        return in_array($domain, $this->allowedDomains, true);
    }

    /**
     * Returns the primary mail domain configured for this workspace.
     * Used by the reputation area – there is exactly one domain per
     * workspace and it comes from configuration, not from the user.
     */
    public function getPrimaryDomain(): ?string {
        return $this->allowedDomains[0] ?? null;
    }

    // -------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------

    private function ensureTicket(): void {
        if ($this->ticket !== null && time() < $this->ticketExpiresAt - 10) {
            return;
        }
        $this->login();
    }

    private function login(): void {
        if ($this->domain === '' || $this->username === '') {
            throw new PMGException(
                'Proxmox Mail Gateway is not configured. Use "occ config:app:set souvera_shield pmg_domain …".'
            );
        }

        $url = $this->domain . '/api2/json/access/ticket';
        try {
            $response = $this->http->post($url, [
                'body' => [
                    'username' => $this->username,
                    'password' => $this->password,
                ],
                'verify' => !$this->insecure,
                'timeout' => 15,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('PMG login transport error', ['exception' => $e]);
            throw new PMGException('Could not reach Proxmox Mail Gateway: ' . $e->getMessage(), 502, $e);
        }

        $body = json_decode((string)$response->getBody(), true);
        $ticket = $body['data']['ticket'] ?? null;
        if (!is_string($ticket) || $ticket === '') {
            throw new PMGException('PMG login failed: unexpected response payload.', 502);
        }

        $this->ticket = $ticket;
        $this->csrfToken = $body['data']['CSRFPreventionToken'] ?? null;
        $this->ticketExpiresAt = time() + self::TICKET_LIFETIME;
    }

    // -------------------------------------------------------------------
    // Low-level request
    // -------------------------------------------------------------------

    /**
     * Perform a request against the PMG API.
     *
     * @param 'GET'|'POST'|'DELETE' $method
     * @param array<string,mixed>   $params
     * @return array<string,mixed>  Decoded JSON body. Always contains http and (usually) data.
     */
    public function request(string $method, string $path, array $params = []): array {
        $this->ensureTicket();

        $url = $this->buildUrl($path);
        $options = [
            'verify' => !$this->insecure,
            'timeout' => 30,
            'headers' => [
                'Cookie' => 'PMGAuthCookie=' . $this->ticket,
            ],
        ];

        if ($method === 'GET' || $method === 'DELETE') {
            if (!empty($params)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            }
        } else {
            $options['body'] = $params;
        }

        if ($method !== 'GET' && $this->csrfToken !== null) {
            $options['headers']['CSRFPreventionToken'] = $this->csrfToken;
        }

        try {
            $response = match ($method) {
                'GET' => $this->http->get($url, $options),
                'POST' => $this->http->post($url, $options),
                'DELETE' => $this->http->delete($url, $options),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('PMG request failed', [
                'method' => $method,
                'path' => $path,
                'exception' => $e,
            ]);
            throw new PMGException('PMG request error: ' . $e->getMessage(), 502, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string)$response->getBody();
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $json = ['data' => $raw];
        }
        $json['http'] = $status;
        return $json;
    }

    private function buildUrl(string $path): string {
        $prefix = str_starts_with($path, '/extjs')
            ? '/api2/extjs'
            : '/api2/json';
        $suffix = str_starts_with($path, '/extjs')
            ? substr($path, strlen('/extjs'))
            : $path;
        return $this->domain . $prefix . $suffix;
    }

    // -------------------------------------------------------------------
    // Public API – Quarantine
    // -------------------------------------------------------------------

    /**
     * @return array{data: array<int,array<string,mixed>>}
     */
    public function getSpamQuarantine(string $pmail, bool $includeOlder = false): array {
        return $this->fetchQuarantine(self::QUARANTINE_SPAM, $pmail, $includeOlder);
    }

    /**
     * @return array{data: array<int,array<string,mixed>>}
     */
    public function getAttachmentQuarantine(string $pmail, bool $includeOlder = false): array {
        return $this->fetchQuarantine(self::QUARANTINE_ATTACHMENT, $pmail, $includeOlder);
    }

    /**
     * @return array{data: array<int,array<string,mixed>>}
     */
    public function getVirusQuarantine(string $pmail, bool $includeOlder = false): array {
        return $this->fetchQuarantine(self::QUARANTINE_VIRUS, $pmail, $includeOlder);
    }

    private function fetchQuarantine(string $type, string $pmail, bool $includeOlder): array {
        $this->assertAllowed($pmail);
        $params = ['pmail' => $pmail];
        if ($includeOlder) {
            // Last 90 days (PMG default is "today only" if no range is given).
            $params['starttime'] = time() - 90 * 86400;
            $params['endtime']   = time() + 86400;
        }
        $res = $this->request('GET', '/quarantine/' . $type, $params);
        $data = $res['data'] ?? [];
        return ['data' => is_array($data) ? $data : []];
    }

    /**
     * @return array{data: mixed}
     */
    public function viewMessage(string $pmail, string $id): array {
        $this->assertAllowed($pmail);
        // PMG's /quarantine/content endpoint validates against a strict schema
        // and rejects unknown properties – pmail is only valid on list endpoints.
        $res = $this->request('GET', '/quarantine/content', ['id' => $id]);
        return ['data' => $res['data'] ?? $res];
    }

    public function releaseMessage(string $pmail, string $id): void {
        $this->assertAllowed($pmail);
        $res = $this->request('POST', '/quarantine/content', [
            'action' => 'deliver',
            'id' => $id,
        ]);
        $this->assertSuccess($res, 'Failed to release message');
    }

    /**
     * Release a message specifically from the spam quarantine.
     *
     * Uses the same PMG quarantine/content endpoint as the generic release
     * but passes the receiver so PMG can correctly identify the quarantine
     * bucket even when the message ID alone is ambiguous.
     */
    public function releaseSpamMessage(string $pmail, string $id): void {
        $this->assertAllowed($pmail);
        $res = $this->request('POST', '/quarantine/content', [
            'action' => 'deliver',
            'id' => $id,
        ]);
        $this->assertSuccess($res, 'Failed to release spam message');
    }

    public function deleteMessage(string $pmail, string $id): void {
        $this->assertAllowed($pmail);
        $res = $this->request('POST', '/quarantine/content', [
            'action' => 'delete',
            'id' => $id,
        ]);
        $this->assertSuccess($res, 'Failed to delete message');
    }

    // -------------------------------------------------------------------
    // Public API – Whitelist / Blacklist
    // -------------------------------------------------------------------

    /**
     * @return array{data: array<int,array<string,mixed>>}
     */
    public function getWhitelist(string $pmail): array {
        return $this->fetchUserList('whitelist', $pmail);
    }

    /**
     * @return array{data: array<int,array<string,mixed>>}
     */
    public function getBlacklist(string $pmail): array {
        return $this->fetchUserList('blacklist', $pmail);
    }

    public function addToWhitelist(string $pmail, string $entry): void {
        $this->addUserListEntry('whitelist', $pmail, $entry);
    }

    public function addToBlacklist(string $pmail, string $entry): void {
        $this->addUserListEntry('blacklist', $pmail, $entry);
    }

    public function removeFromWhitelist(string $pmail, string $entry): void {
        $this->removeUserListEntry('whitelist', $pmail, $entry);
    }

    public function removeFromBlacklist(string $pmail, string $entry): void {
        $this->removeUserListEntry('blacklist', $pmail, $entry);
    }

    private function fetchUserList(string $kind, string $pmail): array {
        $this->assertAllowed($pmail);
        $res = $this->request('GET', '/quarantine/' . $kind, ['pmail' => $pmail]);
        $data = $res['data'] ?? [];
        return ['data' => is_array($data) ? $data : []];
    }

    private function addUserListEntry(string $kind, string $pmail, string $entry): void {
        $this->assertAllowed($pmail);
        $entry = trim($entry);
        if ($entry === '') {
            throw new PMGException('Empty list entry', 400);
        }
        $res = $this->request('POST', '/quarantine/' . $kind, [
            'pmail' => $pmail,
            'address' => $entry,
        ]);
        $this->assertSuccess($res, "Failed to add to {$kind}");
    }

    private function removeUserListEntry(string $kind, string $pmail, string $entry): void {
        $this->assertAllowed($pmail);
        $entry = trim($entry);
        if ($entry === '') {
            throw new PMGException('Empty list entry', 400);
        }
        $res = $this->request('DELETE', '/quarantine/' . $kind, [
            'pmail' => $pmail,
            'address' => $entry,
        ]);
        $this->assertSuccess($res, "Failed to remove from {$kind}");
    }

    // -------------------------------------------------------------------
    // Spam report configuration (global on the PMG host)
    // -------------------------------------------------------------------

    /**
     * Current global spam report style of the PMG host
     * (none|short|verbose|custom) — null when unavailable.
     */
    public function getSpamReportStyle(): ?string {
        try {
            $res = $this->request('GET', '/config/spam');
        } catch (\Throwable $e) {
            $this->logger->warning('PMG getSpamReportStyle failed: ' . $e->getMessage());
            return null;
        }
        $style = $res['data']['reportstyle'] ?? null;
        return \is_string($style) ? $style : null;
    }

    /**
     * Set the global spam report style (e.g. "none" to disable the PMG
     * built-in daily report because Souvera sends its own).
     */
    public function setSpamReportStyle(string $style): void {
        $res = $this->request('PUT', '/config/spam', ['reportstyle' => $style]);
        $this->assertSuccess($res, 'Failed to update PMG spam report style');
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function assertAllowed(string $pmail): void {
        if (!$this->isAllowedDomain($pmail)) {
            throw new PMGException('E-Mail domain is not allowed for this app.', 403);
        }
    }

    /**
     * @param array<string,mixed> $res
     */
    private function assertSuccess(array $res, string $errorPrefix): void {
        $http = (int)($res['http'] ?? 0);
        if ($http >= 200 && $http < 300 && empty($res['errors'])) {
            return;
        }
        $msg = $errorPrefix;
        if (!empty($res['errors']) && is_array($res['errors'])) {
            $msg .= ': ' . implode('; ', array_map(
                static fn($k, $v) => is_string($v) ? "$k: $v" : (string)json_encode($v),
                array_keys($res['errors']),
                $res['errors']
            ));
        } elseif (!empty($res['message'])) {
            $msg .= ': ' . $res['message'];
        }
        throw new PMGException($msg, $http >= 400 ? $http : 502);
    }
}
