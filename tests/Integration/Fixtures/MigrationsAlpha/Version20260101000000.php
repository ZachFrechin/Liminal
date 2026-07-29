<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\MigrationsAlpha;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('test_alpha');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('test_alpha');
    }
}
