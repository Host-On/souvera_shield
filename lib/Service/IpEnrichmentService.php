<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCA\SouveraShield\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * IP enrichment service — geolocation, ISP, threat intelligence via
 * provider.tools, with 24h local caching to avoid hammering the API.
 */
class IpEnrichmentService {

    private const CACHE_PREFIX = 'shield_ip_enrich_';
    private const CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private readonly ProviderToolsClient $providerTools,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Enrich an IP address with geo/ISP/threat data.
     *
     * @return array{ip:string, country?:string, city?:string, isp?:string, asn?:string, hosting?:bool, vpn?:bool, proxy?:bool, tor?:bool, blocklists?:list<string>}
     */
    public function enrich(string $ip): array {
        $cacheKey = self::CACHE_PREFIX . md5($ip);
        $cached = $this->appConfig->getValueString(Application::APP_ID, $cacheKey, '', lazy: true);

        if ($cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && ($decoded['_expires'] ?? 0) > time()) {
                unset($decoded['_expires']);
                return $decoded;
            }
        }

        try {
            $data = $this->providerTools->ipLookup($ip);
        } catch (ProviderToolsException $e) {
            $this->logger->warning('IP lookup failed, falling back to DNS', [
                'app'       => Application::APP_ID,
                'ip'        => $ip,
                'exception' => $e,
            ]);
            $data = $this->dnsFallback($ip);
        }

        // Normalise the response: provider.tools returns asn as an object
        // { asn: 201035, asnOrg: "LueneCom" }, not a plain string.
        if (isset($data['asn']) && is_array($data['asn'])) {
            $asnObj = $data['asn'];
            $asnNumber = (string)($asnObj['asn'] ?? '');
            $data['asn'] = $asnNumber; // store just the number on the trace
            $data['isp'] = $asnObj['asnOrg'] ?? ($data['isp'] ?? null);
            $data['asn_name'] = $asnObj['asnOrg'] ?? null;

            if ($asnNumber !== '') {
                try {
                    $asnData = $this->providerTools->asnLookup($asnNumber);
                    if (isset($asnData['type'])) {
                        $data['hosting'] = str_contains(strtolower($asnData['type']), 'hosting');
                    }
                } catch (ProviderToolsException $e) {
                    $this->logger->debug('ASN lookup failed (non-critical)', [
                        'app'       => Application::APP_ID,
                        'asn'       => $asnNumber,
                        'exception' => $e,
                    ]);
                }
            }
        }

        $data['_expires'] = time() + self::CACHE_TTL;
        $this->appConfig->setValueString(
            Application::APP_ID,
            $cacheKey,
            json_encode($data, JSON_THROW_ON_ERROR),
            lazy: true,
        );

        unset($data['_expires']);
        return $data;
    }

    /**
     * Extract the /24 (IPv4) or /48 (IPv6) subnet from an IP.
     */
    public function subnet(string $ip): string {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            $prefix = implode(':', array_slice($parts, 0, 3));
            return $prefix . '::/48';
        }
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        return $ip;
    }

    /**
     * Compute a device hash from IP subnet and user agent.
     */
    public function deviceHash(string $ipSubnet, ?string $userAgent): string {
        $input = $ipSubnet . '|' . ($userAgent ?? '');
        return md5($input);
    }

    /**
     * Fallback: use gethostbyaddr for basic info when API is unavailable.
     *
     * @return array{ip:string, isp?:string}
     */
    private function dnsFallback(string $ip): array {
        $hostname = gethostbyaddr($ip);
        return [
            'ip'       => $ip,
            'isp'      => $hostname !== $ip ? $hostname : null,
        ];
    }
}
