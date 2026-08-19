<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Controller;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\AuditMapper;
use OCA\SouveraShield\Service\IdentityDiscoveryService;
use OCA\SouveraShield\Service\PMGClient;
use OCA\SouveraShield\Service\PMGException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST controller for Souvera Shield.
 *
 * Every endpoint that mutates state still uses Nextcloud's CSRF protection;
 * only read endpoints carry #[NoCSRFRequired] so they can be polled from
 * other tabs without re-fetching the token.
 *
 * Mutating actions are written to the audit log so administrators can
 * follow what happened, by whom and when.
 */
class ApiController extends Controller {

    public function __construct(
        IRequest $request,
        private readonly PMGClient $pmg,
        private readonly IdentityDiscoveryService $identityDiscovery,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly AuditMapper $audit,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // ---------------------------------------------------------------
    // Spam quarantine
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function quarantine(): JSONResponse {
        return $this->multiEmailListAction(fn(string $pmail) =>
            $this->pmg->getSpamQuarantine($pmail, $this->wantsAll()));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function viewQuarantine(): JSONResponse {
        $id = $this->requiredParam('id');
        $email = $this->request->getParam('email');

        // Explicit email given — use it directly
        if (\is_string($email) && $email !== '') {
            return $this->withPmail(fn() => new JSONResponse($this->pmg->viewMessage($email, $id)));
        }

        // Try all identities
        $emails = $this->identityDiscovery->discover();
        foreach ($emails as $email) {
            try {
                return new JSONResponse($this->pmg->viewMessage($email, $id));
            } catch (PMGException $e) {
                continue;
            }
        }
        return new JSONResponse(['error' => 'Message not found in any mailbox'], Http::STATUS_NOT_FOUND);
    }

    #[NoAdminRequired]
    public function releaseQuarantine(): JSONResponse {
        $ids = $this->extractIds();
        $email = $this->request->getParam('email');

        if (\is_string($email) && $email !== '') {
            return $this->bulkActionOnEmail('quarantine', 'release', $ids, $email,
                fn(string $pmail, string $id) => $this->pmg->releaseSpamMessage($pmail, $id));
        }

        return $this->multiEmailBulkAction('quarantine', 'release', $ids,
            fn(string $pmail, string $id) => $this->pmg->releaseSpamMessage($pmail, $id));
    }

    #[NoAdminRequired]
    public function deleteQuarantine(): JSONResponse {
        $ids = $this->extractIds();
        $email = $this->request->getParam('email');

        if (\is_string($email) && $email !== '') {
            return $this->bulkActionOnEmail('quarantine', 'delete', $ids, $email,
                fn(string $pmail, string $id) => $this->pmg->deleteMessage($pmail, $id));
        }

        return $this->multiEmailBulkAction('quarantine', 'delete', $ids,
            fn(string $pmail, string $id) => $this->pmg->deleteMessage($pmail, $id));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportQuarantine(): DataDownloadResponse {
        try {
            $emails = $this->identityDiscovery->discover();
            $allRows = [];
            foreach ($emails as $email) {
                try {
                    $res = $this->pmg->getSpamQuarantine($email, true);
                    foreach ($res['data'] ?? [] as $row) {
                        $row['_pmail'] = $email;
                        $allRows[] = $row;
                    }
                } catch (PMGException $e) {
                    continue;
                }
            }
            $csv = $this->csvFromRows($allRows, ['time', 'from', 'subject', 'spamlevel', '_pmail', 'id']);
            return new DataDownloadResponse(
                $csv,
                'shield-quarantine-' . \date('Ymd-His') . '.csv',
                'text/csv; charset=utf-8'
            );
        } catch (\Throwable $e) {
            return new DataDownloadResponse('error,' . $e->getMessage(), 'quarantine-error.csv', 'text/csv');
        }
    }

    // ---------------------------------------------------------------
    // File quarantine
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function fileQuarantine(): JSONResponse {
        $this->assertFeature('allow_file_quarantine');
        return $this->listAction(fn(string $pmail) => $this->pmg->getAttachmentQuarantine($pmail, $this->wantsAll()));
    }

    #[NoAdminRequired]
    public function releaseFileQuarantine(): JSONResponse {
        $this->assertFeature('allow_file_quarantine');
        return $this->bulkAction('file_quarantine', 'release', fn(string $pmail, string $id) => $this->pmg->releaseMessage($pmail, $id));
    }

    #[NoAdminRequired]
    public function deleteFileQuarantine(): JSONResponse {
        $this->assertFeature('allow_file_quarantine');
        return $this->bulkAction('file_quarantine', 'delete', fn(string $pmail, string $id) => $this->pmg->deleteMessage($pmail, $id));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportFileQuarantine(): DataDownloadResponse {
        $this->assertFeature('allow_file_quarantine');
        return $this->exportQuarantineCsv('file_quarantine', fn(string $pmail) => $this->pmg->getAttachmentQuarantine($pmail, true));
    }

    // ---------------------------------------------------------------
    // Virus quarantine
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function virusQuarantine(): JSONResponse {
        $this->assertFeature('allow_virus_quarantine');
        return $this->listAction(fn(string $pmail) => $this->pmg->getVirusQuarantine($pmail, $this->wantsAll()));
    }

    #[NoAdminRequired]
    public function releaseVirusQuarantine(): JSONResponse {
        $this->assertFeature('allow_virus_quarantine');
        return $this->bulkAction('virus_quarantine', 'release', fn(string $pmail, string $id) => $this->pmg->releaseMessage($pmail, $id));
    }

    #[NoAdminRequired]
    public function deleteVirusQuarantine(): JSONResponse {
        $this->assertFeature('allow_virus_quarantine');
        return $this->bulkAction('virus_quarantine', 'delete', fn(string $pmail, string $id) => $this->pmg->deleteMessage($pmail, $id));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportVirusQuarantine(): DataDownloadResponse {
        $this->assertFeature('allow_virus_quarantine');
        return $this->exportQuarantineCsv('virus_quarantine', fn(string $pmail) => $this->pmg->getVirusQuarantine($pmail, true));
    }

    // ---------------------------------------------------------------
    // Whitelist / Blacklist
    // ---------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function whitelist(): JSONResponse {
        return $this->multiListAction(fn(string $pmail) => $this->pmg->getWhitelist($pmail));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function blacklist(): JSONResponse {
        return $this->multiListAction(fn(string $pmail) => $this->pmg->getBlacklist($pmail));
    }

    #[NoAdminRequired]
    public function addWhitelist(): JSONResponse {
        $entry = $this->requiredParam('entry');
        return $this->multiModifyAction(
            fn(string $pmail) => $this->pmg->addToWhitelist($pmail, $entry),
            'whitelist:add', $entry
        );
    }

    #[NoAdminRequired]
    public function addBlacklist(): JSONResponse {
        $entry = $this->requiredParam('entry');
        return $this->multiModifyAction(
            fn(string $pmail) => $this->pmg->addToBlacklist($pmail, $entry),
            'blacklist:add', $entry
        );
    }

    #[NoAdminRequired]
    public function removeWhitelist(): JSONResponse {
        $entry = $this->requiredParam('entry');
        return $this->multiModifyAction(
            fn(string $pmail) => $this->pmg->removeFromWhitelist($pmail, $entry),
            'whitelist:remove', $entry
        );
    }

    #[NoAdminRequired]
    public function removeBlacklist(): JSONResponse {
        $entry = $this->requiredParam('entry');
        return $this->multiModifyAction(
            fn(string $pmail) => $this->pmg->removeFromBlacklist($pmail, $entry),
            'blacklist:remove', $entry
        );
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportWhitelist(): DataDownloadResponse {
        return $this->multiExportListCsv('whitelist', fn(string $pmail) => $this->pmg->getWhitelist($pmail));
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportBlacklist(): DataDownloadResponse {
        return $this->multiExportListCsv('blacklist', fn(string $pmail) => $this->pmg->getBlacklist($pmail));
    }

    // ---------------------------------------------------------------
    // Settings (admin only)
    // ---------------------------------------------------------------

    public function getSettings(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse([
            'allow_file_quarantine'  => $this->boolSetting('allow_file_quarantine'),
            'allow_virus_quarantine' => $this->boolSetting('allow_virus_quarantine'),
        ]);
    }

    public function saveSettings(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], Http::STATUS_FORBIDDEN);
        }
        foreach (['allow_file_quarantine', 'allow_virus_quarantine'] as $key) {
            $val = $this->request->getParam($key);
            if ($val !== null) {
                $bool = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                $this->appConfig->setValueBool(Application::APP_ID, $key, $bool, lazy: true);
            }
        }
        return new JSONResponse(['success' => true]);
    }

    // ---------------------------------------------------------------
    // Audit log (admin only)
    // ---------------------------------------------------------------

    #[NoCSRFRequired]
    public function audit(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'Admin access required'], Http::STATUS_FORBIDDEN);
        }
        $limit = max(1, min(500, (int)$this->request->getParam('limit', 200)));
        $entries = array_map(static fn($e) => [
            'id' => $e->getId(),
            'user_id' => $e->getUserId(),
            'action' => $e->getAction(),
            'target' => $e->getTarget(),
            'created_at' => $e->getCreatedAt(),
        ], $this->audit->findRecent($limit));
        return new JSONResponse(['data' => $entries]);
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * Execute either a single-id action or a bulk action (comma separated `ids`),
     * counting successes for the response.
     *
     * @param callable(string,string):void $perId
     */
    private function bulkAction(string $listKey, string $verb, callable $perId): JSONResponse {
        return $this->withPmail(function (string $pmail) use ($listKey, $verb, $perId): JSONResponse {
            $ids = $this->extractIds();
            $ok = 0;
            $errors = [];
            foreach ($ids as $id) {
                try {
                    $perId($pmail, $id);
                    $this->logAudit($listKey . ':' . $verb, $id);
                    $ok++;
                } catch (PMGException $e) {
                    $errors[] = ['id' => $id, 'error' => $e->getMessage()];
                }
            }
            return new JSONResponse(['data' => 'ok', 'success' => $ok, 'errors' => $errors]);
        });
    }

    /**
     * List quarantine items across ALL emails the user has access to.
     *
     * @param callable(string):array{data:array} $fetch
     */
    private function multiEmailListAction(callable $fetch): JSONResponse {
        try {
            $emails = $this->identityDiscovery->discover();
            $allItems = [];
            $seen = [];
            $deadline = \time() + 10; // max 10s for PMG queries total

            foreach ($emails as $email) {
                if (\time() > $deadline) break;
                try {
                    $res = $fetch($email);
                    foreach ($res['data'] ?? [] as $item) {
                        $id = (string) ($item['id'] ?? '');
                        if (isset($seen[$id])) continue;
                        $seen[$id] = true;
                        $item['_pmail'] = $email;
                        $allItems[] = $item;
                    }
                } catch (PMGException $e) {
                    $this->logger->warning('ApiController: PMG fetch failed for ' . $email . ': ' . $e->getMessage());
                }
            }

            \usort($allItems, static fn(array $a, array $b): int =>
                (int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0));

            return new JSONResponse(['data' => $allItems]);
        } catch (PMGException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus() ?: Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('Souvera Shield: multiEmailListAction error', ['exception' => $e]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Bulk action across all identities — tries each email until one succeeds.
     */
    private function multiEmailBulkAction(string $listKey, string $verb, array $ids, callable $perId): JSONResponse {
        try {
            $emails = $this->identityDiscovery->discover();
            $ok = 0;
            $errors = [];

            foreach ($ids as $id) {
                $done = false;
                foreach ($emails as $email) {
                    try {
                        $perId($email, $id);
                        $this->logAudit($listKey . ':' . $verb, $id);
                        $ok++;
                        $done = true;
                        break;
                    } catch (PMGException $e) {
                        continue;
                    }
                }
                if (!$done) {
                    $errors[] = ['id' => $id, 'error' => 'No matching identity'];
                }
            }
            return new JSONResponse(['data' => 'ok', 'success' => $ok, 'errors' => $errors]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Bulk action on a single known email address.
     */
    private function bulkActionOnEmail(string $listKey, string $verb, array $ids, string $email, callable $perId): JSONResponse {
        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $perId($email, $id);
                $this->logAudit($listKey . ':' . $verb, $id);
                $ok++;
            } catch (PMGException $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        return new JSONResponse(['data' => 'ok', 'success' => $ok, 'errors' => $errors]);
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

    /**
     * @param callable(string):array{data:mixed} $fetch
     */
    private function exportQuarantineCsv(string $key, callable $fetch): DataDownloadResponse {
        try {
            $pmail = $this->requireUserEmail();
            $rows = $fetch($pmail)['data'];
        } catch (PMGException $e) {
            return new DataDownloadResponse('error,' . $e->getMessage(), $key . '-error.csv', 'text/csv');
        }
        $csv = $this->csvFromRows($rows, ['time', 'from', 'subject', 'spamlevel', 'id']);
        return new DataDownloadResponse(
            $csv,
            'shield-' . $key . '-' . date('Ymd-His') . '.csv',
            'text/csv; charset=utf-8'
        );
    }

    /**
     * @param callable(string):array{data:mixed} $fetch
     */
    private function exportListCsv(string $key, callable $fetch): DataDownloadResponse {
        try {
            $pmail = $this->requireUserEmail();
            $rows = $fetch($pmail)['data'];
        } catch (PMGException $e) {
            return new DataDownloadResponse('error,' . $e->getMessage(), $key . '-error.csv', 'text/csv');
        }
        $csv = $this->csvFromRows(array_map(static function ($row) {
            $entry = is_string($row) ? $row : ($row['address'] ?? $row['email'] ?? $row['value'] ?? '');
            return ['entry' => $entry];
        }, $rows), ['entry']);
        return new DataDownloadResponse(
            $csv,
            'shield-' . $key . '-' . date('Ymd-His') . '.csv',
            'text/csv; charset=utf-8'
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param string[]                       $columns
     */
    private function csvFromRows(array $rows, array $columns): string {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $val = $row[$col] ?? '';
                if ($col === 'time' && is_numeric($val)) {
                    $val = date('c', (int)$val);
                }
                $line[] = is_scalar($val) ? (string)$val : json_encode($val);
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $out = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $out;
    }

    private function logAudit(string $action, string $target): void {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) return;
        try {
            $this->audit->log($uid, $action, mb_substr($target, 0, 255));
        } catch (\Throwable $e) {
            $this->logger->warning('Audit log write failed', ['exception' => $e]);
        }
    }

    /**
     * Merge a per-mailbox list across ALL identities of the current user
     * (primary + aliases + shared mailboxes), deduplicated by entry.
     * Falls back to the NC e-mail address when identity discovery is
     * unavailable.
     *
     * @param callable(string):array{data: mixed} $fetch
     */
    private function multiListAction(callable $fetch): JSONResponse {
        try {
            $emails = $this->identityDiscovery->discover();
            if ($emails === []) {
                $emails = [$this->requireUserEmail()];
            }
            $all = [];
            $seen = [];
            $deadline = \time() + 15;
            foreach ($emails as $email) {
                if (\time() > $deadline) break;
                try {
                    $res = $fetch($email);
                    foreach ($res['data'] ?? [] as $item) {
                        $entry = $this->listEntryToString($item);
                        if ($entry === '' || isset($seen[$entry])) continue;
                        $seen[$entry] = true;
                        $all[] = $entry;
                    }
                } catch (PMGException $e) {
                    $this->logger->warning('Shield multiListAction: fetch failed for ' . $email . ': ' . $e->getMessage());
                }
            }
            \usort($all, 'strcasecmp');
            return new JSONResponse(['data' => $all]);
        } catch (PMGException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus() ?: Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('Shield multiListAction: unhandled error', ['exception' => $e]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Apply an add/remove operation to ALL identities of the current user.
     * Per-mailbox failures are collected; the call fails only when NONE
     * of the mailboxes accepted the change.
     *
     * @param callable(string):void $op
     */
    private function multiModifyAction(callable $op, string $auditAction, string $entry): JSONResponse {
        try {
            $emails = $this->identityDiscovery->discover();
            if ($emails === []) {
                $emails = [$this->requireUserEmail()];
            }
            $applied = 0;
            $errors = [];
            foreach ($emails as $email) {
                try {
                    $op($email);
                    $applied++;
                } catch (PMGException $e) {
                    $errors[$email] = $e->getMessage();
                }
            }
            if ($applied === 0) {
                $msg = $errors !== []
                    ? \implode('; ', \array_slice($errors, 0, 2))
                    : 'No mailbox accepted the change';
                throw new PMGException($msg, 502);
            }
            $this->logAudit($auditAction, $entry);
            return new JSONResponse(['data' => 'ok', 'applied' => $applied]);
        } catch (PMGException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus() ?: Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('Shield multiModifyAction: unhandled error', ['exception' => $e]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * CSV export across all identities (deduplicated entries).
     *
     * @param callable(string):array{data:mixed} $fetch
     */
    private function multiExportListCsv(string $key, callable $fetch): DataDownloadResponse {
        try {
            $emails = $this->identityDiscovery->discover();
            if ($emails === []) {
                $emails = [$this->requireUserEmail()];
            }
            $rows = [];
            $seen = [];
            foreach ($emails as $email) {
                try {
                    foreach (($fetch($email)['data'] ?? []) as $item) {
                        $entry = $this->listEntryToString($item);
                        if ($entry === '' || isset($seen[$entry])) continue;
                        $seen[$entry] = true;
                        $rows[] = ['entry' => $entry];
                    }
                } catch (PMGException $e) {
                    $this->logger->warning('Shield multiExportListCsv: fetch failed for ' . $email . ': ' . $e->getMessage());
                }
            }
        } catch (PMGException $e) {
            return new DataDownloadResponse('error,' . $e->getMessage(), $key . '-error.csv', 'text/csv');
        }
        $csv = $this->csvFromRows($rows, ['entry']);
        return new DataDownloadResponse(
            $csv,
            'shield-' . $key . '-' . date('Ymd-His') . '.csv',
            'text/csv; charset=utf-8'
        );
    }

    /**
     * Normalise a PMG list item (string or object) to a plain entry string.
     */
    private function listEntryToString(mixed $item): string {
        if (\is_string($item)) {
            return \trim($item);
        }
        if (\is_array($item)) {
            return \trim((string) ($item['address'] ?? $item['email'] ?? $item['value'] ?? $item['entry'] ?? ''));
        }
        return '';
    }

    /**
     * @param callable(string):array{data: mixed} $fetch
     */
    private function listAction(callable $fetch): JSONResponse {
        return $this->withPmail(function (string $pmail) use ($fetch): JSONResponse {
            $res = $fetch($pmail);
            return new JSONResponse(['data' => $res['data'] ?? []]);
        });
    }

    /**
     * @param callable(string):JSONResponse $cb
     */
    private function withPmail(callable $cb): JSONResponse {
        try {
            $pmail = $this->requireUserEmail();
            return $cb($pmail);
        } catch (PMGException $e) {
            $code = $e->getHttpStatus() ?: Http::STATUS_INTERNAL_SERVER_ERROR;
            return new JSONResponse(['error' => $e->getMessage()], $code);
        } catch (\Throwable $e) {
            $this->logger->error('Souvera Shield: unhandled controller error', ['exception' => $e]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function requireUserEmail(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new PMGException('Not signed in', Http::STATUS_UNAUTHORIZED);
        }
        $email = $user->getEMailAddress();
        if ($email === null || $email === '') {
            throw new PMGException('No e-mail address configured for this user.', Http::STATUS_BAD_REQUEST);
        }
        return $email;
    }

    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        return $user !== null && $this->groupManager->isAdmin($user->getUID());
    }

    private function wantsAll(): bool {
        return filter_var($this->request->getParam('all', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function requiredParam(string $name): string {
        $val = (string)($this->request->getParam($name) ?? '');
        if (trim($val) === '') {
            throw new PMGException('Missing "' . $name . '" parameter', Http::STATUS_BAD_REQUEST);
        }
        return trim($val);
    }

    private function assertFeature(string $key): void {
        if (!$this->boolSetting($key)) {
            throw new PMGException('This feature is disabled by the administrator.', Http::STATUS_FORBIDDEN);
        }
    }

    private function boolSetting(string $key): bool {
        return $this->appConfig->getValueBool(Application::APP_ID, $key, true, lazy: true);
    }
}
