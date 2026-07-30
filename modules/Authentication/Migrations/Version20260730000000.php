<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Liminal\Lib\Database\Migration\Migration;

/**
 * Users, roles granted per company, login throttling and the audit trail.
 *
 * Nothing here is CompanyScoped, and that is load-bearing rather than lazy.
 * At login the request is anonymous, so the SQL filter's accessible list is the
 * bootstrap company alone: a scoped pivot would make accessibleCompanyIds()
 * return company 1's rows only, and a user belonging solely to company 2 could
 * never log in — while working perfectly on every dev machine where everyone is
 * in company 1. And the scoped trait stamps company_id from the CURRENT scope
 * and refuses later changes, so an admin in company 1 could never create a
 * grant for company 2. These are administration tables: explicit company_id,
 * read across companies on purpose.
 */
final class Version20260730000000 extends Migration
{
    public function getDescription(): string
    {
        return 'Create the authentication tables: users, roles, per-company grants, throttling and audit.';
    }

    public function up(Schema $schema): void
    {
        // module:install plans THIS namespace only (ScopedPlanCalculator), so
        // the security lib's core_session may legitimately not exist yet.
        // abortIf, never skipIf: skipIf would record this migration as executed
        // and the foreign key below would never be created.
        $this->abortIf(
            !$schema->hasTable('core_session'),
            'core_session is missing: run "liminal migrate" before installing this module.',
        );

        $user = $schema->createTable('core_user');
        // integer, NOT bigint: core_session.user_id is a signed INT, and a width
        // mismatch fails the foreign key with MySQL's useless errno 150.
        $user->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        // The sole identifier. No username beside it: two identifiers means two
        // enumeration surfaces and a permanent "which one did I use". Callers
        // normalise (trim + lowercase) in PHP so uniqueness never depends on the
        // server's collation.
        $user->addColumn('email', Types::STRING, ['length' => 191]);
        $user->addColumn('password_hash', Types::STRING, ['length' => 255]);
        $user->addColumn('display_name', Types::STRING, ['length' => 191]);
        // The whole deactivation feature: filtered in byId() AND forLogin(), so
        // a live session logs itself out on the next request.
        $user->addColumn('is_active', Types::BOOLEAN, ['default' => true]);
        $user->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $user->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $user->setPrimaryKey(['id']);
        $user->addUniqueIndex(['email'], 'uniq_core_user_email');

        // Roles are global definitions; the pivot is what makes RBAC
        // per-company. Per-company role ROWS would make "the accountant role" a
        // different object per company, so every permission change would have to
        // be applied N times. Adding a nullable company_id later (company-local
        // roles) is additive; un-scoping a scoped table is a data migration.
        $role = $schema->createTable('core_role');
        $role->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $role->addColumn('code', Types::STRING, ['length' => 64]);
        $role->addColumn('label', Types::STRING, ['length' => 191]);
        $role->setPrimaryKey(['id']);
        $role->addUniqueIndex(['code'], 'uniq_core_role_code');

        // permission_code references the frozen PermissionRegistry, so no
        // foreign key is possible. A stale code is harmless by construction:
        // Gate::allows() refuses an undeclared code before the resolver is ever
        // consulted, so a row naming one can never be reached.
        $rolePermission = $schema->createTable('core_role_permission');
        $rolePermission->addColumn('role_id', Types::INTEGER);
        $rolePermission->addColumn('permission_code', Types::STRING, ['length' => 191]);
        $rolePermission->setPrimaryKey(['role_id', 'permission_code']);
        $rolePermission->addForeignKeyConstraint('core_role', ['role_id'], ['id'], ['onDelete' => 'CASCADE']);

        $grant = $schema->createTable('core_user_company_role');
        $grant->addColumn('user_id', Types::INTEGER);
        $grant->addColumn('company_id', Types::INTEGER);
        $grant->addColumn('role_id', Types::INTEGER);
        $grant->setPrimaryKey(['user_id', 'company_id', 'role_id']);
        $grant->addIndex(['user_id', 'company_id'], 'idx_core_user_company_role_lookup');
        $grant->addForeignKeyConstraint('core_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $grant->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        $grant->addForeignKeyConstraint('core_role', ['role_id'], ['id'], ['onDelete' => 'CASCADE']);

        // Throttle counters: hot, tiny, cleared on success. Separate from the
        // audit trail because merging them would force either counting rows in a
        // forever-growing table on every login, or pruning the audit to keep the
        // counter cheap.
        $attempt = $schema->createTable('core_login_attempt');
        $attempt->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $attempt->addColumn('kind', Types::STRING, ['length' => 16]);
        $attempt->addColumn('subject', Types::STRING, ['length' => 191]);
        $attempt->addColumn('failures', Types::INTEGER, ['default' => 0]);
        $attempt->addColumn('first_at', Types::DATETIME_IMMUTABLE);
        $attempt->addColumn('last_at', Types::DATETIME_IMMUTABLE);
        $attempt->addColumn('locked_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $attempt->setPrimaryKey(['id']);
        $attempt->addUniqueIndex(['kind', 'subject'], 'uniq_core_login_attempt_subject');
        $attempt->addIndex(['last_at'], 'idx_core_login_attempt_last');

        // Append-only audit. user_id is SET NULL, never CASCADE: deleting an
        // account must not erase the evidence of what it did.
        $event = $schema->createTable('core_auth_event');
        $event->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $event->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $event->addColumn('event', Types::STRING, ['length' => 32]);
        $event->addColumn('user_id', Types::INTEGER, ['notnull' => false]);
        $event->addColumn('identifier', Types::STRING, ['length' => 191]);
        $event->addColumn('ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
        $event->addColumn('user_agent', Types::STRING, ['length' => 255, 'notnull' => false]);
        $event->setPrimaryKey(['id']);
        $event->addIndex(['occurred_at'], 'idx_core_auth_event_occurred');
        $event->addIndex(['user_id', 'occurred_at'], 'idx_core_auth_event_user');
        $event->addForeignKeyConstraint('core_user', ['user_id'], ['id'], ['onDelete' => 'SET NULL']);

        // The one sanctioned case of a module migration touching a lib's table:
        // the security lib cannot reference core_user, which does not exist
        // until this module installs. CASCADE so deleting an account kills its
        // live sessions — the index this needs already shipped with the table.
        // Named explicitly so down() can drop exactly this one.
        $schema->getTable('core_session')
            ->addForeignKeyConstraint('core_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_core_session_user');
    }

    public function down(Schema $schema): void
    {
        // The session foreign key must go before core_user can.
        $schema->getTable('core_session')->removeForeignKey('fk_core_session_user');

        $schema->dropTable('core_auth_event');
        $schema->dropTable('core_login_attempt');
        $schema->dropTable('core_user_company_role');
        $schema->dropTable('core_role_permission');
        $schema->dropTable('core_role');
        $schema->dropTable('core_user');
    }
}
