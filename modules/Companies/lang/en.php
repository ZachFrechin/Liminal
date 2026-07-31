<?php

declare(strict_types=1);

// The companies module's catalogue; merged after the libs', last one winning.
return [
    'companies.list.title' => 'Companies',
    'companies.list.create' => 'Create a company',
    'companies.list.code' => 'Code',
    'companies.list.name' => 'Name',
    'companies.list.created' => 'Created',

    'companies.create.title' => 'Create a company',
    'companies.create.code' => 'Code',
    'companies.create.code_hint' => 'Uppercase letters, digits and underscores; the code never changes afterwards.',
    'companies.create.name' => 'Name',
    'companies.create.submit' => 'Create',

    'companies.company.title' => 'Company',
    'companies.company.name' => 'Name',
    'companies.company.save' => 'Save',
    'companies.company.invalid' => 'The company needs a valid code and a name.',
    'companies.company.code_taken' => 'A company with that code already exists.',
    'companies.company.name_required' => 'The name cannot be empty.',
    'companies.company.created' => 'Company created, with every installed module enabled for it.',
    'companies.company.created_with_missing' => 'Company created — but some declared modules are not installed. See the module list below; the remedy is `module:install`.',
    'companies.company.renamed' => 'Company renamed.',
    'companies.company.back' => 'Back to companies',
    'companies.company.identity' => 'Identity',
    'companies.company.identity_hint' => 'What legal documents print about this company — every field is optional, absent lines simply do not print.',
    'companies.company.address' => 'Address',
    'companies.company.zip' => 'Postal code',
    'companies.company.town' => 'Town',
    'companies.company.country' => 'Country code',
    'companies.company.vat_number' => 'VAT number',
    'companies.company.registration' => 'Registration',
    'companies.company.legal_mentions' => 'Legal mentions',
    'companies.company.legal_mentions_hint' => 'Free text printed at the bottom of documents: payment terms, late penalties, recovery indemnity…',
    'companies.company.identity_saved' => 'Identity saved.',
    'companies.company.modules' => 'Modules for this company',
    'companies.company.module' => 'Module',
    'companies.company.module_state' => 'State',
    'companies.company.module_enabled' => 'enabled',
    'companies.company.module_disabled' => 'disabled',
    'companies.company.module_not_installed' => 'NOT INSTALLED — run module:install, then enable it here',

    'companies.menu.companies' => 'Companies',

    'companies.permission.company.manage' => 'Manage companies',
];
