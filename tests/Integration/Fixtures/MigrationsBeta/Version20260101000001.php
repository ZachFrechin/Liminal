<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\MigrationsBeta;

use Doctrine\DBAL\Schema\Schema;
use Liminal\Lib\Database\Migration\Migration;

final class Version20260101000001 extends Migration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('test_beta');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('test_beta');
    }
}
