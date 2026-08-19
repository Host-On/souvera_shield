<?php
declare(strict_types=1);

namespace OCA\SouveraShield\BackgroundJob;

use OCA\SouveraShield\AppInfo\Application;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Täglicher Integritäts-Check des E-Mail-Archivs.
 * Sendet eine Notification wenn die Chain beschädigt ist.
 *
 * @see ARCHIVE_PLAN §2.4b
 */
class ArchiveIntegrityCheckJob extends TimedJob
{
	public function __construct(
		\OCP\AppFramework\Utility\ITimeFactory $time,
		private IManager $notificationManager,
		private IConfig $config,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$enabled = $this->config->getAppValue('souvera_central', 'archive.enabled', '0') === '1';
		if (!$enabled) {
			return;
		}

		$cmApiUrl = $this->config->getSystemValue('souvera_central.cm_api_url', '');
		$cmApiKey = $this->config->getSystemValue('souvera_central.cm_api_key', '');
		if (!$cmApiUrl || !$cmApiKey) {
			return;
		}

		try {
			$tenantId = $this->config->getSystemValue('souvera_central.tenant_id', 'default');
			$client = $this->clientService->newClient();
			$response = $client->get("{$cmApiUrl}/clouds/{$tenantId}/archive/integrity", [
				'headers' => [
					'Authorization' => 'Bearer ' . $cmApiKey,
					'Accept' => 'application/json',
				],
				'timeout' => 15,
				'verify' => false,
			]);
			$status = json_decode($response->getBody(), true) ?? [];
		} catch (\Throwable $e) {
			$this->logger->warning('ArchiveIntegrityCheckJob: CM API call failed', [
				'exception' => $e->getMessage(),
			]);
			return;
		}

		$chainStatus = $status['chain_status'] ?? 'unknown';
		if ($chainStatus !== 'ok') {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser('') // Admin notification for all
				->setSubject('souvera_shield_archive_integrity')
				->setObject('archive_integrity', 'chain')
				->setSubjectParameters(['chain_status' => $chainStatus])
				->setMessage('Integritätsstatus des E-Mail-Archivs: ' . $chainStatus)
				->setDateTime(new \DateTime());
			$this->notificationManager->notify($notification);
		}
	}
}
