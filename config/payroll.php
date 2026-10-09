<?php

/*
|--------------------------------------------------------------------------
| Catálogos de planilla (app móvil y panel)
|--------------------------------------------------------------------------
| La app muestra estas listas como sugerencias; el colaborador puede
| escribir otro banco o fondo si el suyo no aparece.
*/

return [
    'banks' => array_filter(array_map('trim', explode(',', env('PAYROLL_BANKS',
        'BCP,BBVA,Interbank,Scotiabank,Banco de la Nación,BanBif,Banco Pichincha,Caja Arequipa,Caja Huancayo,Caja Piura'
    )))),

    'pension_funds' => array_filter(array_map('trim', explode(',', env('PAYROLL_PENSION_FUNDS',
        'AFP Integra,AFP Prima,AFP Profuturo,AFP Habitat'
    )))),
];
