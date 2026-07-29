<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Core tables.
 *
 * No global table prefix: the per-module prefix (core_, invoicing_, ...) is
 * already the boundary the registries can verify, and a second prefix would only
 * add noise.
 */
final class Version20260729000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the core_* tables: companies, modules, per-company activation and settings.';
    }

    public function up(Schema $schema): void
    {
        $entity = $schema->createTable('core_entity');
        $entity->addColumn('id', 'integer', ['autoincrement' => true]);
        $entity->addColumn('code', 'string', ['length' => 32]);
        $entity->addColumn('name', 'string', ['length' => 255]);
        $entity->addColumn('created_at', 'datetime_immutable');
        $entity->addColumn('updated_at', 'datetime_immutable');
        $entity->setPrimaryKey(['id']);
        $entity->addUniqueIndex(['code'], 'uniq_core_entity_code');

        $module = $schema->createTable('core_module');
        $module->addColumn('id', 'integer', ['autoincrement' => true]);
        $module->addColumn('name', 'string', ['length' => 64]);
        $module->addColumn('version', 'string', ['length' => 32]);
        $module->addColumn('state', 'string', ['length' => 16]);
        $module->addColumn('installed_at', 'datetime_immutable');
        $module->setPrimaryKey(['id']);
        $module->addUniqueIndex(['name'], 'uniq_core_module_name');

        // Modules are installed globally but enabled per company.
        $moduleEntity = $schema->createTable('core_module_entity');
        $moduleEntity->addColumn('module_id', 'integer');
        $moduleEntity->addColumn('entity_id', 'integer');
        $moduleEntity->addColumn('enabled', 'boolean', ['default' => false]);
        $moduleEntity->setPrimaryKey(['module_id', 'entity_id']);
        $moduleEntity->addForeignKeyConstraint('core_module', ['module_id'], ['id'], ['onDelete' => 'CASCADE']);
        $moduleEntity->addForeignKeyConstraint('core_entity', ['entity_id'], ['id'], ['onDelete' => 'CASCADE']);

        $setting = $schema->createTable('core_setting');
        $setting->addColumn('id', 'integer', ['autoincrement' => true]);
        $setting->addColumn('setting_key', 'string', ['length' => 191]);
        $setting->addColumn('value', 'text', ['notnull' => false]);
        $setting->addColumn('scope', 'string', ['length' => 16]);
        $setting->addColumn('entity_id', 'integer', ['notnull' => false]);
        $setting->addColumn('user_id', 'integer', ['notnull' => false]);
        $setting->addColumn('created_at', 'datetime_immutable');
        $setting->addColumn('updated_at', 'datetime_immutable');
        $setting->setPrimaryKey(['id']);
        $setting->addUniqueIndex(['setting_key', 'entity_id', 'user_id'], 'uniq_core_setting_scope');
        $setting->addForeignKeyConstraint('core_entity', ['entity_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('core_setting');
        $schema->dropTable('core_module_entity');
        $schema->dropTable('core_module');
        $schema->dropTable('core_entity');
    }
}
