<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in table `souvera_shield_login_baseline`.
 *
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method int     getTotalLogins()
 * @method void    setTotalLogins(int $totalLogins)
 * @method int     getActiveDays()
 * @method void    setActiveDays(int $activeDays)
 * @method ?int    getFirstSeen()
 * @method void    setFirstSeen(?int $firstSeen)
 * @method ?int    getLastSeen()
 * @method void    setLastSeen(?int $lastSeen)
 * @method ?string getTrustedSubnets()
 * @method void    setTrustedSubnets(?string $trustedSubnets)
 * @method ?string getTrustedCountries()
 * @method void    setTrustedCountries(?string $trustedCountries)
 * @method ?string getTrustedIsps()
 * @method void    setTrustedIsps(?string $trustedIsps)
 * @method ?string getTrustedDevices()
 * @method void    setTrustedDevices(?string $trustedDevices)
 * @method ?string getTypicalHours()
 * @method void    setTypicalHours(?string $typicalHours)
 * @method ?float  getAvgLoginsPerDay()
 * @method void    setAvgLoginsPerDay(?float $avgLoginsPerDay)
 * @method ?int    getGracePeriodUntil()
 * @method void    setGracePeriodUntil(?int $gracePeriodUntil)
 */
class LoginBaseline extends Entity {

    protected $userId = '';
    protected $totalLogins = 0;
    protected $activeDays = 0;
    protected $firstSeen = null;
    protected $lastSeen = null;
    protected $trustedSubnets = null;
    protected $trustedCountries = null;
    protected $trustedIsps = null;
    protected $trustedDevices = null;
    protected $typicalHours = null;
    protected $avgLoginsPerDay = null;
    protected $gracePeriodUntil = null;

    public function __construct() {
        $this->addType('totalLogins', 'integer');
        $this->addType('activeDays', 'integer');
        $this->addType('firstSeen', 'integer');
        $this->addType('lastSeen', 'integer');
        $this->addType('gracePeriodUntil', 'integer');
    }
}
