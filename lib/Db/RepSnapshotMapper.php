<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RepSnapshot>
 */
class RepSnapshotMapper extends QBMapper {

    public const TABLE = 'souvera_shield_score_snap';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, RepSnapshot::class);
    }

    public function findLatest(string $domain): ?RepSnapshot {
        $rows = $this->findRecent($domain, 1);
        return $rows[0] ?? null;
    }

    /**
     * @return RepSnapshot[] newest first
     */
    public function findRecent(string $domain, int $limit = 30): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('domain_name', $qb->createNamedParameter($domain, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
