<?php

declare(strict_types=1);

// Contributed after the rendering lib's catalogue, so these keys win any
// collision — the same last-wins layering the container applies.
return [
    'authentication.login.title' => 'Sign in',
    'authentication.login.email' => 'Email',
    'authentication.login.password' => 'Password',
    'authentication.login.submit' => 'Sign in',
    // Deliberately identical for an unknown email and a wrong password: telling
    // the two apart is an account-enumeration oracle.
    'authentication.login.refused' => 'Those credentials were not accepted.',
    'authentication.login.throttled' => 'Too many attempts. Please wait before trying again.',
    'authentication.login.welcome' => 'Welcome back.',

    'authentication.account.title' => 'Your account',
    'authentication.account.signed_in_as' => 'Signed in as %name% (%email%).',
    'authentication.account.companies' => 'Companies you can reach',
    'authentication.account.current' => 'current',
    'authentication.account.switch' => 'Work in this company',
    'authentication.account.sign_out' => 'Sign out',

    'authentication.switch.done' => 'Working company switched.',
    'authentication.switch.refused' => 'That company is not yours to work in.',

    'authentication.logout.done' => 'You have been signed out.',

    'authentication.users.title' => 'Users',
    'authentication.users.create' => 'Create a user',
    'authentication.users.email' => 'Email',
    'authentication.users.name' => 'Name',
    'authentication.users.roles' => 'Roles in this company',
    'authentication.users.status' => 'Status',
    'authentication.users.inactive' => 'inactive',
    // Zero grants anywhere = zero accessible companies = cannot sign in.
    'authentication.users.no_access' => 'no access',

    'authentication.user.title' => 'User',
    'authentication.user.create_title' => 'Create a user',
    'authentication.user.create_submit' => 'Create',
    'authentication.user.email' => 'Email',
    'authentication.user.display_name' => 'Display name',
    'authentication.user.invalid_email' => 'That email address is not valid.',
    'authentication.user.email_taken' => 'A user with that email already exists.',
    'authentication.user.name_required' => 'The display name cannot be empty.',
    'authentication.user.created_title' => 'User created',
    'authentication.user.password_for' => 'The initial password for %email% is:',
    'authentication.user.password_once' => 'Copy it now — it is shown only this once and never stored in the clear.',
    'authentication.user.back_to_user' => 'Go to the user',
    'authentication.user.active' => 'Active',
    'authentication.user.save' => 'Save',
    'authentication.user.updated' => 'User updated.',
    'authentication.user.self_note' => 'You cannot deactivate your own account.',
    'authentication.user.self_deactivation' => 'You cannot deactivate your own account.',
    'authentication.user.self_deletion' => 'You cannot delete your own account.',
    'authentication.user.deleted' => 'User deleted.',
    'authentication.user.grants' => 'Roles per company',
    'authentication.user.grant_company' => 'Company',
    'authentication.user.grant_role' => 'Role',
    'authentication.user.no_grants' => 'No role in any company — this user cannot sign in.',
    'authentication.user.danger' => 'Danger zone',
    'authentication.user.delete' => 'Delete this user',

    'authentication.menu.account' => 'Account',
    'authentication.menu.users' => 'Users',

    'authentication.permission.user.manage' => 'Manage users',
];
