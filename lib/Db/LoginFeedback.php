<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in table `souvera_shield_login_feedback`.
 *
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method ?string getIp()
 * @method void    setIp(?string $ip)
 * @method ?string getIpSubnet()
 * @method void    setIpSubnet(?string $ipSubnet)
 * @method string  getFeedback()
 * @method void    setFeedback(string $feedback)
 * @method ?string getCreatedBy()
 * @method void    setCreatedBy(?string $createdBy)
 * @method int     getIsAdmin()
 * @method void    setIsAdmin(int $isAdmin)
 * @method ?string getNotes()
 * @method void    setNotes(?string $notes)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class LoginFeedback extends Entity {

    protected $userId = '';
    protected $ip = null;
    protected $ipSubnet = null;
    protected $feedback = '';
    protected $createdBy = null;
    protected $isAdmin = 0;
    protected $notes = null;
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('createdAt', 'integer');
        $this->addType('isAdmin', 'integer');
    }
}
