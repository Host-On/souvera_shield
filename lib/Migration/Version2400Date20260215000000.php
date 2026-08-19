<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Souvera Shield v2.4.0 – DMARC Analyzer schema extension.
 *
 * The v2.3.x reputation area only performed a single DNS snapshot
 * (`GET /dmarc-check`). v2.4 upgrades to the full DMARC Analyzer of
 * provider.tools, which needs three extra columns on the managed
 * domain row:
 *
 *   - provider_domain_id  string   ID returned by `POST /dmarc/domains`
 *   - verification_txt    string   TXT value for `_provider-tools.<domain>`
 *   - report_email        string   RUA target address (d-xxx@...)
 *   - dmarc_record        text     Full DMARC record recommended by
 *                                  provider.tools (includes rua=)
 *   - registered_at       bigint   Timestamp of the initial registration.
 *
 * The legacy `last_dmarc_data` column stays around so historic rows
 * remain readable; it is no longer written to.
 */
class Version2400Date20260215000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('souvera_shield_dmarc_domain')) {
            return $schema;
        }

        $table = $schema->getTable('souvera_shield_dmarc_domain');

        if (!$table->hasColumn('provider_domain_id')) {
            $table->addColumn('provider_domain_id', Types::STRING, [
                'notnull' => false,
                'length'  => 128,
            ]);
        }
        if (!$table->hasColumn('verification_txt')) {
            $table->addColumn('verification_txt', Types::STRING, [
                'notnull' => false,
                'length'  => 255,
            ]);
        }
        if (!$table->hasColumn('report_email')) {
            $table->addColumn('report_email', Types::STRING, [
                'notnull' => false,
                'length'  => 320,
            ]);
        }
        if (!$table->hasColumn('dmarc_record')) {
            $table->addColumn('dmarc_record', Types::TEXT, [
                'notnull' => false,
            ]);
        }
        if (!$table->hasColumn('registered_at')) {
            $table->addColumn('registered_at', Types::BIGINT, [
                'notnull' => false,
                'length'  => 20,
            ]);
        }

        return $schema;
    }
}
