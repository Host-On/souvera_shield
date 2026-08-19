<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MailTest>
 */
class MailTestMapper extends QBMapper {

    public const TABLE = 'souvera_shield_mail_test';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, MailTest::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findById(int $id): MailTest {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * @return MailTest[]
     */
    public function findRecent(int $limit = 200, ?int $domainId = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        if ($domainId !== null) {
            $qb->where($qb->expr()->eq('domain_id', $qb->createNamedParameter($domainId, IQueryBuilder::PARAM_INT)));
        }
        return $this->findEntities($qb);
    }

    /**
     * Rows still waiting for a final result. Used by the polling job.
     *
     * @return MailTest[]
     */
    public function findPending(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'status',
                $qb->createNamedParameter(
                    [MailTest::STATUS_PENDING, MailTest::STATUS_SENT],
                    IQueryBuilder::PARAM_STR_ARRAY,
                ),
            ))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Return the newest completed test per domain – used on the overview tab.
     *
     * @return array<int,MailTest>  keyed by domain_id
     */
    public function findLatestPerDomain(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(MailTest::STATUS_COMPLETED, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC');
        $rows = $this->findEntities($qb);
        $byDomain = [];
        foreach ($rows as $row) {
            $did = $row->getDomainId();
            if (!isset($byDomain[$did])) {
                $byDomain[$did] = $row;
            }
        }
        return $byDomain;
    }
}
