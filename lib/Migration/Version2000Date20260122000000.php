<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the `souvera_shield_audit` table that holds the audit-trail
 * of every mutating action (release / delete / list change).
 */
class Version2000Date20260122000000 extends SimpleMigrationStep {

    public const TABLE = 'souvera_shield_audit';

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable(self::TABLE)) {
            $table = $schema->createTable(self::TABLE);
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('action', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('target', Types::STRING, [
                'notnull' => true,
                'length' => 255,
                'default' => '',
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
                'length' => 20,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'shield_audit_uid');
            $table->addIndex(['created_at'], 'shield_audit_created');
        }
        return $schema;
    }
}
