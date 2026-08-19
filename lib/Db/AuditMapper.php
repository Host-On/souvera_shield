<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AuditEntry>
 */
class AuditMapper extends QBMapper {

    public const TABLE = 'souvera_shield_audit';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, AuditEntry::class);
    }

    public function log(string $userId, string $action, string $target): void {
        $entry = new AuditEntry();
        $entry->setUserId($userId);
        $entry->setAction($action);
        $entry->setTarget($target);
        $entry->setCreatedAt(time());
        $this->insert($entry);
    }

    /**
     * @return AuditEntry[]
     */
    public function findRecent(int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * @return AuditEntry[]
     */
    public function findForUser(string $userId, int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
