<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Liminal\Lib\Database\Migration\Migration;

/**
 * API tokens: the bearer credential storage behind the security lib's
 * TokenProvider contract. Wholly this module's table — it references
 * core_user, this module's own — so unlike the core_session foreign key this
 * needs no cross-namespace exception.
 *
 * Only the sha256 of the raw token is stored, the session posture: a leaked
 * dump contains nothing a client could replay. The surrogate integer id
 * exists because the account screen and the revoke command need a row
 * identifier that is not the hash; the provider looks up through the unique
 * hash index, timing-equivalent to the session PK lookup.
 */
final class Version20260731120000 extends Migration
{
    public function getDescription(): string
    {
        return 'Create the core_api_token table for bearer authentication.';
    }

    public function up(Schema $schema): void
    {
        // module:install plans THIS namespace only — core_user may not exist
        // on a partial run. abortIf, never skipIf.
        $this->abortIf(
            !$schema->hasTable('core_user'),
            'core_user is missing: run the earlier authentication migrations first.',
        );

        $token = $schema->createTable('core_api_token');
        $token->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $token->addColumn('token_hash', Types::STRING, ['length' => 64, 'fixed' => true]);
        $token->addColumn('user_id', Types::INTEGER);
        $token->addColumn('label', Types::STRING, ['length' => 100]);
        $token->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $token->addColumn('last_used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $token->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $token->setPrimaryKey(['id']);
        $token->addUniqueIndex(['token_hash'], 'uniq_core_api_token_hash');
        $token->addIndex(['user_id'], 'idx_core_api_token_user');
        // Deleting a user takes their tokens along — revocation by deletion.
        $token->addForeignKeyConstraint('core_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('core_api_token');
    }
}
