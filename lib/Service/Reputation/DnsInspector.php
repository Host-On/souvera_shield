<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service\Reputation;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Thin, testable wrapper around PHP's DNS primitives plus the HTTPS
 * fetch needed for MTA-STS policies. All lookups are best-effort and
 * return empty/null instead of throwing – the caller decides whether
 * "no data" is a failure or merely informational.
 */
class DnsInspector {

    private IClient $http;

    public function __construct(
        IClientService $clientService,
        private readonly LoggerInterface $logger,
    ) {
        $this->http = $clientService->newClient();
    }

    /** @return string[] full TXT record strings (chunks joined) */
    public function txtRecords(string $host): array {
        $records = @dns_get_record($host, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }
        $out = [];
        foreach ($records as $rec) {
            if (isset($rec['entries']) && is_array($rec['entries'])) {
                $out[] = implode('', $rec['entries']);
            } elseif (isset($rec['txt'])) {
                $out[] = (string)$rec['txt'];
            }
        }
        return $out;
    }

    /** @return string[] IPv4 addresses */
    public function aRecords(string $host): array {
        $records = @dns_get_record($host, DNS_A);
        if (!is_array($records)) {
            return [];
        }
        $out = [];
        foreach ($records as $rec) {
            if (isset($rec['ip'])) {
                $out[] = (string)$rec['ip'];
            }
        }
        return $out;
    }

    /** @return string[] MX targets ordered by priority */
    public function mxRecords(string $domain): array {
        $records = @dns_get_record($domain, DNS_MX);
        if (!is_array($records)) {
            return [];
        }
        usort($records, static fn(array $a, array $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        $out = [];
        foreach ($records as $rec) {
            if (!empty($rec['target'])) {
                $out[] = rtrim((string)$rec['target'], '.');
            }
        }
        return $out;
    }

    /** Reverse lookup; null when no PTR exists. */
    public function ptr(string $ip): ?string {
        $host = @gethostbyaddr($ip);
        if ($host === false || $host === $ip || $host === '') {
            return null;
        }
        return rtrim($host, '.');
    }

    /** First TXT record starting with the given prefix (case-insensitive). */
    public function findTxt(string $host, string $prefix): ?string {
        foreach ($this->txtRecords($host) as $txt) {
            if (stripos(ltrim($txt), $prefix) === 0) {
                return trim($txt);
            }
        }
        return null;
    }

    public function spfRecord(string $domain): ?string {
        return $this->findTxt($domain, 'v=spf1');
    }

    public function dmarcRecord(string $domain): ?string {
        return $this->findTxt('_dmarc.' . $domain, 'v=DMARC1');
    }

    /**
     * Extract a `tag=value` pair from an SPF/DMARC/STS-style record.
     */
    public function parseTag(string $record, string $tag): ?string {
        if (preg_match('/(?:^|[;\s])' . preg_quote($tag, '/') . '\s*=\s*([^;\s]+)/i', $record, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Fetch `https://mta-sts.<domain>/.well-known/mta-sts.txt`.
     * Returns the raw policy text or null (unreachable / non-200).
     */
    public function fetchMtaStsPolicy(string $domain): ?string {
        $url = 'https://mta-sts.' . $domain . '/.well-known/mta-sts.txt';
        try {
            $response = $this->http->get($url, ['timeout' => 10]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = (string)$response->getBody();
            return $body !== '' ? $body : null;
        } catch (\Throwable $e) {
            $this->logger->debug('MTA-STS policy fetch failed', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
