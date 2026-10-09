<?php

/*
|--------------------------------------------------------------------------
| Hash de contraseñas
|--------------------------------------------------------------------------
| Argon2id por defecto (resistente a GPU). Si el PHP del servidor no trae
| soporte Argon2, cae a bcrypt. Los hashes antiguos bcrypt se siguen
| aceptando ('verify' => false) y se migran solos en el siguiente login
| exitoso (LoginGuard::registerSuccess).
*/

return [

    'driver' => env('HASH_DRIVER', defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => false,
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => false,
    ],

    'rehash_on_login' => true,

];
