<?php
declare(strict_types=1);

/**
 * Souvera Shield – Routes
 *
 * All routes are also annotated via PHP attributes on the controller methods.
 * They are listed here to keep compatibility with Nextcloud versions that
 * still rely on routes.php (and to give a clean overview at a glance).
 */
return [
    'routes' => [
        // Pages
        ['name' => 'page#index',           'url' => '/',                  'verb' => 'GET'],
        ['name' => 'page#quarantine',      'url' => '/quarantine',        'verb' => 'GET'],
        ['name' => 'page#whitelist',       'url' => '/whitelist',         'verb' => 'GET'],
        ['name' => 'page#blacklist',       'url' => '/blacklist',         'verb' => 'GET'],
        ['name' => 'page#fileQuarantine',  'url' => '/file_quarantine',   'verb' => 'GET'],
        ['name' => 'page#virusQuarantine', 'url' => '/virus_quarantine',  'verb' => 'GET'],
        ['name' => 'page#settings',        'url' => '/settings',          'verb' => 'GET'],
        ['name' => 'page#audit',           'url' => '/audit',             'verb' => 'GET'],

        // Spam quarantine
        ['name' => 'api#quarantine',          'url' => '/api/quarantine',              'verb' => 'GET'],
        ['name' => 'api#viewQuarantine',      'url' => '/api/quarantine/view',         'verb' => 'GET'],
        ['name' => 'api#releaseQuarantine',   'url' => '/api/quarantine/release',      'verb' => 'POST'],
        ['name' => 'api#releaseQuarantineWhitelist', 'url' => '/api/quarantine/release-whitelist', 'verb' => 'POST'],
        ['name' => 'api#deleteQuarantine',    'url' => '/api/quarantine/delete',       'verb' => 'POST'],
        ['name' => 'api#exportQuarantine',    'url' => '/api/quarantine/export.csv',   'verb' => 'GET'],

        // File quarantine
        ['name' => 'api#fileQuarantine',          'url' => '/api/file_quarantine',             'verb' => 'GET'],
        ['name' => 'api#releaseFileQuarantine',   'url' => '/api/file_quarantine/release',     'verb' => 'POST'],
        ['name' => 'api#deleteFileQuarantine',    'url' => '/api/file_quarantine/delete',      'verb' => 'POST'],
        ['name' => 'api#exportFileQuarantine',    'url' => '/api/file_quarantine/export.csv',  'verb' => 'GET'],

        // Virus quarantine
        ['name' => 'api#virusQuarantine',         'url' => '/api/virus_quarantine',            'verb' => 'GET'],
        ['name' => 'api#releaseVirusQuarantine',  'url' => '/api/virus_quarantine/release',    'verb' => 'POST'],
        ['name' => 'api#deleteVirusQuarantine',   'url' => '/api/virus_quarantine/delete',     'verb' => 'POST'],
        ['name' => 'api#exportVirusQuarantine',   'url' => '/api/virus_quarantine/export.csv', 'verb' => 'GET'],

        // Whitelist / Blacklist
        ['name' => 'api#whitelist',       'url' => '/api/whitelist',             'verb' => 'GET'],
        ['name' => 'api#addWhitelist',    'url' => '/api/whitelist',             'verb' => 'POST'],
        ['name' => 'api#removeWhitelist', 'url' => '/api/whitelist/remove',      'verb' => 'POST'],
        ['name' => 'api#exportWhitelist', 'url' => '/api/whitelist/export.csv',  'verb' => 'GET'],
        ['name' => 'api#blacklist',       'url' => '/api/blacklist',             'verb' => 'GET'],
        ['name' => 'api#addBlacklist',    'url' => '/api/blacklist',             'verb' => 'POST'],
        ['name' => 'api#removeBlacklist', 'url' => '/api/blacklist/remove',      'verb' => 'POST'],
        ['name' => 'api#exportBlacklist', 'url' => '/api/blacklist/export.csv',  'verb' => 'GET'],

        // Settings (admin only)
        ['name' => 'api#getSettings',  'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'api#saveSettings', 'url' => '/api/settings', 'verb' => 'POST'],

		// Audit
		['name' => 'api#audit',        'url' => '/api/audit',    'verb' => 'GET'],

		// Internal API (cross-app integration — souvera_mail)
		['name' => 'internalApi#spamList',    'url' => '/api/internal/spam/list',    'verb' => 'GET'],
		['name' => 'internalApi#spamView',    'url' => '/api/internal/spam/view',    'verb' => 'GET'],
		['name' => 'internalApi#spamRelease', 'url' => '/api/internal/spam/release', 'verb' => 'POST'],
		['name' => 'internalApi#spamDelete',  'url' => '/api/internal/spam/delete',  'verb' => 'POST'],
		['name' => 'internalApi#spamCount',   'url' => '/api/internal/spam/count',   'verb' => 'GET'],

        // Reputation Management: DMARC Analyzer + weekly mail test (souvera-admins group)
        ['name' => 'page#dmarc',                  'url' => '/dmarc',                             'verb' => 'GET'],
        ['name' => 'page#repProviders',           'url' => '/reputation/providers',              'verb' => 'GET'],
        ['name' => 'page#repChecks',              'url' => '/reputation/checks',                 'verb' => 'GET'],
        ['name' => 'page#repSources',             'url' => '/reputation/sources',                'verb' => 'GET'],
        ['name' => 'page#repIncidents',           'url' => '/reputation/incidents',              'verb' => 'GET'],
        ['name' => 'page#repMailTests',           'url' => '/reputation/mail-tests',             'verb' => 'GET'],
        ['name' => 'dmarc#status',                'url' => '/api/dmarc/status',                  'verb' => 'GET'],
        ['name' => 'dmarc#domain',                'url' => '/api/dmarc/domain',                  'verb' => 'GET'],
        ['name' => 'dmarc#register',              'url' => '/api/dmarc/domain/register',         'verb' => 'POST'],
        ['name' => 'dmarc#verify',                'url' => '/api/dmarc/domain/verify',           'verb' => 'POST'],
        ['name' => 'dmarc#stats',                 'url' => '/api/dmarc/domain/stats',            'verb' => 'GET'],
        ['name' => 'dmarc#reports',               'url' => '/api/dmarc/domain/reports',          'verb' => 'GET'],
        ['name' => 'dmarc#triggerTest',           'url' => '/api/dmarc/domain/test',             'verb' => 'POST'],
        ['name' => 'dmarc#listTests',             'url' => '/api/dmarc/tests',                   'verb' => 'GET'],
        ['name' => 'dmarc#refreshTest',           'url' => '/api/dmarc/tests/{testId}/refresh',  'verb' => 'POST'],

        // Extended reputation analysis (souvera-admins group)
        ['name' => 'reputation#overview',        'url' => '/api/reputation/overview',                        'verb' => 'GET'],
        ['name' => 'reputation#providers',       'url' => '/api/reputation/providers',                       'verb' => 'GET'],
        ['name' => 'reputation#checks',          'url' => '/api/reputation/checks',                          'verb' => 'GET'],
        ['name' => 'reputation#sources',         'url' => '/api/reputation/sources',                         'verb' => 'GET'],
        ['name' => 'reputation#incidents',       'url' => '/api/reputation/incidents',                       'verb' => 'GET'],
        ['name' => 'reputation#resolveIncident', 'url' => '/api/reputation/incidents/{incidentId}/resolve',  'verb' => 'POST'],
        ['name' => 'reputation#analyze', 'url' => '/api/reputation/analyze', 'verb' => 'POST'],

        // Suspicious Login Detection (souvera-admins group)
        ['name' => 'page#suspiciousLogin',     'url' => '/suspicious',                       'verb' => 'GET'],
        ['name' => 'suspiciousLogin#index',   'url' => '/api/suspicious-logins',             'verb' => 'GET'],
        ['name' => 'suspiciousLogin#show',    'url' => '/api/suspicious-logins/{id}',         'verb' => 'GET',  'requirements' => ['id' => '\d+']],
        ['name' => 'suspiciousLogin#resolve', 'url' => '/api/suspicious-logins/{id}/resolve', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
    ],
];
