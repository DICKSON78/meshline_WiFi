<?php

return [
    'driver' => env('HASH_DRIVER', 'bcrypt'),

    /*
     * The legacy Node/bcryptjs hashes use the "$2a$" prefix. PHP's
     * password_verify() understands them, but Laravel's strict algorithm check
     * rejects them, so verification is intentionally left permissive while new
     * hashes are still created with bcrypt.
     */
    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => false,
    ],

    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
        'verify' => true,
    ],

    'rehash_on_login' => true,
];
