<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in table `souvera_shield_dmarc_domain`.
 *
 * Represents the single mail domain that Souvera Shield monitors through
 * the provider.tools DMARC Analyzer. The analyzer needs the
 * `provider_domain_id` (assigned on `POST /dmarc/domains`) to fetch
 * statistics and aggregate reports; the extra columns cache the setup
 * material returned during registration so the UI can display the DNS
 * instructions without another API round-trip.
 *
 * @method string  getDomain()
 * @method void    setDomain(string $domain)
 * @method string  getSenderAddress()
 * @method void    setSenderAddress(string $senderAddress)
 * @method int     getActive()
 * @method void    setActive(int $active)
 * @method ?int    getLastDmarcCheckAt()
 * @method void    setLastDmarcCheckAt(?int $ts)
 * @method ?string getLastDmarcData()
 * @method void    setLastDmarcData(?string $json)
 * @method int     getProviderVerified()
 * @method void    setProviderVerified(int $flag)
 * @method ?string getProviderDomainId()
 * @method void    setProviderDomainId(?string $id)
 * @method ?string getVerificationTxt()
 * @method void    setVerificationTxt(?string $txt)
 * @method ?string getReportEmail()
 * @method void    setReportEmail(?string $email)
 * @method ?string getDmarcRecord()
 * @method void    setDmarcRecord(?string $record)
 * @method ?int    getRegisteredAt()
 * @method void    setRegisteredAt(?int $ts)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method string  getCreatedBy()
 * @method void    setCreatedBy(string $userId)
 */
class DmarcDomain extends Entity {

    protected $domain = '';
    protected $senderAddress = '';
    protected $active = 1;
    protected $lastDmarcCheckAt = null;
    protected $lastDmarcData = null;
    protected $providerVerified = 0;
    protected $providerDomainId = null;
    protected $verificationTxt = null;
    protected $reportEmail = null;
    protected $dmarcRecord = null;
    protected $registeredAt = null;
    protected $createdAt = 0;
    protected $createdBy = '';

    public function __construct() {
        $this->addType('active', 'integer');
        $this->addType('lastDmarcCheckAt', 'integer');
        $this->addType('providerVerified', 'integer');
        $this->addType('registeredAt', 'integer');
        $this->addType('createdAt', 'integer');
    }
}
