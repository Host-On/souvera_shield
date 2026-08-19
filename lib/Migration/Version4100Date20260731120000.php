<?php

declare(strict_types=1);

namespace OCA\SouveraShield\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `is_admin` to login feedback: only admin-issued feedback may influence
 * scoring. A compromised user previously could resolve their own events with
 * `false_positive`/`known_location`, which reduced the score of their subnet
 * by -30 and silently weakened detection for the attacker's location.
 */
class Version4100Date20260731120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('souvera_shield_login_feedback');
        if (!$table->hasColumn('is_admin')) {
            $table->addColumn('is_admin', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
        }

        return $schema;
    }
}
