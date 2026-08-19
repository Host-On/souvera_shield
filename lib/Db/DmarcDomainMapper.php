<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DmarcDomain>
 */
class DmarcDomainMapper extends QBMapper {

    public const TABLE = 'souvera_shield_dmarc_domain';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, DmarcDomain::class);
    }

    /**
     * @return DmarcDomain[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('domain', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findById(int $id): DmarcDomain {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function findByDomain(string $domain): ?DmarcDomain {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from(self::TABLE)
                ->where($qb->expr()->eq('domain', $qb->createNamedParameter($domain, IQueryBuilder::PARAM_STR)));
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
