<?php

declare(strict_types=1);

// The thirdparty module's catalogue; merged after the libs', last one winning.
return [
    'thirdparty.list.title' => 'Third parties',
    'thirdparty.list.create' => 'Create a third party',
    'thirdparty.list.empty' => 'No third party here yet.',
    'thirdparty.list.code' => 'Code',
    'thirdparty.list.name' => 'Name',
    'thirdparty.list.kind' => 'Kind',
    'thirdparty.list.status' => 'Status',
    'thirdparty.list.inactive' => 'inactive',

    // The archived/active pair the status filter offers; 'inactive' stays
    // the badge's word, which is what a ROW says rather than a choice.
    'thirdparty.state.active' => 'active',
    'thirdparty.state.archived' => 'archived',

    'thirdparty.kind.customer' => 'customer',
    'thirdparty.kind.supplier' => 'supplier',
    'thirdparty.kind.prospect' => 'prospect',

    'thirdparty.detail.title' => 'Third party',
    'thirdparty.detail.back' => 'Back to the list',
    'thirdparty.detail.edit' => 'Edit',
    'thirdparty.detail.save' => 'Save',
    'thirdparty.detail.danger' => 'Danger zone',
    'thirdparty.detail.delete' => 'Delete this third party',

    'thirdparty.create.title' => 'Create a third party',
    'thirdparty.create.submit' => 'Create',

    'thirdparty.field.code' => 'Code',
    'thirdparty.field.name' => 'Name',
    'thirdparty.field.alias' => 'Alias',
    'thirdparty.field.email' => 'Email',
    'thirdparty.field.phone' => 'Phone',
    'thirdparty.field.address' => 'Address',
    'thirdparty.field.zip' => 'Zip',
    'thirdparty.field.town' => 'Town',
    'thirdparty.field.country' => 'Country',
    'thirdparty.field.vat' => 'VAT number',
    'thirdparty.field.notes' => 'Notes',
    'thirdparty.field.active' => 'Active',

    'thirdparty.form.identity' => 'Identity',
    'thirdparty.form.contact' => 'Contact',
    'thirdparty.form.accounting' => 'Accounting',
    'thirdparty.form.code_invalid' => 'The code is required, 32 characters at most.',
    'thirdparty.form.name_required' => 'The name is required.',
    'thirdparty.form.email_invalid' => 'That email address is not valid.',
    'thirdparty.form.country_invalid' => 'The country is a two-letter code.',
    'thirdparty.form.code_taken' => 'A third party with that code already exists in this company.',
    'thirdparty.form.created' => 'Third party created.',
    'thirdparty.form.updated' => 'Third party updated.',
    'thirdparty.form.deleted' => 'Third party deleted.',

    'thirdparty.menu.thirdparties' => 'Third parties',

    'thirdparty.permission.read' => 'See third parties',
    'thirdparty.permission.manage' => 'Manage third parties',
];
