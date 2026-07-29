<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\ModuleFixture\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Liminal\Lib\Database\Migration\Migration;

/**
 * The fixture module's own table — deliberately its own namespace, so the
 * runner-scoping fixtures (MigrationsAlpha/Beta) and the module lifecycle
 * tests can evolve independently.
 */
final class Version20260730000000 extends Migration
{
    public function getDescription(): string
    {
        return 'Create the module fixture table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('test_module_fixture');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('label', 'string', ['length' => 64]);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('test_module_fixture');
    }
}
