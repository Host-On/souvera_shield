<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in table `souvera_shield_suspicious_event`.
 *
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method ?int    getTraceId()
 * @method void    setTraceId(?int $traceId)
 * @method ?int    getConfidence()
 * @method void    setConfidence(?int $confidence)
 * @method string  getSeverity()
 * @method void    setSeverity(string $severity)
 * @method ?string getDecision()
 * @method void    setDecision(?string $decision)
 * @method ?string getIp()
 * @method void    setIp(?string $ip)
 * @method ?string getGeoCountry()
 * @method void    setGeoCountry(?string $geoCountry)
 * @method ?string getGeoCity()
 * @method void    setGeoCity(?string $geoCity)
 * @method ?string getIspName()
 * @method void    setIspName(?string $ispName)
 * @method ?string getRiskFlags()
 * @method void    setRiskFlags(?string $riskFlags)
 * @method int     getResolved()
 * @method void    setResolved(int $resolved)
 * @method ?string getResolvedBy()
 * @method void    setResolvedBy(?string $resolvedBy)
 * @method ?int    getResolvedAt()
 * @method void    setResolvedAt(?int $resolvedAt)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 */
class SuspiciousEvent extends Entity {

    protected $userId = '';
    protected $traceId = null;
    protected $confidence = null;
    protected $severity = 'low';
    protected $decision = null;
    protected $ip = null;
    protected $geoCountry = null;
    protected $geoCity = null;
    protected $ispName = null;
    protected $riskFlags = null;
    protected $resolved = 0;
    protected $resolvedBy = null;
    protected $resolvedAt = null;
    protected $createdAt = 0;

    public function __construct() {
        $this->addType('traceId', 'integer');
        $this->addType('confidence', 'integer');
        $this->addType('resolved', 'integer');
        $this->addType('resolvedAt', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
