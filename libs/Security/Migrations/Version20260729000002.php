<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Liminal\Lib\Database\Migration\Migration;

/**
 * The audit trail behind the trigger catch-all: one append-only row per
 * fired trigger, whoever fires it, forever.
 *
 * ZERO foreign keys, twice deliberate. This lib cannot reference core_user —
 * a module's table, the exact reason core_auth_event lives in the module —
 * and more fundamentally an audit stores HISTORICAL FACTS, not live
 * references: a deleted user's id stays readable verbatim, which is better
 * forensics than the SET NULL a foreign key would force (the module's own
 * audit table already refuses CASCADE for the same evidence-preservation
 * reason).
 *
 * The event column is named `event`, not `trigger`: TRIGGER is a reserved
 * word and DBAL's insert() builds unquoted column lists. Its 64 characters
 * are the ceiling the TriggerRegistry grammar enforces at boot — a name
 * that cannot be stored fails the boot, not the insert.
 */
final class Version20260729000002 extends Migration
{
    public function getDescription(): string
    {
        return 'Create the append-only audit trail the trigger catch-all writes';
    }

    public function up(Schema $schema): void
    {
        $event = $schema->createTable('core_audit_event');
        $event->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $event->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $event->addColumn('event', Types::STRING, ['length' => 64]);
        $event->addColumn('actor_id', Types::INTEGER, ['notnull' => false]);
        $event->addColumn('company_id', Types::INTEGER, ['notnull' => false]);
        $event->addColumn('payload', Types::TEXT);
        $event->setPrimaryKey(['id']);
        $event->addIndex(['occurred_at'], 'idx_core_audit_event_occurred');
        $event->addIndex(['event', 'occurred_at'], 'idx_core_audit_event_kind');
        $event->addIndex(['actor_id', 'occurred_at'], 'idx_core_audit_event_actor');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('core_audit_event');
    }
}
