<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in table `souvera_shield_mail_test`.
 *
 * Represents a single mail-test session against provider.tools.
 *
 * Lifecycle of `status`:
 *   `pending`  → mail-test session created, e-mail not yet dispatched
 *   `sent`     → e-mail dispatched via IMailer, waiting for provider.tools
 *                to analyse the incoming mail
 *   `completed`→ provider.tools returned a final result (spf / dkim / dmarc)
 *   `error`    → something failed, see `error_message`
 *
 * @method int     getDomainId()
 * @method void    setDomainId(int $id)
 * @method string  getTestId()
 * @method void    setTestId(string $testId)
 * @method string  getTestEmail()
 * @method void    setTestEmail(string $email)
 * @method string  getStatus()
 * @method void    setStatus(string $status)
 * @method ?float  getScore()
 * @method void    setScore(?float $score)
 * @method ?string getSpfResult()
 * @method void    setSpfResult(?string $r)
 * @method ?string getDkimResult()
 * @method void    setDkimResult(?string $r)
 * @method ?string getDmarcResult()
 * @method void    setDmarcResult(?string $r)
 * @method ?string getRawResult()
 * @method void    setRawResult(?string $json)
 * @method ?string getErrorMessage()
 * @method void    setErrorMessage(?string $msg)
 * @method string  getTriggerType()
 * @method void    setTriggerType(string $type)
 * @method ?string getTriggeredBy()
 * @method void    setTriggeredBy(?string $userId)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $ts)
 * @method ?int    getSentAt()
 * @method void    setSentAt(?int $ts)
 * @method ?int    getCompletedAt()
 * @method void    setCompletedAt(?int $ts)
 */
class MailTest extends Entity {

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ERROR     = 'error';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_WEEKLY = 'weekly';

    protected $domainId = 0;
    protected $testId = '';
    protected $testEmail = '';
    protected $status = self::STATUS_PENDING;
    protected $score = null;
    protected $spfResult = null;
    protected $dkimResult = null;
    protected $dmarcResult = null;
    protected $rawResult = null;
    protected $errorMessage = null;
    protected $triggerType = self::TRIGGER_MANUAL;
    protected $triggeredBy = null;
    protected $createdAt = 0;
    protected $sentAt = null;
    protected $completedAt = null;

    public function __construct() {
        $this->addType('domainId', 'integer');
        $this->addType('score', 'float');
        $this->addType('createdAt', 'integer');
        $this->addType('sentAt', 'integer');
        $this->addType('completedAt', 'integer');
    }
}
