<?php
/**
 * Souvera Shield – SPA mount point (Vue 3).
 *
 * We deliberately expose the initial state as `data-*` attributes on the
 * mount point (no inline <script>) so we stay clear of Nextcloud's
 * content-security-policy nonce plumbing – which throws a fatal on some
 * NC versions when the manager's API surface changes.
 *
 * See SOUVERA_DESIGN_SYSTEM.md §3.
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="souvera-shield-app"
     data-testid="souvera-shield-app"
     data-app-version="<?php p($_['appVersion']); ?>"
     data-initial-page="<?php p($_['initialPage']); ?>"
     data-is-admin="<?php p($_['isAdmin'] ? '1' : '0'); ?>"
     data-is-souvera-admin="<?php p($_['isSouveraAdmin'] ? '1' : '0'); ?>"
     data-allow-file-quarantine="<?php p($_['allowFileQuarantine'] ? '1' : '0'); ?>"
     data-allow-virus-quarantine="<?php p($_['allowVirusQuarantine'] ? '1' : '0'); ?>">
    <div class="loading-container" data-testid="souvera-shield-loading">
        <div class="icon-loading"></div>
        <p><?php p($l->t('Loading…')); ?></p>
    </div>
</div>
