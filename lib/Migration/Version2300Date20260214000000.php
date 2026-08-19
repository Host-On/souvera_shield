<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Souvera Shield v2.3.0 – add DMARC / mail-test schema.
 *
 * Creates two tables:
 *   - `oc_souvera_shield_dmarc_domain`  (list of monitored domains)
 *   - `oc_souvera_shield_mail_test`     (history of mail-test sessions)
 */
class Version2300Date20260214000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('souvera_shield_dmarc_domain')) {
            $table = $schema->createTable('souvera_shield_dmarc_domain');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
                'unsigned' => true,
            ]);
            $table->addColumn('domain', Types::STRING, [
                'notnull' => true,
                'length' => 253,
            ]);
            $table->addColumn('sender_address', Types::STRING, [
                'notnull' => true,
                'length' => 320,
            ]);
            $table->addColumn('active', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $table->addColumn('last_dmarc_check_at', Types::BIGINT, [
                'notnull' => false,
                'length' => 20,
            ]);
            $table->addColumn('last_dmarc_data', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('provider_verified', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
                'default' => 0,
            ]);
            $table->addColumn('created_by', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'default' => '',
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['domain'], 'sh_dmarc_domain_uniq');
        }

        if (!$schema->hasTable('souvera_shield_mail_test')) {
            $table = $schema->createTable('souvera_shield_mail_test');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
                'unsigned' => true,
            ]);
            $table->addColumn('domain_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('test_id', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('test_email', Types::STRING, [
                'notnull' => true,
                'length' => 320,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'default' => 'pending',
            ]);
            $table->addColumn('score', Types::FLOAT, [
                'notnull' => false,
            ]);
            $table->addColumn('spf_result', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('dkim_result', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('dmarc_result', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('raw_result', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('error_message', Types::STRING, [
                'notnull' => false,
                'length' => 512,
            ]);
            $table->addColumn('trigger_type', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'manual',
            ]);
            $table->addColumn('triggered_by', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
                'default' => 0,
            ]);
            $table->addColumn('sent_at', Types::BIGINT, [
                'notnull' => false,
                'length' => 20,
            ]);
            $table->addColumn('completed_at', Types::BIGINT, [
                'notnull' => false,
                'length' => 20,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['domain_id', 'created_at'], 'sh_mtest_domain_idx');
            $table->addIndex(['status'], 'sh_mtest_status_idx');
        }

        return $schema;
    }
}
