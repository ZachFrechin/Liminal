<?php

declare(strict_types=1);

// Merged into the shared catalogue after the libs' files: within one key the
// last contribution wins.
return [
    'invoice.permission.read' => 'See invoices',
    'invoice.permission.manage' => 'Manage invoices',

    'invoice.menu.invoices' => 'Invoices',

    'invoice.list.title' => 'Invoices',
    'invoice.list.create' => 'Create an invoice',
    'invoice.list.empty' => 'No invoice here yet.',
    'invoice.list.number' => 'Number',
    'invoice.list.party' => 'Third party',
    'invoice.list.issued' => 'Issued',
    'invoice.list.status' => 'Status',
    'invoice.list.total' => 'Total incl. VAT',

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
    'invoice.detail.pdf' => 'PDF',
    'invoice.detail.pdf_proforma' => 'PDF (proforma)',

    'invoice.pdf.title' => 'Invoice',
    'invoice.pdf.proforma' => 'Proforma',
    'invoice.pdf.issuer' => 'Issuer',
    'invoice.pdf.billed_to' => 'Billed to',
    'invoice.pdf.vat_number' => 'VAT',

    'invoice.line.label' => 'Description',
    'invoice.line.quantity' => 'Qty',
    'invoice.line.unit_price' => 'Unit price',
    'invoice.line.vat_rate' => 'VAT rate',
    'invoice.line.total' => 'Total excl.',
    'invoice.line.add' => 'Add line',
    'invoice.line.remove' => 'Remove',

    'invoice.detail.edit' => 'Edit',
    'invoice.detail.save' => 'Save',
    'invoice.detail.validate' => 'Validate',
    'invoice.detail.validate_hint' => 'Validation assigns the number, sets the issue date to today, and freezes the invoice for good.',
    'invoice.detail.danger' => 'Danger zone',
    'invoice.detail.delete' => 'Delete this draft',

    'invoice.create.title' => 'Create an invoice',
    'invoice.create.thirdparty' => 'Third party',
    'invoice.create.no_thirdparties' => 'Create a third party first: an invoice names the party it bills.',
    'invoice.create.issued_on' => 'Issue date',
    'invoice.create.due_on' => 'Due date',
    'invoice.create.submit' => 'Create the draft',

    'invoice.form.thirdparty_required' => 'Pick the third party this invoice bills.',
    'invoice.form.thirdparty_unknown' => 'That third party does not exist in this company.',
    'invoice.form.issued_on_invalid' => 'The issue date is not a valid date.',
    'invoice.form.due_on_invalid' => 'The due date is not a valid date.',
    'invoice.form.due_before_issue' => 'The due date cannot precede the issue date.',
    'invoice.form.label_required' => 'The line needs a description (255 characters at most).',
    'invoice.form.quantity_invalid' => 'The quantity must be a number up to 999999.99.',
    'invoice.form.unit_price_invalid' => 'The unit price must be a number up to 99999999.99.',
    'invoice.form.vat_rate_invalid' => 'The VAT rate must be between 0 and 100.',
    'invoice.form.immutable' => 'A validated invoice is immutable.',
    'invoice.form.needs_lines' => 'An invoice needs at least one line before validation.',
    'invoice.form.validated' => 'Invoice validated.',
    'invoice.veto.referenced' => 'This third party carries invoices and cannot be deleted.',

    'invoice.form.created' => 'Draft invoice created.',
    'invoice.form.updated' => 'Invoice updated.',
    'invoice.form.line_added' => 'Line added.',
    'invoice.form.line_removed' => 'Line removed.',
    'invoice.form.deleted' => 'Draft invoice deleted.',
];
