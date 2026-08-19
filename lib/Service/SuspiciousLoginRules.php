<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

/**
 * Pure PHP rules engine for the Suspicious Login Detection feature.
 *
 * Applies 11 independent rules to score a login trace against the user's
 * baseline, IP enrichment data, previous feedback, and neighbor traces.
 * Each rule returns an integer point value (0 = no risk, positive = risk).
 */
class SuspiciousLoginRules {

    // Rule weight constants
    private const SCORE_NEW_COUNTRY          = 15;
    private const SCORE_NEW_ISP              = 10;
    private const SCORE_NEW_SUBNET           = 12;
    private const SCORE_NEW_DEVICE           = 8;
    private const SCORE_OFF_HOURS            = 10;
    private const SCORE_HOSTING              = 25;
    private const SCORE_VPN_PROXY            = 30;
    private const SCORE_TOR                  = 35;
    private const SCORE_BLOCKLISTED          = 20;
    private const SCORE_LOGIN_SPIKE          = 15;
    private const SCORE_FAILED_THEN_SUCCESS  = 10;

    /**
     * Score a login trace and return the full assessment.
     *
     * @param array{trace:array, enrichment:?array, baseline:?array, feedback:?array, previousTraces:array} $ctx
     * @return array{score:int, rules:array<string,int>, decision:string, severity:string}
     */
    public function score(
        array $trace,
        ?array $baseline,
        ?array $enrichment,
        ?array $feedback,
        ?array $previousTraces,
    ): array {
        $rules = [];
        $total = 0;

        // Without an existing baseline there is nothing to compare against —
        // the "new_*" rules would fire on a user's very FIRST login and
        // produce a false-positive medium event for every new account.
        // Only environment-based rules (hosting/vpn/tor/blocklist/spike)
        // apply until a baseline exists.
        $hasBaseline = $baseline !== null && !empty($baseline['total_logins']);

        $points = $hasBaseline ? $this->ruleNewCountry($trace, $baseline) : 0;
        $rules['new_country'] = $points;
        $total += $points;

        $points = $hasBaseline ? $this->ruleNewIsp($trace, $baseline, $enrichment) : 0;
        $rules['new_isp'] = $points;
        $total += $points;

        $points = $hasBaseline ? $this->ruleNewSubnet($trace, $baseline) : 0;
        $rules['new_subnet'] = $points;
        $total += $points;

        $points = $hasBaseline ? $this->ruleNewDevice($trace, $baseline) : 0;
        $rules['new_device'] = $points;
        $total += $points;

        $points = $this->ruleOffHours($trace, $baseline);
        $rules['off_hours'] = $points;
        $total += $points;

        $points = $this->ruleHosting($enrichment);
        $rules['hosting'] = $points;
        $total += $points;

        $points = $this->ruleVpnProxy($enrichment);
        $rules['vpn_proxy'] = $points;
        $total += $points;

        $points = $this->ruleTor($enrichment);
        $rules['tor'] = $points;
        $total += $points;

        $points = $this->ruleBlocklisted($enrichment);
        $rules['blocklisted'] = $points;
        $total += $points;

        $points = $this->ruleLoginSpike($trace, $previousTraces);
        $rules['login_spike'] = $points;
        $total += $points;

        $points = $this->ruleFailedThenSuccess($trace, $previousTraces);
        $rules['failed_then_success'] = $points;
        $total += $points;

        // Apply feedback adjustments (reduce score for trusted feedbacks)
        if ($feedback !== null && !empty($feedback)) {
            $adjustment = $this->applyFeedback($feedback);
            $rules['feedback_adjustment'] = $adjustment;
            $total = max(0, $total + $adjustment);
        }

        $total = min(100, max(0, $total));

        return [
            'score'    => $total,
            'rules'    => $rules,
            'decision' => $this->decision($total),
            'severity' => $this->severity($total),
        ];
    }

    /**
     * Map numeric score to severity label.
     */
    public function severity(int $score): string {
        if ($score >= 80) {
            return 'critical';
        }
        if ($score >= 60) {
            return 'high';
        }
        if ($score >= 40) {
            return 'medium';
        }
        if ($score >= 20) {
            return 'low';
        }
        return 'none';
    }

    /**
     * Map numeric score to a human-readable decision summary.
     */
    public function decision(int $score): string {
        if ($score >= 80) {
            return 'Critical risk – immediate review required. Possible account compromise.';
        }
        if ($score >= 60) {
            return 'High risk – unusual login pattern detected. Recommend investigation.';
        }
        if ($score >= 40) {
            return 'Medium risk – atypical login behavior observed.';
        }
        if ($score >= 20) {
            return 'Low risk – minor deviation from normal pattern.';
        }
        return 'Normal – no suspicious activity detected.';
    }

    /**
     * Compute trusted subnets from a user's trace history.
     *
     * @param array<int,array> $traces List of trace arrays with at minimum 'ip_subnet' and possibly 'created_at'
     * @return array<string>
     */
    public function computeTrustedSubnets(array $traces): array {
        $counts = [];
        foreach ($traces as $t) {
            $subnet = $t['ip_subnet'] ?? null;
            if ($subnet === null || $subnet === '') {
                continue;
            }
            $counts[$subnet] = ($counts[$subnet] ?? 0) + 1;
        }
        arsort($counts);
        // Top subnets that cover >= 2% of logins or top 5
        $total = array_sum($counts);
        $trusted = [];
        $i = 0;
        foreach ($counts as $subnet => $count) {
            if ($i >= 5 || ($total > 0 && ($count / $total) < 0.02)) {
                break;
            }
            $trusted[] = $subnet;
            $i++;
        }
        return $trusted;
    }

    /**
     * Compute trusted countries from a user's trace history.
     *
     * @param array<int,array> $traces
     * @return array<string>
     */
    public function computeTrustedCountries(array $traces): array {
        $counts = [];
        foreach ($traces as $t) {
            $country = $t['geo_country'] ?? null;
            if ($country === null || $country === '') {
                continue;
            }
            $counts[$country] = ($counts[$country] ?? 0) + 1;
        }
        arsort($counts);
        $trusted = [];
        $i = 0;
        foreach ($counts as $country => $count) {
            if ($i >= 5) {
                break;
            }
            $trusted[] = $country;
            $i++;
        }
        return $trusted;
    }

    /**
     * Compute typical login hours (UTC) from a user's trace history.
     *
     * @param array<int,array> $traces
     * @return array<int>
     */
    public function computeTypicalHours(array $traces): array {
        $hours = [];
        foreach ($traces as $t) {
            $ts = $t['created_at'] ?? null;
            if ($ts === null || $ts <= 0) {
                continue;
            }
            $h = (int)gmdate('G', $ts);
            $hours[$h] = ($hours[$h] ?? 0) + 1;
        }
        if (empty($hours)) {
            return range(0, 23);
        }
        arsort($hours);
        $typical = array_keys(array_slice($hours, 0, 14, true));
        sort($typical);
        return $typical;
    }

    // ===================================================================
    // Individual rule methods
    // ===================================================================

    private function ruleNewCountry(array $trace, ?array $baseline): int {
        $country = $trace['geo_country'] ?? null;
        if ($country === null || $country === '') {
            return 0;
        }
        if ($baseline === null || empty($baseline['trusted_countries'])) {
            return self::SCORE_NEW_COUNTRY;
        }
        $trusted = is_array($baseline['trusted_countries'])
            ? $baseline['trusted_countries']
            : json_decode((string)$baseline['trusted_countries'], true) ?? [];
        if (in_array($country, $trusted, true)) {
            return 0;
        }
        return self::SCORE_NEW_COUNTRY;
    }

    private function ruleNewIsp(array $trace, ?array $baseline, ?array $enrichment): int {
        $isp = $trace['isp_name'] ?? $enrichment['isp'] ?? null;
        if ($isp === null || $isp === '') {
            return 0;
        }
        if ($baseline === null || empty($baseline['trusted_isps'])) {
            return self::SCORE_NEW_ISP;
        }
        $trusted = is_array($baseline['trusted_isps'])
            ? $baseline['trusted_isps']
            : json_decode((string)$baseline['trusted_isps'], true) ?? [];
        foreach ($trusted as $t) {
            // One-directional, length-guarded substring match: a new ISP is
            // trusted when it CONTAINS a trusted name (>= 4 chars). The
            // reverse direction ("trusted contains new ISP") is dangerous —
            // a short attacker-controlled ISP name like "Telekom" would
            // match "Telekom Deutschland" and silently become trusted.
            if (\strlen((string) $t) >= 4 && \stripos($isp, $t) !== false) {
                return 0;
            }
        }
        return self::SCORE_NEW_ISP;
    }

    private function ruleNewSubnet(array $trace, ?array $baseline): int {
        $subnet = $trace['ip_subnet'] ?? null;
        if ($subnet === null || $subnet === '') {
            return 0;
        }
        if ($baseline === null || empty($baseline['trusted_subnets'])) {
            return self::SCORE_NEW_SUBNET;
        }
        $trusted = is_array($baseline['trusted_subnets'])
            ? $baseline['trusted_subnets']
            : json_decode((string)$baseline['trusted_subnets'], true) ?? [];
        if (in_array($subnet, $trusted, true)) {
            return 0;
        }
        return self::SCORE_NEW_SUBNET;
    }

    private function ruleNewDevice(array $trace, ?array $baseline): int {
        $hash = $trace['device_hash'] ?? null;
        if ($hash === null || $hash === '') {
            return 0;
        }
        if ($baseline === null || empty($baseline['trusted_devices'])) {
            return self::SCORE_NEW_DEVICE;
        }
        $trusted = is_array($baseline['trusted_devices'])
            ? $baseline['trusted_devices']
            : json_decode((string)$baseline['trusted_devices'], true) ?? [];
        if (in_array($hash, $trusted, true)) {
            return 0;
        }
        return self::SCORE_NEW_DEVICE;
    }

    private function ruleOffHours(array $trace, ?array $baseline): int {
        $ts = $trace['created_at'] ?? time();
        $hour = (int)gmdate('G', $ts);
        if ($baseline === null || empty($baseline['typical_hours'])) {
            return 0;
        }
        $typical = is_array($baseline['typical_hours'])
            ? $baseline['typical_hours']
            : json_decode((string)$baseline['typical_hours'], true) ?? [];
        if (in_array($hour, $typical, true)) {
            return 0;
        }
        return self::SCORE_OFF_HOURS;
    }

    private function ruleHosting(?array $enrichment): int {
        if ($enrichment === null) {
            return 0;
        }
        return !empty($enrichment['hosting']) ? self::SCORE_HOSTING : 0;
    }

    private function ruleVpnProxy(?array $enrichment): int {
        if ($enrichment === null) {
            return 0;
        }
        $vpn   = !empty($enrichment['vpn']);
        $proxy = !empty($enrichment['proxy']);
        return ($vpn || $proxy) ? self::SCORE_VPN_PROXY : 0;
    }

    private function ruleTor(?array $enrichment): int {
        if ($enrichment === null) {
            return 0;
        }
        return !empty($enrichment['tor']) ? self::SCORE_TOR : 0;
    }

    private function ruleBlocklisted(?array $enrichment): int {
        if ($enrichment === null) {
            return 0;
        }
        $blocklists = $enrichment['blocklists'] ?? [];
        if (is_array($blocklists) && count($blocklists) > 0) {
            return self::SCORE_BLOCKLISTED;
        }
        return 0;
    }

    private function ruleLoginSpike(array $trace, ?array $previousTraces): int {
        if ($previousTraces === null || count($previousTraces) < 3) {
            return 0;
        }
        $now = $trace['created_at'] ?? time();
        $windowStart = $now - 3600; // 1 hour window

        $count = 0;
        foreach ($previousTraces as $pt) {
            $ts = $pt['created_at'] ?? 0;
            if ($ts >= $windowStart && $ts <= $now) {
                $count++;
            }
        }
        return $count >= 5 ? self::SCORE_LOGIN_SPIKE : 0;
    }

    private function ruleFailedThenSuccess(array $trace, ?array $previousTraces): int {
        if ($previousTraces === null) {
            return 0;
        }
        $success = $trace['success'] ?? null;
        if ($success === null || $success !== 1) {
            return 0;
        }
        $now = $trace['created_at'] ?? time();
        $windowStart = $now - 900; // 15 minutes

        $failCount = 0;
        foreach ($previousTraces as $pt) {
            $ts = $pt['created_at'] ?? 0;
            $s = $pt['success'] ?? null;
            if ($ts >= $windowStart && $ts < $now && $s === 0) {
                $failCount++;
            }
        }
        return $failCount >= 3 ? self::SCORE_FAILED_THEN_SUCCESS : 0;
    }

    /**
     * Apply user/admin feedback to adjust the score.
     *
     * @param array<int,array> $feedbacks
     */
    private function applyFeedback(array $feedbacks): int {
        foreach ($feedbacks as $fb) {
            $type = $fb['feedback'] ?? '';
            switch ($type) {
                case 'confirmed_threat':
                    return 20;
                case 'false_positive':
                case 'known_location':
                case 'user_travel':
                    return -30;
            }
        }
        return 0;
    }
}
