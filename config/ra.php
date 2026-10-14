<?php

return [

    // First administrator, created by `php artisan db:seed` (skipped when the email is empty).
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'roles' => [
        'Admin' => '*',
        'Account Specialist' => [
            'remittances.upload',
            'remittances.view-own',
            'remittances.confirm',
            'remittances.delete',
            'files.set-visibility',
        ],
        'User' => ['files.view-public'],
    ],

    'permissions' => [
        'users.manage' => 'Create, edit, deactivate users and reset passwords',
        'roles.manage' => 'Edit what each role may do',
        'remittances.upload' => 'Upload documents for extraction',
        'remittances.view-own' => 'View own transactions',
        'remittances.view-all' => 'View every transaction',
        'remittances.confirm' => 'Review, edit and confirm own extractions',
        'remittances.delete' => 'Delete own transactions and their files',
        'files.set-visibility' => 'Mark own files public or private',
        'files.view-public' => 'View public files',
    ],

];
