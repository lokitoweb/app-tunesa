<?php

/*
|--------------------------------------------------------------------------
| Regiones por país (lista de "Región" de las sedes)
|--------------------------------------------------------------------------
| La sede elige su región de esta lista (antes era texto libre y cada uno la
| escribía distinto). Otro país: agrega su código ISO con sus regiones. Una sede
| con un valor que no está en la lista lo conserva.
*/

return [
    'default_country' => env('APP_COUNTRY', 'PE'),

    'countries' => [
        // Perú: 24 departamentos + la Provincia Constitucional del Callao
        'PE' => [
            'Amazonas', 'Áncash', 'Apurímac', 'Arequipa', 'Ayacucho', 'Cajamarca', 'Callao', 'Cusco',
            'Huancavelica', 'Huánuco', 'Ica', 'Junín', 'La Libertad', 'Lambayeque', 'Lima', 'Loreto',
            'Madre de Dios', 'Moquegua', 'Pasco', 'Piura', 'Puno', 'San Martín', 'Tacna', 'Tumbes', 'Ucayali',
        ],
    ],
];
