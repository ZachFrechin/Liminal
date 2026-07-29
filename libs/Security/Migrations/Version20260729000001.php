<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Liminal\Lib\Database\Migration\Migration;

/**
 * Database-backed sessions.
 *
 * PHP and MariaDB clocks are assumed co-located, as everywhere else in the
 * core: every timestamp is written by PHP.
 */
final class Version20260729000001 extends Migration
{
    public function getDescription(): string
    {
        return 'Create core_session: database-backed sessions keyed by the SHA-256 of the cookie value.';
    }

    public function up(Schema $schema): void
    {
        $session = $schema->createTable('core_session');

        // The PRIMARY KEY is sha256(cookie), hex: a leaked dump contains no
        // value a browser could replay. The raw id never touches the database.
        $session->addColumn('id', 'string', ['length' => 64, 'fixed' => true]);

        // No FK until core_user exists; the authentication module's own
        // migration adds it (ON DELETE CASCADE) in phase 5 — the one
        // sanctioned case of a module migration touching a lib's table.
        // The index exists NOW: "kill every session of user X" on password
        // change is a phase-5 requirement.
        $session->addColumn('user_id', 'integer', ['notnull' => false]);
        $session->addColumn('company_id', 'integer', ['notnull' => false]);
        $session->addColumn('payload', 'text');
        $session->addColumn('created_at', 'datetime_immutable');
        $session->addColumn('last_seen_at', 'datetime_immutable');

        // min(last_seen + idle_ttl, created + absolute_ttl): one indexed
        // column enforces both OWASP timeouts, for SELECT validity and GC.
        $session->addColumn('expires_at', 'datetime_immutable');

        $session->setPrimaryKey(['id']);
        $session->addIndex(['expires_at'], 'idx_core_session_expires');
        $session->addIndex(['user_id'], 'idx_core_session_user');
        $session->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('core_session');
    }
}
