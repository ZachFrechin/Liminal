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
    'authentication.users.email' => 'Email',
    'authentication.users.name' => 'Name',
    'authentication.users.roles' => 'Roles in this company',

    'authentication.menu.account' => 'Account',
    'authentication.menu.users' => 'Users',

    'authentication.permission.user.manage' => 'Manage users',
];
