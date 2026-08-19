<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCA\SouveraShield\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Thin client around the provider.tools REST API.
 *
 * ------------------------------------------------------------------
 * Token resolution
 * ------------------------------------------------------------------
 * The provider.tools Bearer token is *not* stored inside Shield.
 * It is centrally managed by the **souvera_central** app and read
 * on-demand through {@see \OCA\SouveraCentral\Service\ProviderTokenService}.
 *
 * Rationale (see SHARED_PROVIDER_TOKEN.md):
 *   - Central is the single source of truth for shared credentials.
 *   - Rotation happens once (`occ souvera:provider-token:set`) and
 *     is picked up by every Souvera app – Shield, Mail, …
 *   - Shield only *reads* the token, it never persists it locally.
 *
 * Configuration keys still owned by Shield (non-secret):
 *   - `provider_tools_base_url`  Optional API base URL override.
 *                                Default: https://provider.tools/api/v1
 *
 * ------------------------------------------------------------------
 * Endpoints used
 * ------------------------------------------------------------------
 *   - GET  /dmarc-check?domain=X   DNS-side DMARC / SPF / DKIM lookup
 *   - GET  /dmarc/domains          Registered domains (verified state)
 *   - POST /dmarc/domains          Register a new domain for the analyzer
 *   - POST /dmarc/domains/:id/verify        Verify ownership via TXT record
 *   - GET  /dmarc/domains/:id/stats         30-day aggregate statistics
 *   - GET  /dmarc/domains/:id/reports       Paginated aggregate reports (RUA)
 *   - DELETE /dmarc/domains/:id             Remove domain from analyzer
 *   - POST /mail-test              Create mail-test session
 *   - GET  /mail-test/:id          Poll a specific test
 *
 * Every public method returns a decoded array or throws
 * {@see ProviderToolsException}.
 */
class ProviderToolsClient {

    public const DEFAULT_BASE_URL = 'https://provider.tools/api/v1';

    /**
     * Fully-qualified name of the Central service that owns the token.
     * Referenced as a string so we don't hard-require the class at
     * autoload time – souvera_central might be disabled.
     */
    private const CENTRAL_TOKEN_SERVICE = 'OCA\\SouveraCentral\\Service\\ProviderTokenService';
    private const CENTRAL_APP_ID        = 'souvera_central';

    private IClient $http;

    public function __construct(
        private readonly IAppConfig $appConfig,
        IClientService $clientService,
        private readonly LoggerInterface $logger,
        private readonly IAppManager $appManager,
    ) {
        $this->http = $clientService->newClient();
    }

    /**
     * True when the central token service is available *and* holds a token.
     */
    public function isConfigured(): bool {
        return $this->readToken() !== '';
    }

    // -------------------------------------------------------------------
    // DMARC Analyzer (managed domains + reports + statistics)
    // -------------------------------------------------------------------

    /**
     * @deprecated  Kept for backward compatibility with legacy callers;
     *              the UI now uses the DMARC Analyzer (registerDomain +
     *              getDomainStats + listAggregateReports).
     * @return array<string,mixed> the `data` object of the response
     */
    public function checkDmarc(string $domain): array {
        return $this->getData('/dmarc-check', ['domain' => $domain]);
    }

    /**
     * Read the list of domains the account has registered with provider.tools.
     * Used to enrich our local records with the "verified" flag and to
     * discover the provider-side domain-id when it was not previously
     * persisted locally.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listRegisteredDomains(): array {
        $payload = $this->getData('/dmarc/domains', []);
        if (isset($payload[0])) {
            return $payload;
        }
        return [];
    }

    /**
     * Register a new domain for DMARC analysis.
     * Returns the setup instructions (verification TXT + rua record).
     *
     * @return array<string,mixed>
     */
    public function registerDomain(string $domain): array {
        return $this->postJson('/dmarc/domains', ['domain' => $domain]);
    }

    /**
     * Verify domain ownership via the DNS TXT record that was returned by
     * {@see registerDomain()}. The response contains `verified: bool` plus
     * (when unverified) the expected / found records for diagnostics.
     *
     * @return array<string,mixed>
     */
    public function verifyDomain(string $providerDomainId): array {
        return $this->postJson(
            '/dmarc/domains/' . rawurlencode($providerDomainId) . '/verify',
            [],
        );
    }

    /**
     * Get the aggregate DMARC/SPF/DKIM statistics for a domain.
     *
     * @return array<string,mixed>
     */
    public function getDomainStats(string $providerDomainId, int $days = 30): array {
        $days = max(1, min(365, $days));
        return $this->getData(
            '/dmarc/domains/' . rawurlencode($providerDomainId) . '/stats',
            ['days' => $days],
        );
    }

    /**
     * Paginated DMARC aggregate reports for a domain.
     *
     * @return array<string,mixed>
     */
    public function listAggregateReports(
        string $providerDomainId,
        int $page = 1,
        int $limit = 20,
    ): array {
        return $this->getData(
            '/dmarc/domains/' . rawurlencode($providerDomainId) . '/reports',
            [
                'page'  => max(1, $page),
                'limit' => max(1, min(100, $limit)),
            ],
        );
    }

    /**
     * Delete a domain from the DMARC analyzer. Not exposed to end users
     * yet; kept here to close the CRUD lifecycle.
     */
    public function deleteDomain(string $providerDomainId): void {
        $url = $this->buildUrl(
            '/dmarc/domains/' . rawurlencode($providerDomainId)
        );
        try {
            $this->http->delete($url, [
                'headers' => $this->authHeaders(),
                'timeout' => 20,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('provider.tools DELETE failed', [
                'app'       => Application::APP_ID,
                'exception' => $e,
            ]);
            throw new ProviderToolsException(
                'Reputation service request failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    // -------------------------------------------------------------------
    // Mail Test
    // -------------------------------------------------------------------

    /**
     * Create a new mail-test session.
     *
     * @return array{testId:string, testEmail:string}
     */
    public function createMailTest(): array {
        $data = $this->postJson('/mail-test', []);
        $testId    = (string)($data['testId']    ?? $data['id']         ?? '');
        $testEmail = (string)($data['testEmail'] ?? $data['email']      ?? $data['address'] ?? '');
        if ($testId === '' || $testEmail === '') {
            throw new ProviderToolsException(
                'Reputation service returned unexpected payload for /mail-test: '
                . json_encode($data)
            );
        }
        return ['testId' => $testId, 'testEmail' => $testEmail];
    }

    /**
     * Fetch a specific mail-test result.
     *
     * @return array<string,mixed>  raw `data` object
     */
    public function getMailTest(string $testId): array {
        return $this->getData('/mail-test/' . rawurlencode($testId), []);
    }

    // -------------------------------------------------------------------
    // Blacklist / domain details (reputation analysis)
    // -------------------------------------------------------------------

    /**
     * DNSBL check against provider.tools' 120+ blacklists.
     *
     * @param string $target IPv4 address or domain name
     * @param string $type   'ip' | 'domain'
     * @return array<string,mixed> {ip|domain, totalChecked, listedCount, cleanCount, blacklists[]}
     */
    public function checkBlacklist(string $target, string $type = 'ip'): array {
        $query = $type === 'domain' ? ['domain' => $target] : ['ip' => $target];
        return $this->getData('/blacklist-check', $query);
    }

    /**
     * Single-domain details incl. `totalForensicReports` (RUF feedback).
     *
     * @return array<string,mixed>
     */
    public function getDomainDetails(string $providerDomainId): array {
        return $this->getData('/dmarc/domains/' . rawurlencode($providerDomainId), []);
    }

    // -------------------------------------------------------------------
    // IP Intelligence (Suspicious Login Detection)
    // -------------------------------------------------------------------

    /**
     * Enrich an IP address with geolocation, ISP, ASN, and threat intelligence.
     *
     * @return array{ip:string, country?:string, city?:string, isp?:string, asn?:string, hosting?:bool, vpn?:bool, proxy?:bool, tor?:bool, blocklists?:list<string>}
     * @throws ProviderToolsException
     */
    public function ipLookup(string $ip): array {
        return $this->getData('/ip-lookup', ['ip' => $ip]);
    }

    /**
     * Look up ASN details (owner, type, network range).
     *
     * @return array{asn:string, name?:string, type?:string, network?:string}
     * @throws ProviderToolsException
     */
    public function asnLookup(string $asn): array {
        return $this->getData('/asn-lookup', ['asn' => $asn]);
    }

    // -------------------------------------------------------------------
    // Low-level helpers
    // -------------------------------------------------------------------

    /**
     * @param  array<string,mixed> $query
     * @return array<mixed>
     */
    private function getData(string $path, array $query): array {
        $url = $this->buildUrl($path);
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        try {
            $response = $this->http->get($url, [
                'headers' => $this->authHeaders(),
                'timeout' => 20,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('provider.tools GET failed', [
                'app'       => Application::APP_ID,
                'path'      => $path,
                'exception' => $e,
            ]);
            throw new ProviderToolsException(
                'Reputation service request failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
        return $this->extractData($response->getBody(), $path);
    }

    /**
     * @param  array<string,mixed> $body
     * @return array<mixed>
     */
    private function postJson(string $path, array $body): array {
        $url = $this->buildUrl($path);
        try {
            $response = $this->http->post($url, [
                'headers' => $this->authHeaders(['Content-Type' => 'application/json']),
                'body'    => json_encode($body, JSON_THROW_ON_ERROR),
                'timeout' => 20,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('provider.tools POST failed', [
                'app'       => Application::APP_ID,
                'path'      => $path,
                'exception' => $e,
            ]);
            throw new ProviderToolsException(
                'Reputation service request failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
        return $this->extractData($response->getBody(), $path);
    }

    /**
     * @return array<mixed>
     */
    private function extractData(mixed $raw, string $path): array {
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new ProviderToolsException(
                "Reputation service returned non-JSON for {$path}"
            );
        }
        if (isset($decoded['success']) && $decoded['success'] === false) {
            $msg = (string)($decoded['error'] ?? $decoded['message'] ?? 'unknown error');
            throw new ProviderToolsException("Reputation service error at {$path}: {$msg}");
        }
        $data = $decoded['data'] ?? $decoded;
        return is_array($data) ? $data : [];
    }

    private function buildUrl(string $path): string {
        $base = rtrim($this->readBaseUrl(), '/');
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        return $base . $path;
    }

    /**
     * @param array<string,string> $extra
     * @return array<string,string>
     */
    private function authHeaders(array $extra = []): array {
        $token = $this->readToken();
        if ($token === '') {
            throw new ProviderToolsException(
                'Reputation service token is not configured in Souvera Central. '
                . 'Run on the Nextcloud server: '
                . 'occ souvera:provider-token:set --stdin'
            );
        }
        return array_merge([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ], $extra);
    }

    private function readBaseUrl(): string {
        $value = $this->appConfig->getValueString(
            Application::APP_ID,
            'provider_tools_base_url',
            self::DEFAULT_BASE_URL,
            lazy: true,
        );
        return $value !== '' ? $value : self::DEFAULT_BASE_URL;
    }

    /**
     * Fetch the provider.tools token from souvera_central.
     *
     * Returns an empty string when
     *   - souvera_central is not installed / not enabled, or
     *   - Central's ProviderTokenService is not yet available in the
     *     DI container (autoloader race during install), or
     *   - no token has been set / it cannot be decrypted.
     *
     * We log-but-do-not-throw so background jobs stay quiet until the
     * hoster runs `occ souvera:provider-token:set`.
     */
    private function readToken(): string {
        if (!$this->appManager->isInstalled(self::CENTRAL_APP_ID)) {
            $this->logger->debug(
                'souvera_central is not installed – provider.tools token unavailable.',
                ['app' => Application::APP_ID],
            );
            return '';
        }
        try {
            /** @var object{getToken: callable(): ?string} $svc */
            $svc = \OCP\Server::get(self::CENTRAL_TOKEN_SERVICE);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Unable to resolve ProviderTokenService from souvera_central.',
                ['app' => Application::APP_ID, 'exception' => $e],
            );
            return '';
        }
        try {
            $token = $svc->getToken();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ProviderTokenService::getToken() failed.',
                ['app' => Application::APP_ID, 'exception' => $e],
            );
            return '';
        }
        return is_string($token) ? trim($token) : '';
    }
}
