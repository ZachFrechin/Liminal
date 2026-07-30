<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Liminal\Lib\Database\Migration\Migration;

/**
 * The thirdparty table — the first business table, and the first CompanyScoped
 * one: company_id is NOT NULL (the trait's column is non-nullable, so the
 * composite unique below needs no sentinel trick), and both indexes lead with
 * company_id because the scope filter prefixes every single query with it.
 *
 * (company_id, code) is unique per company under the server's case-insensitive
 * collation: ACME and acme collide within one company, while another company
 * may reuse the same code freely.
 */
final class Version20260730000000 extends Migration
{
    public function getDescription(): string
    {
        return 'Create the thirdparty table, fenced per company';
    }

    public function up(Schema $schema): void
    {
        // core_company belongs to the Database lib. The full migrate orders
        // libs before modules, but module:install plans THIS namespace alone —
        // abortIf, never skipIf: skipping would record the version as executed
        // and the table would never exist.
        $this->abortIf(
            !$schema->hasTable('core_company'),
            'core_company is missing: run "liminal migrate" before installing this module.',
        );

        $thirdparty = $schema->createTable('thirdparty_thirdparty');
        $thirdparty->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $thirdparty->addColumn('company_id', Types::INTEGER);
        $thirdparty->addColumn('code', Types::STRING, ['length' => 32]);
        $thirdparty->addColumn('name', Types::STRING, ['length' => 191]);
        $thirdparty->addColumn('alias', Types::STRING, ['length' => 191, 'notnull' => false]);
        $thirdparty->addColumn('is_customer', Types::BOOLEAN, ['default' => false]);
        $thirdparty->addColumn('is_supplier', Types::BOOLEAN, ['default' => false]);
        $thirdparty->addColumn('email', Types::STRING, ['length' => 191, 'notnull' => false]);
        $thirdparty->addColumn('phone', Types::STRING, ['length' => 32, 'notnull' => false]);
        $thirdparty->addColumn('address', Types::STRING, ['length' => 255, 'notnull' => false]);
        $thirdparty->addColumn('zip', Types::STRING, ['length' => 16, 'notnull' => false]);
        $thirdparty->addColumn('town', Types::STRING, ['length' => 128, 'notnull' => false]);
        $thirdparty->addColumn('country_code', Types::STRING, ['length' => 2, 'notnull' => false]);
        $thirdparty->addColumn('vat_number', Types::STRING, ['length' => 32, 'notnull' => false]);
        $thirdparty->addColumn('notes', Types::TEXT, ['notnull' => false]);
        $thirdparty->addColumn('is_active', Types::BOOLEAN, ['default' => true]);
        $thirdparty->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $thirdparty->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $thirdparty->setPrimaryKey(['id']);
        $thirdparty->addUniqueIndex(['company_id', 'code'], 'uniq_thirdparty_thirdparty_company_code');
        $thirdparty->addIndex(['company_id', 'name'], 'idx_thirdparty_thirdparty_company_name');
        $thirdparty->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('thirdparty_thirdparty');
    }
}
