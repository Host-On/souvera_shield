<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<LoginTrace>
 */
class LoginTraceMapper extends QBMapper {

    public const TABLE = 'souvera_shield_login_trace';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, LoginTrace::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findById(int $id): LoginTrace {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * @return LoginTrace[]
     */
    public function findAllByUser(string $userId, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * @return LoginTrace[]
     */
    public function findByIp(string $ip, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('ip', $qb->createNamedParameter($ip, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    public function insert(\OCP\AppFramework\Db\Entity $trace): LoginTrace {
        return parent::insert($trace);
    }

    public function countSince(string $userId, int $timestamp): int {
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

    /**
     * @return LoginTrace[]
     */
    public function findRecentByUser(string $userId, int $limit = 100): array {
        return $this->findAllByUser($userId, $limit);
    }

    /**
     * @return LoginTrace[]
     */
    public function findUnscored(int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->isNull('risk_score'))
            ->orderBy('created_at', 'ASC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    public function deleteOlderThan(int $timestamp): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * @return LoginTrace[]
     */
    public function findTracesSince(string $userId, int $timestamp): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    public function countDistinctUsers(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('DISTINCT user_id', 'cnt'))
            ->from(self::TABLE);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * @return string[]
     */
    public function distinctUserIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from(self::TABLE)
            ->orderBy('user_id', 'ASC');
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = $row['user_id'];
        }
        $result->closeCursor();
        return $ids;
    }

    /**
     * Get the first trace timestamp for a user.
     */
    public function findFirstSeen(string $userId): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('created_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'ASC')
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['created_at'] : null;
    }

    /**
     * Get the last trace timestamp for a user.
     */
    public function findLastSeen(string $userId): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('created_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (int)$row['created_at'] : null;
    }

    /**
     * Count distinct days with logins for a user.
     */
    public function countActiveDays(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('DISTINCT ' . $qb->createFunction("DATE(FROM_UNIXTIME(created_at))"), 'cnt'))
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }
}
