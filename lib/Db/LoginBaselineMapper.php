<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<LoginBaseline>
 */
class LoginBaselineMapper extends QBMapper {

    public const TABLE = 'souvera_shield_login_baseline';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, LoginBaseline::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(string $userId): LoginBaseline {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        return $this->findEntity($qb);
    }

    public function insertIfNew(\OCP\AppFramework\Db\Entity $baseline): LoginBaseline {
        try {
            return $this->find($baseline->getUserId());
        } catch (DoesNotExistException) {
            return $this->insert($baseline);
        }
    }

    /**
     * Custom update since this table uses user_id as PK, not the default `id`.
     */
    public function update(\OCP\AppFramework\Db\Entity $baseline): LoginBaseline {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('total_logins', $qb->createNamedParameter($baseline->getTotalLogins(), IQueryBuilder::PARAM_INT))
            ->set('active_days', $qb->createNamedParameter($baseline->getActiveDays(), IQueryBuilder::PARAM_INT))
            ->set('first_seen', $qb->createNamedParameter($baseline->getFirstSeen(), IQueryBuilder::PARAM_INT))
            ->set('last_seen', $qb->createNamedParameter($baseline->getLastSeen(), IQueryBuilder::PARAM_INT))
            ->set('trusted_subnets', $qb->createNamedParameter($baseline->getTrustedSubnets(), IQueryBuilder::PARAM_STR))
            ->set('trusted_countries', $qb->createNamedParameter($baseline->getTrustedCountries(), IQueryBuilder::PARAM_STR))
            ->set('trusted_isps', $qb->createNamedParameter($baseline->getTrustedIsps(), IQueryBuilder::PARAM_STR))
            ->set('trusted_devices', $qb->createNamedParameter($baseline->getTrustedDevices(), IQueryBuilder::PARAM_STR))
            ->set('typical_hours', $qb->createNamedParameter($baseline->getTypicalHours(), IQueryBuilder::PARAM_STR))
            ->set('avg_logins_per_day', $qb->createNamedParameter($baseline->getAvgLoginsPerDay(), IQueryBuilder::PARAM_STR))
            ->set('grace_period_until', $qb->createNamedParameter($baseline->getGracePeriodUntil(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($baseline->getUserId(), IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
        return $baseline;
    }

    /**
     * Custom insert since this table uses user_id as PK without auto-increment.
     */
    public function insert(\OCP\AppFramework\Db\Entity $baseline): LoginBaseline {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->setValue('user_id', $qb->createNamedParameter($baseline->getUserId(), IQueryBuilder::PARAM_STR))
            ->setValue('total_logins', $qb->createNamedParameter($baseline->getTotalLogins(), IQueryBuilder::PARAM_INT))
            ->setValue('active_days', $qb->createNamedParameter($baseline->getActiveDays(), IQueryBuilder::PARAM_INT))
            ->setValue('first_seen', $qb->createNamedParameter($baseline->getFirstSeen(), IQueryBuilder::PARAM_INT))
            ->setValue('last_seen', $qb->createNamedParameter($baseline->getLastSeen(), IQueryBuilder::PARAM_INT))
            ->setValue('trusted_subnets', $qb->createNamedParameter($baseline->getTrustedSubnets(), IQueryBuilder::PARAM_STR))
            ->setValue('trusted_countries', $qb->createNamedParameter($baseline->getTrustedCountries(), IQueryBuilder::PARAM_STR))
            ->setValue('trusted_isps', $qb->createNamedParameter($baseline->getTrustedIsps(), IQueryBuilder::PARAM_STR))
            ->setValue('trusted_devices', $qb->createNamedParameter($baseline->getTrustedDevices(), IQueryBuilder::PARAM_STR))
            ->setValue('typical_hours', $qb->createNamedParameter($baseline->getTypicalHours(), IQueryBuilder::PARAM_STR))
            ->setValue('avg_logins_per_day', $qb->createNamedParameter($baseline->getAvgLoginsPerDay(), IQueryBuilder::PARAM_STR))
            ->setValue('grace_period_until', $qb->createNamedParameter($baseline->getGracePeriodUntil(), IQueryBuilder::PARAM_INT));
        $qb->executeStatement();
        return $baseline;
    }
}
