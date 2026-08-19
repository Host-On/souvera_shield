<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in table `souvera_shield_login_trace`.
 *
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method ?string getIp()
 * @method void    setIp(?string $ip)
 * @method ?string getIpSubnet()
 * @method void    setIpSubnet(?string $ipSubnet)
 * @method ?int    getSuccess()
 * @method void    setSuccess(?int $success)
 * @method ?string getUserAgent()
 * @method void    setUserAgent(?string $userAgent)
 * @method ?string getDeviceHash()
 * @method void    setDeviceHash(?string $deviceHash)
 * @method ?string getGeoCountry()
 * @method void    setGeoCountry(?string $geoCountry)
 * @method ?string getGeoCity()
 * @method void    setGeoCity(?string $geoCity)
 * @method ?string getIspName()
 * @method void    setIspName(?string $ispName)
 * @method ?string getAsn()
 * @method void    setAsn(?string $asn)
 * @method ?string getRiskFlags()
 * @method void    setRiskFlags(?string $riskFlags)
 * @method ?int    getRiskScore()
 * @method void    setRiskScore(?int $riskScore)
 * @method ?string getRuleResults()
 * @method void    setRuleResults(?string $ruleResults)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class LoginTrace extends Entity {

    protected $userId = '';
    protected $ip = null;
    protected $ipSubnet = null;
    protected $success = null;
    protected $userAgent = null;
    protected $deviceHash = null;
    protected $geoCountry = null;
    protected $geoCity = null;
    protected $ispName = null;
    protected $asn = null;
    protected $riskFlags = null;
    protected $riskScore = null;
    protected $ruleResults = null;
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('success', 'integer');
        $this->addType('riskScore', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
