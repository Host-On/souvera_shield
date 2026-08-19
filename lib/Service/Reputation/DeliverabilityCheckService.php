<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service\Reputation;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\MailTest;
use OCA\SouveraShield\Db\MailTestMapper;
use OCA\SouveraShield\Service\ProviderToolsClient;
use OCA\SouveraShield\Service\ProviderToolsException;
use OCA\SouveraShield\Service\SouveraCentralConfig;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Runs the extended deliverability checks for the managed domain.
 *
 * Every check is backed by *real* observed data:
 *   - DNS lookups performed by this Nextcloud host (SPF, DMARC, MTA-STS,
 *     TLS-RPT, BIMI, PTR/FCrDNS, MX)
 *   - a live SMTP banner/EHLO probe (HELO identity, STARTTLS)
 *   - the latest completed provider.tools mail-test (DKIM selector,
 *     SPF/DKIM alignment, spam score, One-Click-Unsubscribe headers)
 *   - provider.tools blacklist checks for the outbound IP *and* the
 *     domain (120+ DNSBLs)
 *
 * A check that cannot be evaluated reports status `nodata` including the
 * reason – never a fabricated value.
 *
 * Statuses: ok | warn | fail | info | nodata
 */
class DeliverabilityCheckService {

    private const CACHE_TTL = 6 * 3600;
    private const CACHE_KEY_PREFIX = 'rep_checks_';

    public function __construct(
        private readonly DnsInspector $dns,
        private readonly SmtpProbe $probe,
        private readonly ProviderToolsClient $provider,
        private readonly MailTestMapper $tests,
        private readonly SouveraCentralConfig $central,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{generated_at:int, outbound_ip:?string, ip_source:?string, checks:array<int,array<string,mixed>>}
     */
    public function getChecks(DmarcDomain $domain, bool $refresh = false): array {
        $cacheKey = self::CACHE_KEY_PREFIX . preg_replace('/[^a-z0-9_.\-]/i', '_', strtolower($domain->getDomain()));
        if (!$refresh) {
            $cached = $this->appConfig->getValueString(Application::APP_ID, $cacheKey, '', lazy: true);
            if ($cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)
                    && isset($decoded['generated_at'])
                    && (time() - (int)$decoded['generated_at']) < self::CACHE_TTL) {
                    return $decoded;
                }
            }
        }

        $result = $this->runAll($domain);
        $this->appConfig->setValueString(
            Application::APP_ID,
            $cacheKey,
            json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            lazy: true,
        );
        return $result;
    }

    /**
     * @return array{generated_at:int, outbound_ip:?string, ip_source:?string, checks:array<int,array<string,mixed>>}
     */
    private function runAll(DmarcDomain $domain): array {
        $name = strtolower($domain->getDomain());
        $checks = [];

        $mailTest = $this->latestCompletedTest((int)$domain->getId());
        $analysis = $this->decodeAnalysis($mailTest);

        [$outboundIp, $ipSource] = $this->resolveOutboundIp($name, $analysis);

        // --- DNS records -------------------------------------------------
        $spfRecord   = $this->dns->spfRecord($name);
        $dmarcRecord = $this->dns->dmarcRecord($name);

        $checks[] = $this->checkSpfRecord($spfRecord);
        $checks[] = $this->checkDmarcPolicy($dmarcRecord);
        $checks[] = $this->checkMtaSts($name);
        $checks[] = $this->checkTlsRpt($name);
        $checks[] = $this->checkBimi($name, $dmarcRecord);

        // --- Outbound identity (PTR / HELO / TLS) ------------------------
        $ptrHost = null;
        if ($outboundIp !== null) {
            $ptrHost = $this->dns->ptr($outboundIp);
        }
        $checks[] = $this->checkPtr($outboundIp, $ptrHost);
        $checks[] = $this->checkHeloAndTls($name, $outboundIp, $ptrHost, $checks);

        // --- Mail-test derived checks ------------------------------------
        $checks[] = $this->checkDkim($mailTest, $analysis);
        $checks[] = $this->checkSpfAlignment($name, $mailTest, $analysis);
        $checks[] = $this->checkDkimAlignment($name, $mailTest, $analysis);
        $checks[] = $this->checkOneClickUnsubscribe($analysis);

        // --- Blacklists (provider.tools, 120+ DNSBLs) --------------------
        $checks[] = $this->checkBlacklist('blacklist_ip', $outboundIp, 'ip');
        $checks[] = $this->checkBlacklist('blacklist_domain', $name, 'domain');

        return [
            'generated_at' => time(),
            'outbound_ip'  => $outboundIp,
            'ip_source'    => $ipSource,
            'checks'       => $checks,
        ];
    }

    // -------------------------------------------------------------------
    // Individual checks
    // -------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function checkSpfRecord(?string $record): array {
        if ($record === null) {
            return $this->c('spf_record', 'fail', ['record' => null]);
        }
        $observed = ['record' => $record];
        $lookups = preg_match_all('/(?:^|\s)(?:include:|a[:\s]|mx[:\s]|ptr[:\s]|exists:|redirect=)/i', $record);
        $observed['dns_lookups_estimate'] = (int)$lookups;

        if (preg_match('/[+?]all\s*$/i', trim($record)) === 1) {
            return $this->c('spf_record', 'warn', $observed + ['issue' => 'permissive_all']);
        }
        if ($lookups > 10) {
            return $this->c('spf_record', 'warn', $observed + ['issue' => 'too_many_lookups']);
        }
        return $this->c('spf_record', 'ok', $observed);
    }

    /** @return array<string,mixed> */
    private function checkDmarcPolicy(?string $record): array {
        if ($record === null) {
            return $this->c('dmarc_policy', 'fail', ['record' => null]);
        }
        $p   = strtolower((string)($this->dns->parseTag($record, 'p') ?? ''));
        $rua = $this->dns->parseTag($record, 'rua');
        $pct = $this->dns->parseTag($record, 'pct');
        $ruf = $this->dns->parseTag($record, 'ruf');
        $observed = ['record' => $record, 'p' => $p, 'rua' => $rua, 'ruf' => $ruf, 'pct' => $pct];

        if ($p === 'none' || $p === '') {
            return $this->c('dmarc_policy', 'warn', $observed + ['issue' => 'policy_none']);
        }
        if ($rua === null) {
            return $this->c('dmarc_policy', 'warn', $observed + ['issue' => 'no_rua']);
        }
        if ($pct !== null && (int)$pct < 100) {
            return $this->c('dmarc_policy', 'warn', $observed + ['issue' => 'partial_pct']);
        }
        return $this->c('dmarc_policy', 'ok', $observed);
    }

    /** @return array<string,mixed> */
    private function checkMtaSts(string $domain): array {
        $txt = $this->dns->findTxt('_mta-sts.' . $domain, 'v=STSv1');
        if ($txt === null) {
            return $this->c('mta_sts', 'info', ['record' => null]);
        }
        $policy = $this->dns->fetchMtaStsPolicy($domain);
        if ($policy === null) {
            return $this->c('mta_sts', 'fail', ['record' => $txt, 'policy' => null, 'issue' => 'policy_unreachable']);
        }
        $mode = null;
        if (preg_match('/^\s*mode\s*:\s*(\w+)/im', $policy, $m) === 1) {
            $mode = strtolower($m[1]);
        }
        $observed = ['record' => $txt, 'mode' => $mode];
        if ($mode === 'enforce') {
            return $this->c('mta_sts', 'ok', $observed);
        }
        return $this->c('mta_sts', 'warn', $observed + ['issue' => 'mode_not_enforce']);
    }

    /** @return array<string,mixed> */
    private function checkTlsRpt(string $domain): array {
        $txt = $this->dns->findTxt('_smtp._tls.' . $domain, 'v=TLSRPTv1');
        if ($txt === null) {
            return $this->c('tls_rpt', 'info', ['record' => null]);
        }
        return $this->c('tls_rpt', 'ok', ['record' => $txt]);
    }

    /** @return array<string,mixed> */
    private function checkBimi(string $domain, ?string $dmarcRecord): array {
        $txt = $this->dns->findTxt('default._bimi.' . $domain, 'v=BIMI1');
        if ($txt === null) {
            return $this->c('bimi', 'info', ['record' => null]);
        }
        $p = $dmarcRecord !== null ? strtolower((string)($this->dns->parseTag($dmarcRecord, 'p') ?? '')) : '';
        $observed = ['record' => $txt, 'logo' => $this->dns->parseTag($txt, 'l'), 'dmarc_p' => $p];
        if (!in_array($p, ['quarantine', 'reject'], true)) {
            return $this->c('bimi', 'warn', $observed + ['issue' => 'dmarc_not_enforcing']);
        }
        return $this->c('bimi', 'ok', $observed);
    }

    /** @return array<string,mixed> */
    private function checkPtr(?string $ip, ?string $ptrHost): array {
        if ($ip === null) {
            return $this->c('ptr', 'nodata', ['reason' => 'no_outbound_ip']);
        }
        if ($ptrHost === null) {
            return $this->c('ptr', 'fail', ['ip' => $ip, 'ptr' => null]);
        }
        $forward = $this->dns->aRecords($ptrHost);
        $fcrdns = in_array($ip, $forward, true);
        $observed = ['ip' => $ip, 'ptr' => $ptrHost, 'fcrdns' => $fcrdns];
        return $this->c('ptr', $fcrdns ? 'ok' : 'warn', $observed + ($fcrdns ? [] : ['issue' => 'fcrdns_mismatch']));
    }

    /**
     * Probe the outbound mail server for its SMTP banner identity and
     * STARTTLS capability *from the outside world's perspective*.
     *
     * Prior to v3.8.1 this probed `stalwart_api_url` first – that host
     * is the **internal management endpoint** and may answer on :25
     * with a different banner (e.g. `mx.example.com`) than what any
     * external MTA actually sees. External peers reach us via MX → IP
     * → PTR, so those are the only correct probe targets.
     *
     * Priority: PTR host of the outbound IP (canonical external
     * identity), then the domain's MX (falls through when PTR is
     * missing or explicitly diverges).
     *
     * @param array<int,array<string,mixed>> $prior unused, kept for signature clarity
     * @return array<string,mixed>
     */
    private function checkHeloAndTls(string $domain, ?string $ip, ?string $ptrHost, array $prior): array {
        $targets = [];
        if ($ptrHost !== null && $ptrHost !== '' && str_contains($ptrHost, '.')) {
            $targets[] = ['host' => $ptrHost, 'kind' => 'ptr'];
        }
        foreach (array_slice($this->dns->mxRecords($domain), 0, 1) as $mx) {
            if ($mx === null || $mx === '') {
                continue;
            }
            if ($ptrHost !== null && strcasecmp($mx, $ptrHost) === 0) {
                continue; // same host we already queued as PTR target
            }
            $targets[] = ['host' => $mx, 'kind' => 'mx'];
        }
        if ($targets === []) {
            return $this->c('helo_tls', 'nodata', ['reason' => 'no_probe_target']);
        }

        $lastError = null;
        foreach ($targets as $target) {
            $probe = $this->probe->probe($target['host'], 25);
            if (!$probe['reachable']) {
                $lastError = $probe['error'];
                continue;
            }
            $banner = $probe['banner_host'];
            $observed = [
                'probed_host' => $target['host'],
                'probe_kind'  => $target['kind'],
                'banner_host' => $banner,
                'starttls'    => $probe['starttls'],
                'ptr'         => $ptrHost,
            ];
            $bannerIsFqdn = is_string($banner) && str_contains($banner, '.')
                && stripos($banner, 'localhost') === false;
            if (!$probe['starttls']) {
                return $this->c('helo_tls', 'fail', $observed + ['issue' => 'no_starttls']);
            }
            if (!$bannerIsFqdn) {
                return $this->c('helo_tls', 'warn', $observed + ['issue' => 'banner_not_fqdn']);
            }
            if ($ptrHost !== null && strcasecmp($banner, $ptrHost) !== 0) {
                return $this->c('helo_tls', 'warn', $observed + ['issue' => 'banner_ptr_mismatch']);
            }
            return $this->c('helo_tls', 'ok', $observed);
        }
        return $this->c('helo_tls', 'nodata', ['reason' => 'unreachable', 'error' => $lastError]);
    }

    /** @return array<string,mixed> */
    private function checkDkim(?MailTest $test, array $analysis): array {
        if ($test === null) {
            return $this->c('dkim', 'nodata', ['reason' => 'no_completed_mail_test']);
        }
        $result = strtolower((string)($test->getDkimResult() ?? ''));
        $selector = null;
        if (isset($analysis['dkim']) && is_array($analysis['dkim'])) {
            $selector = $analysis['dkim']['selector'] ?? null;
        }
        $observed = ['result' => $result, 'selector' => $selector, 'test_id' => $test->getTestId()];
        if ($result === 'pass') {
            return $this->c('dkim', 'ok', $observed);
        }
        if ($result === '' || $result === 'none') {
            return $this->c('dkim', 'warn', $observed + ['issue' => 'not_signed']);
        }
        return $this->c('dkim', 'fail', $observed);
    }

    /** @return array<string,mixed> */
    private function checkSpfAlignment(string $domain, ?MailTest $test, array $analysis): array {
        if ($test === null) {
            return $this->c('spf_alignment', 'nodata', ['reason' => 'no_completed_mail_test']);
        }
        $spf   = strtolower((string)($test->getSpfResult() ?? ''));
        $dmarc = strtolower((string)($test->getDmarcResult() ?? ''));
        $spfDomain = null;
        if (isset($analysis['spf']) && is_array($analysis['spf'])) {
            $spfDomain = strtolower((string)($analysis['spf']['domain'] ?? ''));
        }
        $observed = ['spf' => $spf, 'dmarc' => $dmarc, 'spf_domain' => $spfDomain ?: null];
        if ($spf !== 'pass') {
            return $this->c('spf_alignment', 'fail', $observed + ['issue' => 'spf_not_pass']);
        }
        $aligned = $spfDomain === null || $spfDomain === ''
            ? ($dmarc === 'pass')
            : ($spfDomain === $domain || str_ends_with($spfDomain, '.' . $domain));
        return $this->c('spf_alignment', $aligned ? 'ok' : 'fail', $observed + ($aligned ? [] : ['issue' => 'unaligned']));
    }

    /** @return array<string,mixed> */
    private function checkDkimAlignment(string $domain, ?MailTest $test, array $analysis): array {
        if ($test === null) {
            return $this->c('dkim_alignment', 'nodata', ['reason' => 'no_completed_mail_test']);
        }
        $dkim  = strtolower((string)($test->getDkimResult() ?? ''));
        $dmarc = strtolower((string)($test->getDmarcResult() ?? ''));
        $dkimDomain = null;
        if (isset($analysis['dkim']) && is_array($analysis['dkim'])) {
            $dkimDomain = strtolower((string)($analysis['dkim']['domain'] ?? ''));
        }
        $observed = ['dkim' => $dkim, 'dmarc' => $dmarc, 'dkim_domain' => $dkimDomain ?: null];
        if ($dkim !== 'pass') {
            return $this->c('dkim_alignment', 'fail', $observed + ['issue' => 'dkim_not_pass']);
        }
        $aligned = $dkimDomain === null || $dkimDomain === ''
            ? ($dmarc === 'pass')
            : ($dkimDomain === $domain || str_ends_with($dkimDomain, '.' . $domain));
        return $this->c('dkim_alignment', $aligned ? 'ok' : 'fail', $observed + ($aligned ? [] : ['issue' => 'unaligned']));
    }

    /** @return array<string,mixed> */
    private function checkOneClickUnsubscribe(array $analysis): array {
        $headers = $analysis['headers'] ?? null;
        if ($headers === null) {
            return $this->c('one_click_unsub', 'info', ['reason' => 'no_header_data']);
        }
        $flat = strtolower(is_string($headers)
            ? $headers
            : (json_encode($headers, JSON_UNESCAPED_SLASHES) ?: ''));
        $hasList = str_contains($flat, 'list-unsubscribe');
        $hasPost = str_contains($flat, 'list-unsubscribe-post');
        if ($hasList && $hasPost) {
            return $this->c('one_click_unsub', 'ok', ['list_unsubscribe' => true, 'one_click' => true]);
        }
        if ($hasList) {
            return $this->c('one_click_unsub', 'warn', ['list_unsubscribe' => true, 'one_click' => false]);
        }
        return $this->c('one_click_unsub', 'info', ['list_unsubscribe' => false, 'one_click' => false]);
    }

    /** @return array<string,mixed> */
    private function checkBlacklist(string $id, ?string $target, string $type): array {
        if ($target === null || $target === '') {
            return $this->c($id, 'nodata', ['reason' => $type === 'ip' ? 'no_outbound_ip' : 'no_domain']);
        }
        try {
            $data = $this->provider->checkBlacklist($target, $type);
        } catch (ProviderToolsException $e) {
            return $this->c($id, 'nodata', ['reason' => 'provider_error', 'error' => $e->getMessage()]);
        }
        $listedCount = (int)($data['listedCount'] ?? 0);
        $listed = [];
        $critical = false;
        foreach (($data['blacklists'] ?? []) as $bl) {
            if (!is_array($bl) || empty($bl['listed'])) {
                continue;
            }
            $listed[] = ['name' => (string)($bl['name'] ?? '?'), 'category' => (string)($bl['category'] ?? '')];
            if (strtolower((string)($bl['category'] ?? '')) === 'critical') {
                $critical = true;
            }
        }
        $observed = [
            'target'       => $target,
            'totalChecked' => (int)($data['totalChecked'] ?? 0),
            'listedCount'  => $listedCount,
            'listed'       => $listed,
        ];
        if ($listedCount === 0) {
            return $this->c($id, 'ok', $observed);
        }
        return $this->c($id, $critical ? 'fail' : 'warn', $observed);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /** @return array{0:?string,1:?string} [ip, source] */
    private function resolveOutboundIp(string $domain, array $analysis): array {
        // 1) The IP provider.tools actually saw connecting – the ground truth.
        $ip = $this->extractIpFromAnalysis($analysis);
        if ($ip !== null) {
            return [$ip, 'mail_test'];
        }
        // 2) Stalwart host A record (customer's outbound MTA).
        $stalwartHost = $this->stalwartHost();
        if ($stalwartHost !== null) {
            $ips = filter_var($stalwartHost, FILTER_VALIDATE_IP) !== false
                ? [$stalwartHost]
                : $this->dns->aRecords($stalwartHost);
            foreach ($ips as $candidate) {
                if ($this->isPublicIp($candidate)) {
                    return [$candidate, 'stalwart'];
                }
            }
        }
        // 3) MX A record of the domain.
        foreach (array_slice($this->dns->mxRecords($domain), 0, 1) as $mx) {
            foreach ($this->dns->aRecords($mx) as $candidate) {
                if ($this->isPublicIp($candidate)) {
                    return [$candidate, 'mx'];
                }
            }
        }
        return [null, null];
    }

    private function isPublicIp(string $ip): bool {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function stalwartHost(): ?string {
        $raw = trim((string)($this->central->read('stalwart_api_url') ?? ''));
        if ($raw === '') {
            return null;
        }
        $parts = parse_url($raw);
        if (is_array($parts) && !empty($parts['host'])) {
            return $parts['host'];
        }
        $stripped = preg_replace('~^[a-z][a-z0-9+\-.]*://~i', '', $raw) ?? $raw;
        $stripped = preg_replace('~[/?#].*$~', '', $stripped) ?? $stripped;
        $stripped = preg_replace('~:\d+$~', '', $stripped) ?? $stripped;
        return trim($stripped) !== '' ? trim($stripped) : null;
    }

    private function latestCompletedTest(int $domainId): ?MailTest {
        foreach ($this->tests->findRecent(50, $domainId) as $t) {
            if ($t->getStatus() === MailTest::STATUS_COMPLETED) {
                return $t;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function decodeAnalysis(?MailTest $test): array {
        if ($test === null) {
            return [];
        }
        $raw = json_decode((string)($test->getRawResult() ?? ''), true);
        if (!is_array($raw)) {
            return [];
        }
        $analysis = $raw['analysis'] ?? null;
        return is_array($analysis) ? $analysis : [];
    }

    private function extractIpFromAnalysis(array $analysis): ?string {
        foreach (['ip', 'sourceIp', 'senderIp', 'clientIp', 'remoteIp'] as $key) {
            $v = $analysis[$key] ?? null;
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false) {
                return $v;
            }
        }
        $server = $analysis['serverConfig'] ?? null;
        if (is_array($server)) {
            foreach (['ip', 'sourceIp', 'senderIp', 'address'] as $key) {
                $v = $server[$key] ?? null;
                if (is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false) {
                    return $v;
                }
            }
        }
        // Last resort: scan the raw headers for a bracketed IPv4.
        $headers = $analysis['headers'] ?? null;
        if ($headers !== null) {
            $flat = is_string($headers) ? $headers : (json_encode($headers) ?: '');
            if (preg_match('/\[(\d{1,3}(?:\.\d{1,3}){3})\]/', $flat, $m) === 1
                && $this->isPublicIp($m[1])) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $observed
     * @return array<string,mixed>
     */
    private function c(string $id, string $status, array $observed): array {
        return ['id' => $id, 'status' => $status, 'observed' => $observed];
    }
}
