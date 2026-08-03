<?php

declare(strict_types=1);

// Merged into the shared catalogue after the libs' files: within one key the
// last contribution wins.
return [
    'order.permission.read' => 'See orders',
    'order.permission.manage' => 'Manage orders',

    'order.menu.orders' => 'Orders',

    'order.list.title' => 'Orders',
    'order.list.create' => 'New order',
    'order.list.empty' => 'No order here yet.',
    'order.list.number' => 'Number',
    'order.list.party' => 'Third party',
    'order.list.issued' => 'Issued',
    'order.list.status' => 'Status',
    'order.list.total' => 'Total incl. VAT',

    'order.status.draft' => 'Draft',
    'order.status.validated' => 'Validated',
    'order.status.invoiced' => 'Invoiced',

    'order.detail.title' => 'Order',
    'order.detail.draft_title' => 'Draft order',
    'order.detail.issued' => 'Issued on',
    'order.detail.wanted' => 'Wanted for',
    'order.detail.see_invoice' => 'See the invoice',
    'order.detail.lines' => 'Lines',
    'order.detail.no_lines' => 'No line yet.',
    'order.detail.totals' => 'Totals',
    'order.detail.total_excl' => 'Total excl. VAT',
    'order.detail.vat_at' => 'VAT at %rate% %',
    'order.detail.total_incl' => 'Total incl. VAT',
    'order.detail.back' => 'Back to orders',
    'order.detail.pdf' => 'PDF',
    'order.detail.pdf_proforma' => 'PDF (proforma)',

    'order.pdf.title' => 'Order',
    'order.pdf.proforma' => 'Proforma',
    'order.pdf.issuer' => 'Issuer',
    'order.pdf.ordered_by' => 'Ordered by',
    'order.pdf.vat_number' => 'VAT',

    'order.line.label' => 'Description',
    'order.line.quantity' => 'Qty',
    'order.line.unit_price' => 'Unit price',
    'order.line.vat_rate' => 'VAT rate',
    'order.line.total' => 'Total excl.',
    'order.line.add' => 'Add line',
    'order.line.remove' => 'Remove',

    'order.create.title' => 'New order',
    'order.create.thirdparty' => 'Third party',
    'order.create.no_thirdparties' => 'No active third party — create one first.',
    'order.create.issued_on' => 'Issue date',
    'order.create.wanted_on' => 'Wanted date',
    'order.create.submit' => 'Create the draft',

    'order.detail.edit' => 'Edit',
    'order.detail.save' => 'Save',
    'order.detail.validate' => 'Validate',
    'order.detail.validate_hint' => 'Validation freezes the order and mints its definitive number.',
    'order.detail.invoice' => 'Invoice this order',
    'order.detail.invoice_hint' => 'Creates a draft invoice carrying these lines — definitive once done.',
    'order.detail.danger' => 'Danger zone',
    'order.detail.delete' => 'Delete this draft',

    'order.form.thirdparty_required' => 'Pick a third party.',
    'order.form.thirdparty_unknown' => 'That third party does not exist in this company.',
    'order.form.issued_on_invalid' => 'The issue date is invalid.',
    'order.form.wanted_on_invalid' => 'The wanted date is invalid.',
    'order.form.wanted_before_issue' => 'The wanted date cannot precede the issue date.',
    'order.form.label_required' => 'The line needs a description (255 characters at most).',
    'order.form.quantity_invalid' => 'The quantity must be a positive amount below 1 000 000.',
    'order.form.unit_price_invalid' => 'The unit price must be a positive amount below 100 000 000.',
    'order.form.vat_rate_invalid' => 'The VAT rate must sit between 0 and 100.',
    'order.form.immutable' => 'This order left the draft state — it can no longer change.',
    'order.form.needs_lines' => 'An order needs at least one line before validation.',
    'order.form.validated' => 'Order validated.',
    'order.form.not_validated' => 'Only a validated order can be invoiced.',
    'order.form.already_invoiced' => 'This order has already been invoiced — the conversion is definitive.',
    'order.form.invoiced' => 'Draft invoice created from the order.',

    'order.veto.referenced' => 'This third party still has orders in this company — delete or reassign them first.',
    'order.veto.invoice_referenced' => 'This invoice realises a validated order — it cannot be deleted.',
    'order.form.created' => 'Draft order created.',
    'order.form.updated' => 'Order updated.',
    'order.form.line_added' => 'Line added.',
    'order.form.line_removed' => 'Line removed.',
    'order.form.deleted' => 'Draft order deleted.',
];
