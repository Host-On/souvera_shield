<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Souvera Shield v4.0.0 – Suspicious Login Detection tables.
 *
 * Four new tables:
 *
 *   souvera_shield_login_trace       – raw login events (success/failure)
 *   souvera_shield_login_baseline    – per-user behavioral baseline
 *   souvera_shield_suspicious_event  – detected suspicious login events
 *   souvera_shield_login_feedback    – admin/user feedback on events
 */
class Version4000Date20260729000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('souvera_shield_login_trace')) {
            $table = $schema->createTable('souvera_shield_login_trace');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('ip', Types::STRING, [
                'notnull' => false,
                'length'  => 45,
            ]);
            $table->addColumn('ip_subnet', Types::STRING, [
                'notnull' => false,
                'length'  => 19,
            ]);
            $table->addColumn('success', Types::SMALLINT, [
                'notnull' => false,
                'length'  => 1,
            ]);
            $table->addColumn('user_agent', Types::STRING, [
                'notnull' => false,
                'length'  => 512,
            ]);
            $table->addColumn('device_hash', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
            ]);
            $table->addColumn('geo_country', Types::STRING, [
                'notnull' => false,
                'length'  => 2,
            ]);
            $table->addColumn('geo_city', Types::STRING, [
                'notnull' => false,
                'length'  => 128,
            ]);
            $table->addColumn('isp_name', Types::STRING, [
                'notnull' => false,
                'length'  => 256,
            ]);
            $table->addColumn('asn', Types::STRING, [
                'notnull' => false,
                'length'  => 16,
            ]);
            $table->addColumn('risk_flags', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('risk_score', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('rule_results', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 20,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'created_at'], 'ss_lt_user_created');
            $table->addIndex(['ip', 'created_at'], 'ss_lt_ip_created');
            $table->addIndex(['ip_subnet', 'created_at'], 'ss_lt_subnet_created');
        }

        if (!$schema->hasTable('souvera_shield_login_baseline')) {
            $table = $schema->createTable('souvera_shield_login_baseline');
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('total_logins', Types::INTEGER, [
                'notnull' => false,
                'default' => 0,
            ]);
            $table->addColumn('active_days', Types::INTEGER, [
                'notnull' => false,
                'default' => 0,
            ]);
            $table->addColumn('first_seen', Types::BIGINT, [
                'notnull' => false,
                'length'  => 20,
            ]);
            $table->addColumn('last_seen', Types::BIGINT, [
                'notnull' => false,
                'length'  => 20,
            ]);
            $table->addColumn('trusted_subnets', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('trusted_countries', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('trusted_isps', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('trusted_devices', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('typical_hours', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('avg_logins_per_day', Types::FLOAT, [
                'notnull' => false,
            ]);
            $table->addColumn('grace_period_until', Types::BIGINT, [
                'notnull' => false,
                'length'  => 20,
            ]);
            $table->setPrimaryKey(['user_id']);
        }

        if (!$schema->hasTable('souvera_shield_suspicious_event')) {
            $table = $schema->createTable('souvera_shield_suspicious_event');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('trace_id', Types::BIGINT, [
                'notnull' => false,
                'length'  => 20,
            ]);
            $table->addColumn('confidence', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('severity', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'low',
            ]);
            $table->addColumn('decision', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('ip', Types::STRING, [
                'notnull' => false,
                'length'  => 45,
            ]);
            $table->addColumn('geo_country', Types::STRING, [
                'notnull' => false,
                'length'  => 2,
            ]);
            $table->addColumn('geo_city', Types::STRING, [
                'notnull' => false,
                'length'  => 128,
            ]);
            $table->addColumn('isp_name', Types::STRING, [
                'notnull' => false,
                'length'  => 256,
            ]);
            $table->addColumn('risk_flags', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('resolved', Types::SMALLINT, [
                'notnull' => true,
                'length'  => 1,
                'default' => 0,
            ]);
            $table->addColumn('resolved_by', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
            ]);
            $table->addColumn('resolved_at', Types::BIGINT, [
                'notnull' => false,
                'length'  => 20,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 20,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'created_at'], 'ss_se_user_created');
            $table->addIndex(['resolved', 'created_at'], 'ss_se_resolved_created');
        }

        if (!$schema->hasTable('souvera_shield_login_feedback')) {
            $table = $schema->createTable('souvera_shield_login_feedback');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);
            $table->addColumn('ip', Types::STRING, [
                'notnull' => false,
                'length'  => 45,
            ]);
            $table->addColumn('ip_subnet', Types::STRING, [
                'notnull' => false,
                'length'  => 19,
            ]);
            $table->addColumn('feedback', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
            ]);
            $table->addColumn('created_by', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
            ]);
            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 20,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'ip_subnet'], 'ss_lf_user_subnet');
        }

        return $schema;
    }
}
