<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<LoginFeedback>
 */
class LoginFeedbackMapper extends QBMapper {

    public const TABLE = 'souvera_shield_login_feedback';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, LoginFeedback::class);
    }

    /**
     * @return LoginFeedback[]
     */
    public function findByUserAndSubnet(string $userId, string $ipSubnet): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('ip_subnet', $qb->createNamedParameter($ipSubnet, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('is_admin', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function insert(\OCP\AppFramework\Db\Entity $feedback): LoginFeedback {
        return parent::insert($feedback);
    }

    /**
     * @return LoginFeedback[]
     */
    public function findAllByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }
}
