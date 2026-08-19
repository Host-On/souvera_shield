<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method string  getAction()
 * @method void    setAction(string $action)
 * @method string  getTarget()
 * @method void    setTarget(string $target)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class AuditEntry extends Entity {

    protected $userId = '';
    protected $action = '';
    protected $target = '';
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('createdAt', 'integer');
    }
}
