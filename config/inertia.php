<?php

return [

    // Sin SSR: el panel es una SPA detrás de login
    'ssr' => ['enabled' => false],

    /*
    | Pruebas: assertInertia() comprueba que la pantalla exista en disco.
    | El nombre que envía el servidor es la ruta dentro de resources/js/Modules
    | (p. ej. "HR/Pages/Shifts/Index").
    */
    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths'         => [resource_path('js/Modules')],
        'page_extensions'    => ['vue'],
    ],

];
