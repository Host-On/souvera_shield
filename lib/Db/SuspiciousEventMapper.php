<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SuspiciousEvent>
 */
class SuspiciousEventMapper extends QBMapper {

    public const TABLE = 'souvera_shield_suspicious_event';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, SuspiciousEvent::class);
    }

    /**
     * @return SuspiciousEvent[]
     */
    public function findAll(
        ?int $resolved = null,
        ?string $severity = null,
        ?string $userId = null,
        ?int $createdAfter = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE);

        if ($resolved !== null) {
            $qb->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter($resolved, IQueryBuilder::PARAM_INT)));
        }
        if ($severity !== null) {
            $qb->andWhere($qb->expr()->eq('severity', $qb->createNamedParameter($severity, IQueryBuilder::PARAM_STR)));
        }
        if ($userId !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        }
        if ($createdAfter !== null) {
            $qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($createdAfter, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findById(int $id): SuspiciousEvent {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function insert(\OCP\AppFramework\Db\Entity $event): SuspiciousEvent {
        return parent::insert($event);
    }

    public function update(\OCP\AppFramework\Db\Entity $event): SuspiciousEvent {
        return parent::update($event);
    }

    public function countAll(
        ?int $resolved = null,
        ?string $severity = null,
        ?string $userId = null,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(self::TABLE);

        if ($resolved !== null) {
            $qb->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter($resolved, IQueryBuilder::PARAM_INT)));
        }
        if ($severity !== null) {
            $qb->andWhere($qb->expr()->eq('severity', $qb->createNamedParameter($severity, IQueryBuilder::PARAM_STR)));
        }
        if ($userId !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        }

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    public function countByUserSince(string $userId, int $timestamp): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    public function deleteOlderThan(int $timestamp, int $resolved = 1): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('resolved', $qb->createNamedParameter($resolved, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }
}
