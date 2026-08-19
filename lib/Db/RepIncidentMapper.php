<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RepIncident>
 */
class RepIncidentMapper extends QBMapper {

    public const TABLE = 'souvera_shield_incident';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, RepIncident::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findById(int $id): RepIncident {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function findByDedupeKey(string $key): ?RepIncident {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($key, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * @return RepIncident[]
     */
    public function findByStatus(string $domain, ?string $status = null, int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('domain_name', $qb->createNamedParameter($domain, IQueryBuilder::PARAM_STR)))
            ->orderBy('updated_at', 'DESC')
            ->setMaxResults($limit);
        if ($status !== null && $status !== '' && $status !== 'all') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR)));
        }
        return $this->findEntities($qb);
    }

    /**
     * @return RepIncident[]
     */
    public function findOpen(string $domain): array {
        return $this->findByStatus($domain, RepIncident::STATUS_OPEN, 500);
    }
}
