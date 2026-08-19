<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCP\IAppConfig;

/**
 * Read-only access to the Shield-facing settings that live in the
 * **Souvera Central** app-config namespace.
 *
 * Only the three per-app global switches live here:
 *   settings.shield.desktop_notifications   "0" | "1"
 *   settings.shield.daily_summary           "0" | "1"
 *   settings.shield.min_spam_score          float (default "2.5")
 *
 * v3.4.3: Shield no longer touches Souvera Central's Stalwart config
 * here. The mail-test relay reads Stalwart coordinates itself in
 * {@see MailTestService::dispatchEmail()} and connects without AUTH
 * (trust-based internal relay).
 */
class CentralSettings {

    public const APP = 'souvera_central';

    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
    }

    public function desktopNotificationsEnabled(): bool {
        return $this->appConfig->getValueString(self::APP, 'settings.shield.desktop_notifications', '0') === '1';
    }

    public function dailySummaryEnabled(): bool {
        return $this->appConfig->getValueString(self::APP, 'settings.shield.daily_summary', '0') === '1';
    }

    public function minSpamScore(): float {
        return (float)$this->appConfig->getValueString(self::APP, 'settings.shield.min_spam_score', '2.5');
    }

    /** Hour of day (0-23) at which the daily spam report is sent. */
    public function reportHour(): int {
        $hour = (int)$this->appConfig->getValueString(self::APP, 'settings.shield.report_hour', '6');
        return max(0, min(23, $hour));
    }

    /** True when the PMG built-in spam report must be disabled globally. */
    public function pmgReportDisableEnabled(): bool {
        return $this->appConfig->getValueString(self::APP, 'settings.shield.pmg_report_disable', '1') === '1';
    }

    // ===================================================================
    // Suspicious Login Detection settings
    // ===================================================================

    public function suspiciousLoginDetectionEnabled(): bool {
        return $this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.detection_enabled', '1') === '1';
    }

    public function suspiciousLoginGracePeriodDays(): int {
        return (int)$this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.grace_period_days', '14');
    }

    public function suspiciousLoginScoreThreshold(): int {
        return (int)$this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.score_threshold', '20');
    }

    public function suspiciousLoginNotifyHighSeverity(): bool {
        return $this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.notify_high_severity', '1') === '1';
    }

    public function suspiciousLoginNotifyCriticalSeverity(): bool {
        return $this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.notify_critical_severity', '1') === '1';
    }

    public function suspiciousLoginRetentionDays(): int {
        return (int)$this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.retention_days', '90');
    }

    public function suspiciousLoginRetentionResolvedDays(): int {
        return (int)$this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.retention_resolved_days', '30');
    }

    public function suspiciousLoginAutoResolveAfterDays(): int {
        return (int)$this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.auto_resolve_after_days', '30');
    }

    public function suspiciousLoginMaxEventsPerHour(): int {
        return (int)$this->appConfig->getValueString(self::APP, 'settings.shield.suspicious_login.max_events_per_hour', '10');
    }
}
