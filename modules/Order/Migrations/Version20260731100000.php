<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Liminal\Lib\Database\Migration\Migration;

/**
 * The order tables — the second document, the invoice precedent verbatim,
 * plus the conversion pointer: invoice_id is ON DELETE RESTRICT because a
 * converted order must never dangle; the invoice.deletion.veto answers the
 * same case politely before the database has to.
 */
final class Version20260731100000 extends Migration
{
    public function getDescription(): string
    {
        return 'Create the order, order line and numbering sequence tables';
    }

    public function up(Schema $schema): void
    {
        // Three referenced tables, three other namespaces (Database lib,
        // thirdparty module, invoice module). The full migrate orders
        // contributions correctly, but module:install plans THIS namespace
        // alone — abortIf, never skipIf.
        $this->abortIf(
            !$schema->hasTable('core_company'),
            'core_company is missing: run "liminal migrate" before installing this module.',
        );
        $this->abortIf(
            !$schema->hasTable('thirdparty_thirdparty'),
            'thirdparty_thirdparty is missing: the order module depends on the thirdparty module — install it first.',
        );
        $this->abortIf(
            !$schema->hasTable('invoice_invoice'),
            'invoice_invoice is missing: the order module depends on the invoice module — install it first.',
        );

        $order = $schema->createTable('order_order');
        $order->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $order->addColumn('company_id', Types::INTEGER);
        $order->addColumn('thirdparty_id', Types::INTEGER);
        $order->addColumn('status', Types::STRING, ['length' => 16]);
        $order->addColumn('number', Types::STRING, ['length' => 32, 'notnull' => false]);
        $order->addColumn('issued_on', Types::DATE_IMMUTABLE);
        $order->addColumn('wanted_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $order->addColumn('invoice_id', Types::INTEGER, ['notnull' => false]);
        $order->addColumn('invoiced_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $order->addColumn('total_excl', Types::DECIMAL, ['precision' => 14, 'scale' => 2, 'default' => '0.00']);
        $order->addColumn('total_tax', Types::DECIMAL, ['precision' => 14, 'scale' => 2, 'default' => '0.00']);
        $order->addColumn('total_incl', Types::DECIMAL, ['precision' => 14, 'scale' => 2, 'default' => '0.00']);
        $order->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $order->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $order->setPrimaryKey(['id']);
        // Drafts carry NULL numbers, and unique indexes treat NULLs as
        // distinct — only real numbers are fenced.
        $order->addUniqueIndex(['company_id', 'number'], 'uniq_order_order_company_number');
        $order->addIndex(['company_id', 'status'], 'idx_order_order_company_status');
        $order->addIndex(['company_id', 'issued_on'], 'idx_order_order_company_issued');
        $order->addIndex(['company_id', 'thirdparty_id'], 'idx_order_order_company_thirdparty');
        $order->addIndex(['company_id', 'invoice_id'], 'idx_order_order_company_invoice');
        $order->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        $order->addForeignKeyConstraint('thirdparty_thirdparty', ['thirdparty_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $order->addForeignKeyConstraint('invoice_invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT']);

        $line = $schema->createTable('order_order_line');
        $line->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $line->addColumn('company_id', Types::INTEGER);
        $line->addColumn('order_id', Types::INTEGER);
        $line->addColumn('position', Types::INTEGER);
        $line->addColumn('label', Types::STRING, ['length' => 255]);
        $line->addColumn('quantity', Types::DECIMAL, ['precision' => 12, 'scale' => 2]);
        $line->addColumn('unit_price', Types::DECIMAL, ['precision' => 12, 'scale' => 2]);
        $line->addColumn('vat_rate', Types::DECIMAL, ['precision' => 5, 'scale' => 2]);
        $line->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $line->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $line->setPrimaryKey(['id']);
        $line->addIndex(['company_id', 'order_id', 'position'], 'idx_order_line_company_order');
        $line->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        // Deleting a draft takes its lines along.
        $line->addForeignKeyConstraint('order_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE']);

        // The gap-free counter, one row per company and year, claimed by the
        // lib's YearlySequence inside the validation transaction.
        $sequence = $schema->createTable('order_sequence');
        $sequence->addColumn('company_id', Types::INTEGER);
        $sequence->addColumn('year', Types::INTEGER);
        $sequence->addColumn('counter', Types::INTEGER);
        $sequence->setPrimaryKey(['company_id', 'year']);
        $sequence->addForeignKeyConstraint('core_company', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('order_order_line');
        $schema->dropTable('order_sequence');
        $schema->dropTable('order_order');
    }
}
