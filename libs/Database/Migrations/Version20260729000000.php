<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Liminal\Lib\Database\Migration\Migration;

/**
 * Core tables.
 *
 * No global table prefix: the per-module prefix (core_, invoicing_, ...) is
 * already the boundary the registries can verify, and a second prefix would only
 * add noise.
 */
final class Version20260729000000 extends Migration
{
    public function getDescription(): string
    {
        return 'Create the core_* tables: companies, modules, per-company activation and settings.';
    }

    public function up(Schema $schema): void
    {
        $entity = $schema->createTable('core_company');
        $entity->addColumn('id', 'integer', ['autoincrement' => true]);
        $entity->addColumn('code', 'string', ['length' => 32]);
        $entity->addColumn('name', 'string', ['length' => 255]);
        $entity->addColumn('created_at', 'datetime_immutable');
        $entity->addColumn('updated_at', 'datetime_immutable');
        $entity->setPrimaryKey(['id']);
        $entity->addUniqueIndex(['code'], 'uniq_core_company_code');

        $module = $schema->createTable('core_module');
        $module->addColumn('id', 'integer', ['autoincrement' => true]);
        $module->addColumn('name', 'string', ['length' => 64]);
        $module->addColumn('version', 'string', ['length' => 32]);
        $module->addColumn('state', 'string', ['length' => 16]);
        $module->addColumn('installed_at', 'datetime_immutable');
        $module->setPrimaryKey(['id']);
        $module->addUniqueIndex(['name'], 'uniq_core_module_name');

        // Modules are installed globally but enabled per company.
        $moduleEntity = $schema->createTable('core_module_company');
        $moduleEntity->addColumn('module_id', 'integer');
        $moduleEntity->addColumn('company_id', 'integer');
        $moduleEntity->addColumn('enabled', 'boolean', ['default' => false]);
        $moduleEntity->setPrimaryKey(['module_id', 'company_id']);
        $moduleEntity->addForeignKeyConstraint('core_module', ['module_id'], ['id'], ['onDelete' => 'CASCADE']);
        $moduleEntity->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);

        $setting = $schema->createTable('core_setting');
        $setting->addColumn('id', 'integer', ['autoincrement' => true]);
        $setting->addColumn('setting_key', 'string', ['length' => 191]);
        $setting->addColumn('value', 'text', ['notnull' => false]);
        $setting->addColumn('scope', 'string', ['length' => 16]);
        $setting->addColumn('company_id', 'integer', ['notnull' => false]);
        $setting->addColumn('user_id', 'integer', ['notnull' => false]);

        // MariaDB unique indexes treat NULL as distinct, so (key, NULL, NULL)
        // global settings could duplicate without limit. These STORED sentinels
        // collapse NULL to 0 — an id core_company never allocates — so the
        // unique index below holds for all three scopes. columnDefinition keeps
        // them inside the single CREATE TABLE (addSql() statements run BEFORE
        // the schema diff), and the grammar accepts no NOT NULL clause; the
        // COALESCE never yields NULL anyway. Known limitation: DBAL's MySQL
        // introspection does not understand generated columns, so a future
        // migrations:diff would report noise on these two — irrelevant while
        // migrations are hand-written.
        $setting->addColumn('company_key', 'integer', [
            'columnDefinition' => 'INT GENERATED ALWAYS AS (COALESCE(company_id, 0)) STORED',
        ]);
        $setting->addColumn('user_key', 'integer', [
            'columnDefinition' => 'INT GENERATED ALWAYS AS (COALESCE(user_id, 0)) STORED',
        ]);

        $setting->addColumn('created_at', 'datetime_immutable');
        $setting->addColumn('updated_at', 'datetime_immutable');
        $setting->setPrimaryKey(['id']);
        $setting->addUniqueIndex(['setting_key', 'company_key', 'user_key'], 'uniq_core_setting_scope');
        $setting->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('core_setting');
        $schema->dropTable('core_module_company');
        $schema->dropTable('core_module');
        $schema->dropTable('core_company');
    }
}
