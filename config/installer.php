<?php

/*
 * Asistente de instalación web (/install). Se apaga solo al terminar: escribe
 * el archivo de bloqueo y desde entonces /install responde 404. Una instalación
 * existente (con Super Admin en la base) se detecta y se bloquea sola.
 */
return [
    // false lo desactiva por completo (servidores ya instalados por consola, pruebas)
    'enabled' => env('INSTALLER_ENABLED', true),

    'lock_file' => storage_path('app/installed.lock'),

    'min_php' => '8.2.0',

    'extensions' => ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo', 'curl', 'gd', 'zip'],

    // Relativas a la raíz del proyecto
    'writable' => ['storage', 'storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'],
];
