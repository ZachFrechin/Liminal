<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Liminal\Lib\Database\Migration\Migration;

/**
 * The invoice tables — the tree's first DECIMAL columns (amounts never ride
 * floats; PHP computes in integer cents and stores decimal strings) and its
 * first cross-module foreign key.
 *
 * thirdparty_id is ON DELETE RESTRICT on purpose: a validated invoice is a
 * legal record, and the database refuses to orphan it even if some future
 * writer bypasses the delete handler's veto hook. Deliberate consequence,
 * pinned by InvoiceScopeTest: deleting a company whose invoices still exist
 * fails (InnoDB cascades sibling foreign keys in creation order — the
 * thirdparty row goes first and the RESTRICT fires). The future
 * company-deletion service deletes invoices before companies, in that order,
 * applicatively.
 */
final class Version20260731000000 extends Migration
{
    public function getDescription(): string
    {
        return 'Create the invoice, invoice line and numbering sequence tables';
    }

    public function up(Schema $schema): void
    {
        // Both referenced tables belong to other namespaces (core_company to
        // the Database lib, thirdparty_thirdparty to the thirdparty module).
        // The full migrate orders contributions correctly, but module:install
        // plans THIS namespace alone — abortIf, never skipIf: skipping would
        // record the version as executed and the tables would never exist.
        $this->abortIf(
            !$schema->hasTable('core_company'),
            'core_company is missing: run "liminal migrate" before installing this module.',
        );
        $this->abortIf(
            !$schema->hasTable('thirdparty_thirdparty'),
            'thirdparty_thirdparty is missing: the invoice module depends on the thirdparty module — install it first.',
        );

        $invoice = $schema->createTable('invoice_invoice');
        $invoice->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $invoice->addColumn('company_id', Types::INTEGER);
        $invoice->addColumn('thirdparty_id', Types::INTEGER);
        $invoice->addColumn('status', Types::STRING, ['length' => 16]);
        $invoice->addColumn('number', Types::STRING, ['length' => 32, 'notnull' => false]);
        $invoice->addColumn('issued_on', Types::DATE_IMMUTABLE);
        $invoice->addColumn('due_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $invoice->addColumn('total_excl', Types::DECIMAL, ['precision' => 14, 'scale' => 2, 'default' => '0.00']);
        $invoice->addColumn('total_tax', Types::DECIMAL, ['precision' => 14, 'scale' => 2, 'default' => '0.00']);
        $invoice->addColumn('total_incl', Types::DECIMAL, ['precision' => 14, 'scale' => 2, 'default' => '0.00']);
        $invoice->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $invoice->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $invoice->setPrimaryKey(['id']);
        // Drafts carry NULL numbers, and unique indexes treat NULLs as
        // distinct — only real numbers are fenced.
        $invoice->addUniqueIndex(['company_id', 'number'], 'uniq_invoice_invoice_company_number');
        $invoice->addIndex(['company_id', 'status'], 'idx_invoice_invoice_company_status');
        $invoice->addIndex(['company_id', 'issued_on'], 'idx_invoice_invoice_company_issued');
        $invoice->addIndex(['company_id', 'thirdparty_id'], 'idx_invoice_invoice_company_thirdparty');
        $invoice->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        $invoice->addForeignKeyConstraint('thirdparty_thirdparty', ['thirdparty_id'], ['id'], ['onDelete' => 'RESTRICT']);

        $line = $schema->createTable('invoice_invoice_line');
        $line->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $line->addColumn('company_id', Types::INTEGER);
        $line->addColumn('invoice_id', Types::INTEGER);
        $line->addColumn('position', Types::INTEGER);
        $line->addColumn('label', Types::STRING, ['length' => 255]);
        $line->addColumn('quantity', Types::DECIMAL, ['precision' => 12, 'scale' => 2]);
        $line->addColumn('unit_price', Types::DECIMAL, ['precision' => 12, 'scale' => 2]);
        $line->addColumn('vat_rate', Types::DECIMAL, ['precision' => 5, 'scale' => 2]);
        $line->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $line->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $line->setPrimaryKey(['id']);
        $line->addIndex(['company_id', 'invoice_id', 'position'], 'idx_invoice_line_company_invoice');
        $line->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        // Deleting a draft takes its lines along.
        $line->addForeignKeyConstraint('invoice_invoice', ['invoice_id'], ['id'], ['onDelete' => 'CASCADE']);

        // The gap-free counter, one row per company and year, claimed with an
        // atomic upsert inside the validation transaction.
        $sequence = $schema->createTable('invoice_sequence');
        $sequence->addColumn('company_id', Types::INTEGER);
        $sequence->addColumn('year', Types::INTEGER);
        $sequence->addColumn('counter', Types::INTEGER);
        $sequence->setPrimaryKey(['company_id', 'year']);
        $sequence->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('invoice_invoice_line');
        $schema->dropTable('invoice_sequence');
        $schema->dropTable('invoice_invoice');
    }
}
