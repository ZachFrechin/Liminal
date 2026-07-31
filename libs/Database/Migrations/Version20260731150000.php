<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Liminal\Lib\Database\Migration\Migration;

/**
 * Company identity: what a legal document must say about its issuer —
 * address, VAT number, registration, and the free-text legal mentions a
 * French invoice carries (payment terms, late penalties, recovery
 * indemnity). The tree's first UPGRADE migration of a lib table.
 *
 * Every column is nullable on purpose: identity arrives later, on the
 * company screen — a fresh install and every existing test row stay valid.
 * No abortIf here: this namespace owns core_company (the guard doctrine
 * binds migrations touching ANOTHER namespace's table), and a hasColumn
 * guard would brick the one recovery scenario it pretends to serve.
 */
final class Version20260731150000 extends Migration
{
    public function getDescription(): string
    {
        return 'Add the identity columns to core_company.';
    }

    public function up(Schema $schema): void
    {
        $company = $schema->getTable('core_company');
        $company->addColumn('address', Types::STRING, ['length' => 255, 'notnull' => false]);
        $company->addColumn('zip', Types::STRING, ['length' => 16, 'notnull' => false]);
        $company->addColumn('town', Types::STRING, ['length' => 128, 'notnull' => false]);
        $company->addColumn('country_code', Types::STRING, ['length' => 2, 'fixed' => true, 'notnull' => false]);
        $company->addColumn('vat_number', Types::STRING, ['length' => 32, 'notnull' => false]);
        $company->addColumn('registration', Types::STRING, ['length' => 64, 'notnull' => false]);
        $company->addColumn('legal_mentions', Types::TEXT, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $company = $schema->getTable('core_company');

        foreach (['address', 'zip', 'town', 'country_code', 'vat_number', 'registration', 'legal_mentions'] as $column) {
            $company->dropColumn($column);
        }
    }
}
