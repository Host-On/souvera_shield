<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in table `souvera_shield_score_snap`.
 *
 * Daily snapshot of the composite reputation score (0–100) plus the
 * JSON-encoded component breakdown – powers the score history.
 *
 * @method string  getDomainName()
 * @method void    setDomainName(string $v)
 * @method ?int    getScore()
 * @method void    setScore(?int $v)
 * @method ?string getComponents()
 * @method void    setComponents(?string $json)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $ts)
 */
class RepSnapshot extends Entity {

    protected $domainName = '';
    protected $score = null;
    protected $components = null;
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('score', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
