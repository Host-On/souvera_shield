<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Controller;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Service\IdentityDiscoveryService;
use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\Service\PMGException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Internal API for cross-app integration (souvera_mail).
 *
 * Weg 2: Shield discovers the user's e-mail addresses (primary + shared)
 * via OIDC + JMAP Identity/get, then queries PMG for each address.
 *
 * This controller does NOT extend ApiController — the GroupRestrictionMiddleware
 * only gates ApiController and PageController instances, so these endpoints
 * are reachable whenever the app is installed.
 */
class InternalApiController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly PMGClient $pmg,
		private readonly IdentityDiscoveryService $identityDiscovery,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List spam for ALL e-mail addresses the user has access to (primary + shared).
	 * Returns deduplicated, merged data with _pmail tag per item.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function spamList(): JSONResponse
	{
		try {
			$emails = $this->identityDiscovery->discover();
			if (empty($emails)) {
				return new JSONResponse(['data' => [], 'total' => 0]);
			}

			$allItems = [];
			$seen = [];

			foreach ($emails as $email) {
				try {
					$res = $this->pmg->getSpamQuarantine($email, true);
					foreach ($res['data'] ?? [] as $item) {
						$id = (string) ($item['id'] ?? '');
						// Deduplicate: same PMG id can appear for different recipients
						$dedupeKey = $id;
						if (isset($seen[$dedupeKey])) continue;
						$seen[$dedupeKey] = true;
						$item['_pmail'] = $email;
						$allItems[] = $item;
					}
				} catch (PMGException $e) {
					$this->logger->warning('Shield: PMG spam fetch failed for ' . $email . ': ' . $e->getMessage());
				}
			}

			// Sort by time descending
			\usort($allItems, static fn(array $a, array $b): int =>
				(int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0));

			return new JSONResponse(['data' => $allItems, 'total' => \count($allItems)]);
		} catch (\Throwable $e) {
			$this->logger->error('Shield: spamList failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function spamView(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(['error' => 'Not signed in'], Http::STATUS_UNAUTHORIZED);
			}

			$id = $this->requiredParam('id');
			$email = $this->request->getParam('email');

			// If email specified, try that one first
			if (\is_string($email) && $email !== '') {
				try {
					$data = $this->pmg->viewMessage($email, $id);
					return new JSONResponse($data['data'] ?? $data);
				} catch (PMGException $e) {
					// Fall through to try all identities
				}
			}

			// Try all identities the user has access to
			$emails = $this->identityDiscovery->discover();
			foreach ($emails as $email) {
				try {
					$data = $this->pmg->viewMessage($email, $id);
					return new JSONResponse($data['data'] ?? $data);
				} catch (PMGException $e) {
					continue;
				}
			}

			return new JSONResponse(['error' => 'Message not found'], Http::STATUS_NOT_FOUND);
		} catch (PMGException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus() ?: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function spamRelease(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(['error' => 'Not signed in'], Http::STATUS_UNAUTHORIZED);
			}

			$ids = $this->extractIds();
			$email = $this->request->getParam('email');
			$ok = 0;

			// If explicit email given, use it
			if (\is_string($email) && $email !== '') {
				foreach ($ids as $id) {
					try {
						$this->pmg->releaseSpamMessage($email, $id);
						$ok++;
					} catch (PMGException $e) {
						$this->logger->warning('Shield: release failed for ' . $id . ': ' . $e->getMessage());
					}
				}
				return new JSONResponse(['success' => $ok, 'total' => \count($ids)]);
			}

			// Try all identities
			$emails = $this->identityDiscovery->discover();
			foreach ($ids as $id) {
				$released = false;
				foreach ($emails as $tryEmail) {
					try {
						$this->pmg->releaseSpamMessage($tryEmail, $id);
						$ok++;
						$released = true;
						break;
					} catch (PMGException $e) {
						continue;
					}
				}
				if (!$released) {
					$this->logger->warning('Shield: release failed for ' . $id . ' — no matching identity');
				}
			}

			return new JSONResponse(['success' => $ok, 'total' => \count($ids)]);
		} catch (PMGException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus() ?: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function spamDelete(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(['error' => 'Not signed in'], Http::STATUS_UNAUTHORIZED);
			}

			$ids = $this->extractIds();
			$email = $this->request->getParam('email');
			$ok = 0;

			if (\is_string($email) && $email !== '') {
				foreach ($ids as $id) {
					try {
						$this->pmg->deleteMessage($email, $id);
						$ok++;
					} catch (PMGException $e) {
						$this->logger->warning('Shield: delete failed for ' . $id);
					}
				}
				return new JSONResponse(['success' => $ok, 'total' => \count($ids)]);
			}

			$emails = $this->identityDiscovery->discover();
			foreach ($ids as $id) {
				$deleted = false;
				foreach ($emails as $tryEmail) {
					try {
						$this->pmg->deleteMessage($tryEmail, $id);
						$ok++;
						$deleted = true;
						break;
					} catch (PMGException $e) {
						continue;
					}
				}
				if (!$deleted) {
					$this->logger->warning('Shield: delete failed for ' . $id);
				}
			}

			return new JSONResponse(['success' => $ok, 'total' => \count($ids)]);
		} catch (PMGException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus() ?: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function spamCount(): JSONResponse
	{
		try {
			$emails = $this->identityDiscovery->discover();
			$total = 0;
			foreach ($emails as $email) {
				try {
					$res = $this->pmg->getSpamQuarantine($email, true);
					$total += \count($res['data'] ?? []);
				} catch (PMGException $e) {
					// Skip
				}
			}
			return new JSONResponse(['count' => $total]);
		} catch (\Throwable $e) {
			return new JSONResponse(['count' => 0]);
		}
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	private function requiredParam(string $name): string {
		$val = (string)($this->request->getParam($name) ?? '');
		if (trim($val) === '') {
			throw new PMGException('Missing "' . $name . '" parameter', Http::STATUS_BAD_REQUEST);
		}
		return trim($val);
	}

	/**
	 * @return string[]
	 */
	private function extractIds(): array {
		$ids = $this->request->getParam('ids');
		if ($ids === null) {
			return [$this->requiredParam('id')];
		}
		if (is_array($ids)) {
			$clean = array_filter(array_map('trim', array_map('strval', $ids)), static fn($v) => $v !== '');
		} else {
			$clean = array_filter(array_map('trim', explode(',', (string)$ids)), static fn($v) => $v !== '');
		}
		if (empty($clean)) {
			throw new PMGException('Missing "id" or "ids" parameter', Http::STATUS_BAD_REQUEST);
		}
		return array_values($clean);
	}
}
