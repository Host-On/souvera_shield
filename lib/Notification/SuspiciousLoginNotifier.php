<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Notification;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\SuspiciousEvent;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use Psr\Log\LoggerInterface;

/**
 * Sends bell notifications for suspicious login events (high/critical severity).
 *
 * Registers as an INotifier for subject rendering, and exposes a `notify()`
 * method that creates and dispatches notifications.
 */
class SuspiciousLoginNotifier implements INotifier {

    public function __construct(
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $url,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return 'Souvera Shield – Suspicious Login Detection';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new \InvalidArgumentException('Wrong app');
        }
        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

        if ($notification->getSubject() === 'suspicious_login') {
            $params = $notification->getSubjectParameters();
            $severity = (string)($params['severity'] ?? 'unknown');
            $ip = (string)($params['ip'] ?? 'unknown');
            $score = (int)($params['score'] ?? 0);

            $notification
                ->setRichSubject(
                    $l->t('Suspicious login detected') . ' (' . $severity . ')',
                    [
                        'severity' => ['type' => 'highlight', 'id' => $severity, 'name' => $severity],
                        'score'    => ['type' => 'highlight', 'id' => (string)$score, 'name' => (string)$score],
                        'ip'       => ['type' => 'highlight', 'id' => $ip, 'name' => $ip],
                    ]
                )
                ->setParsedSubject(
                    $l->t('Suspicious login detected') . ' (' . $severity . ', score ' . $score . ', IP ' . $ip . ')'
                )
                ->setLink($this->url->linkToRouteAbsolute('souvera_shield.page.quarantine'))
                ->setIcon($this->url->getAbsoluteURL(
                    $this->url->imagePath(Application::APP_ID, 'appicon.svg')
                ));
        }

        return $notification;
    }

    /**
     * Create and dispatch a notification for a suspicious event.
     */
    public function notify(SuspiciousEvent $event, string $forUserId): void {
        $notification = $this->notificationManager->createNotification();
        $notification
            ->setApp(Application::APP_ID)
            ->setUser($forUserId)
            ->setDateTime(new \DateTime())
            ->setObject('suspicious_login', (string)$event->getId())
            ->setSubject('suspicious_login', [
                'severity' => $event->getSeverity(),
                'ip'       => $event->getIp() ?? 'unknown',
                'score'    => $event->getConfidence() ?? 0,
            ]);

        $this->notificationManager->notify($notification);
    }
}
