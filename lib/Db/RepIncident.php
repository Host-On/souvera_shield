<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in table `souvera_shield_incident`.
 *
 * A reputation incident is an automatically detected (or re-detected)
 * deliverability problem: blacklist listing, DMARC pass-rate drop, volume
 * anomaly, abusive sending source, failed infrastructure check or a
 * failed mail-test. Incidents are deduplicated via `dedupe_key`; repeated
 * detections update the existing row and append to the `measures` log so
 * the full history stays visible.
 *
 * `measures` is a JSON list of `{ts, actor, action, note}` entries –
 * the "ausgeführte Maßnahmen" trail required by the product spec.
 *
 * @method string  getDedupeKey()
 * @method void    setDedupeKey(string $v)
 * @method string  getSeverity()
 * @method void    setSeverity(string $v)
 * @method string  getCategory()
 * @method void    setCategory(string $v)
 * @method string  getTitle()
 * @method void    setTitle(string $v)
 * @method ?string getDescription()
 * @method void    setDescription(?string $v)
 * @method ?string getRecommendation()
 * @method void    setRecommendation(?string $v)
 * @method string  getDomainName()
 * @method void    setDomainName(string $v)
 * @method ?string getEvidence()
 * @method void    setEvidence(?string $json)
 * @method ?string getMeasures()
 * @method void    setMeasures(?string $json)
 * @method string  getStatus()
 * @method void    setStatus(string $v)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $ts)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $ts)
 * @method ?int    getResolvedAt()
 * @method void    setResolvedAt(?int $ts)
 * @method ?string getResolvedBy()
 * @method void    setResolvedBy(?string $uid)
 */
class RepIncident extends Entity {

    public const STATUS_OPEN     = 'open';
    public const STATUS_RESOLVED = 'resolved';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const CATEGORY_BLACKLIST = 'blacklist';
    public const CATEGORY_AUTH      = 'auth';
    public const CATEGORY_ANOMALY   = 'anomaly';
    public const CATEGORY_ABUSE     = 'abuse';
    public const CATEGORY_INFRA     = 'infra';
    public const CATEGORY_MAILTEST  = 'mail_test';

    protected $dedupeKey = '';
    protected $severity = self::SEVERITY_WARNING;
    protected $category = self::CATEGORY_INFRA;
    protected $title = '';
    protected $description = null;
    protected $recommendation = null;
    protected $domainName = '';
    protected $evidence = null;
    protected $measures = null;
    protected $status = self::STATUS_OPEN;
    protected $createdAt = 0;
    protected $updatedAt = 0;
    protected $resolvedAt = null;
    protected $resolvedBy = null;

    public function __construct() {
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('resolvedAt', 'integer');
    }

    /** @return array<int,array<string,mixed>> */
    public function measuresList(): array {
        $decoded = json_decode((string)($this->getMeasures() ?? '[]'), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function appendMeasure(string $actor, string $action, string $note = ''): void {
        $list = $this->measuresList();
        $list[] = ['ts' => time(), 'actor' => $actor, 'action' => $action, 'note' => $note];
        $this->setMeasures(json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
