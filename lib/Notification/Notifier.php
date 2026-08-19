<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Notification;

use OCA\SouveraShield\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Localised renderer for the "new mails in quarantine" bell notification
 * raised by {@see \OCA\SouveraShield\BackgroundJob\PollQuarantineJob}.
 */
class Notifier implements INotifier {

    public function __construct(
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $url,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return 'Souvera Shield';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new \InvalidArgumentException('Wrong app');
        }
        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

        if ($notification->getSubject() === 'new_quarantine') {
            $params = $notification->getSubjectParameters();
            $count = (int)($params['count'] ?? 0);

            $notification
                ->setRichSubject($l->n('%n new mail in quarantine', '%n new mails in quarantine', $count))
                ->setParsedSubject($l->n('%n new mail in quarantine', '%n new mails in quarantine', $count))
                ->setLink($this->url->linkToRouteAbsolute('souvera_shield.page.quarantine'))
                ->setIcon($this->url->getAbsoluteURL(
                    $this->url->imagePath(Application::APP_ID, 'appicon.svg')
                ));
        }
        return $notification;
    }
}
