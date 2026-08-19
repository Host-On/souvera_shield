<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Souvera Shield v2.4.2 – widen `error_message` for the mail-test log.
 *
 * The v2.3.0 schema defined `error_message` as VARCHAR(512), which was
 * fine for the previous one-line SMTP errors. Since v2.4.1 we store a
 * multi-sentence diagnostic with concrete remediation hints (config
 * keys, Postfix rules, PMG relay checklist), regularly exceeding 512
 * characters. Trying to persist such an entry produced
 *
 *   SQLSTATE[22001]: String data, right truncated: 1406
 *   Data too long for column 'error_message' at row 1
 *
 * Switching to TEXT removes the length ceiling. The service also
 * truncates defensively so pre-migration instances survive.
 */
class Version2420Date20260215120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('souvera_shield_mail_test')) {
            return $schema;
        }
        $table = $schema->getTable('souvera_shield_mail_test');
        if (!$table->hasColumn('error_message')) {
            return $schema;
        }

        $col = $table->getColumn('error_message');
        // Only re-shape if not already TEXT to keep the migration idempotent.
        if (strtoupper($col->getType()->getName()) !== 'TEXT') {
            $table->changeColumn('error_message', [
                'type'    => \Doctrine\DBAL\Types\Type::getType(Types::TEXT),
                'length'  => null,
                'notnull' => false,
            ]);
        }

        return $schema;
    }
}
