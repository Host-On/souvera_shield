<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

use OCA\SouveraShield\AppInfo\Application;
use OCA\SouveraShield\Db\DmarcDomain;
use OCA\SouveraShield\Db\DmarcDomainMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;

/**
 * Owns *the* single reputation-monitored domain of the workspace.
 *
 * Design decision:
 *   Souvera Shield always operates on exactly one domain per workspace –
 *   the very same domain that Proxmox Mail Gateway is configured for.
 *   Users must not be able to choose which domain is tested; therefore
 *   the domain is read from the app configuration, never from the UI.
 *
 * Resolution order:
 *   1. App-config key `provider_tools_domain`   – explicit override, set
 *      by the hoster via OCC (`souvera_shield:set-provider-tools-token
 *      --domain=example.com`).
 *   2. First entry of the PMG `pmg_allowed_domains` list.
 *
 * The DB row (`souvera_shield_dmarc_domain`) is used purely as a cache
 * for the DMARC lookup result, the provider.tools "verified" flag and
 * the timestamp of the last check.
 */
class ManagedDomainService {

    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly PMGClient $pmg,
        private readonly DmarcDomainMapper $domains,
    ) {
    }

    /**
     * Domain name as it should be tested / checked, or null if neither
     * the override nor the PMG config yielded anything usable.
     */
    public function getDomainName(): ?string {
        $override = trim($this->appConfig->getValueString(
            Application::APP_ID,
            'provider_tools_domain',
            '',
            lazy: true,
        ));
        if ($override !== '') {
            return strtolower($override);
        }
        return $this->pmg->getPrimaryDomain();
    }

    /**
     * Sender address the weekly mail-test uses.
     * Override via `provider_tools_sender`, default `postmaster@<domain>`.
     */
    public function getSenderAddress(?string $domain = null): ?string {
        $override = trim($this->appConfig->getValueString(
            Application::APP_ID,
            'provider_tools_sender',
            '',
            lazy: true,
        ));
        if ($override !== '') {
            return $override;
        }
        $domain ??= $this->getDomainName();
        return $domain !== null ? 'postmaster@' . $domain : null;
    }

    /**
     * Return the persisted row for the managed domain, creating it on
     * demand. Returns null when neither the override nor the PMG config
     * yields a domain (workspace not fully provisioned yet).
     */
    public function getOrCreate(): ?DmarcDomain {
        $domain = $this->getDomainName();
        if ($domain === null) {
            return null;
        }
        $entity = $this->domains->findByDomain($domain);
        if ($entity !== null) {
            // Keep sender address up-to-date in case the override changed.
            $expectedSender = $this->getSenderAddress($domain);
            if ($expectedSender !== null && $entity->getSenderAddress() !== $expectedSender) {
                $entity->setSenderAddress($expectedSender);
                $entity = $this->domains->update($entity);
            }
            return $entity;
        }

        $entity = new DmarcDomain();
        $entity->setDomain($domain);
        $entity->setSenderAddress($this->getSenderAddress($domain) ?? ('postmaster@' . $domain));
        $entity->setActive(1);
        $entity->setCreatedAt(time());
        $entity->setCreatedBy('system');
        return $this->domains->insert($entity);
    }
}
