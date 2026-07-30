<?php

declare(strict_types=1);

// Merged into the shared catalogue after the libs' files: within one key the
// last contribution wins.
return [
    'invoice.permission.read' => 'See invoices',
    'invoice.permission.manage' => 'Manage invoices',

    'invoice.menu.invoices' => 'Invoices',

    'invoice.list.title' => 'Invoices',
    'invoice.list.search' => 'Search',
    'invoice.list.empty' => 'No invoice here yet.',
    'invoice.list.number' => 'Number',
    'invoice.list.party' => 'Third party',
    'invoice.list.issued' => 'Issued',
    'invoice.list.status' => 'Status',
    'invoice.list.total' => 'Total incl. VAT',
    'invoice.list.page_of' => 'Page %page% of %pages% (%total% total)',

    'invoice.status.draft' => 'Draft',
    'invoice.status.validated' => 'Validated',

    'invoice.detail.title' => 'Invoice',
    'invoice.detail.draft_title' => 'Draft invoice',
    'invoice.detail.issued' => 'Issued on',
    'invoice.detail.due' => 'Due on',
    'invoice.detail.lines' => 'Lines',
    'invoice.detail.no_lines' => 'No line yet.',
    'invoice.detail.totals' => 'Totals',
    'invoice.detail.total_excl' => 'Total excl. VAT',
    'invoice.detail.vat_at' => 'VAT at %rate% %',
    'invoice.detail.total_incl' => 'Total incl. VAT',
    'invoice.detail.back' => 'Back to invoices',

    'invoice.line.label' => 'Description',
    'invoice.line.quantity' => 'Qty',
    'invoice.line.unit_price' => 'Unit price',
    'invoice.line.vat_rate' => 'VAT rate',
    'invoice.line.total' => 'Total excl.',
];
