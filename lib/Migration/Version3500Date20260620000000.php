<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Souvera Shield v3.5.0 – Reputation incidents + score history.
 *
 * Two new tables:
 *
 *   souvera_shield_incident    – automatically detected reputation
 *                                incidents (deduplicated, with a JSON
 *                                measures/history trail)
 *   souvera_shield_score_snap  – daily snapshots of the composite
 *                                reputation score (0–100) incl. the
 *                                component breakdown
 */
class Version3500Date20260620000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('souvera_shield_incident')) {
            $table = $schema->createTable('souvera_shield_incident');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            $table->addColumn('dedupe_key', Types::STRING, [
                'notnull' => true,
                'length'  => 190,
            ]);
            $table->addColumn('severity', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'warning',
            ]);
            $table->addColumn('category', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
                'default' => 'infra',
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('recommendation', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('domain_name', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $table->addColumn('evidence', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('measures', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'open',
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 20,
                'default' => 0,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 20,
                'default' => 0,
            ]);
            $table->addColumn('resolved_at', Types::BIGINT, [
                'notnull' => false,
                'length'  => 20,
            ]);
            $table->addColumn('resolved_by', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['dedupe_key'], 'ss_incident_dedupe_uq');
            $table->addIndex(['domain_name', 'status'], 'ss_incident_dom_status');
        }

        if (!$schema->hasTable('souvera_shield_score_snap')) {
            $table = $schema->createTable('souvera_shield_score_snap');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]);
            $table->addColumn('domain_name', Types::STRING, [
                'notnull' => true,
                'length'  => 255,
            ]);
            $table->addColumn('score', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('components', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'length'  => 20,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['domain_name', 'created_at'], 'ss_snap_dom_created');
        }

        return $schema;
    }
}
