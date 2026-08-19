<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Dashboard;

use OCA\SouveraShield\AppInfo\Application;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Dashboard-Widget: Archiv-Compliance-Status.
 *
 * Zeigt den aktuellen Integritätsstatus des E-Mail-Archivs an.
 * Nur sichtbar wenn das Archiv aktiviert ist.
 *
 * @see ARCHIVE_PLAN §2.4a
 */
class ArchiveComplianceWidget implements IAPIWidgetV2, IIconWidget
{
	private readonly IL10N $l;

	public function __construct(
		IFactory $l10nFactory,
		private readonly IURLGenerator $url,
	) {
		$this->l = $l10nFactory->get(Application::APP_ID);
	}

	public function getId(): string
	{
		return 'souvera_archive_compliance';
	}

	public function getTitle(): string
	{
		return $this->l->t('Archiv-Compliance');
	}

	public function getOrder(): int
	{
		return 30;
	}

	public function getIconClass(): string
	{
		return '';
	}

	public function getIconUrl(): string
	{
		return $this->url->imagePath(Application::APP_ID, 'archive-check.svg');
	}

	public function getUrl(): ?string
	{
		return null;
	}

	public function load(): void
	{
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		return $this->getItems($userId, $since, $limit);
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		$enabled = \OCP\Server::get(\OCP\IConfig::class)
			->getAppValue('souvera_central', 'archive.enabled', '0') === '1';

		if (!$enabled) {
			return new WidgetItems([], $this->l->t('Archiv nicht aktiviert'));
		}

		try {
			$client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
			$cmApiUrl = \OCP\Server::get(\OCP\IConfig::class)
				->getSystemValue('souvera_central.cm_api_url', '');
			$cmApiKey = \OCP\Server::get(\OCP\IConfig::class)
				->getSystemValue('souvera_central.cm_api_key', '');

			if (!$cmApiUrl || !$cmApiKey) {
				return new WidgetItems([], $this->l->t('CM-API nicht konfiguriert'));
			}

			$tenantId = \OCP\Server::get(\OCP\IConfig::class)
				->getSystemValue('souvera_central.tenant_id', 'default');
			$response = $client->get("{$cmApiUrl}/clouds/{$tenantId}/archive/status", [
				'headers' => [
					'Authorization' => 'Bearer ' . $cmApiKey,
					'Accept' => 'application/json',
				],
				'timeout' => 10,
				'verify' => false,
			]);
			$status = json_decode($response->getBody(), true) ?? [];

			$items = [];
			$items[] = new WidgetItem(
				$this->l->t('Nachrichten: ' . ($status['message_count'] ?? '?')),
				'',
				'',
				''
			);
			$chain = $status['chain_status'] ?? '—';
			$items[] = new WidgetItem(
				$this->l->t('Chain: ' . $chain),
				'',
				'',
				''
			);
			if (!empty($status['last_sealed_at'])) {
				$items[] = new WidgetItem(
					$this->l->t('Letztes Sealing: ' . $status['last_sealed_at']),
					'',
					'',
					''
				);
			}
			return new WidgetItems($items, '');
		} catch (\Throwable $e) {
			return new WidgetItems([], $this->l->t('Status nicht verfügbar'));
		}
	}
}
