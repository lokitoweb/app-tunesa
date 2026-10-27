<?php

return [
    // Tasa del IGV para las OC/OS con precios por ítem (cada orden guarda la tasa con la que se emitió)
    'igv_rate' => (float) env('FINANCE_IGV_RATE', 0.18),

    /*
     * Motivos de detracción (sisgere: tabla Tdetraccion, combo de FrmReq). Código SUNAT => [motivo, %].
     * Son los que usaba la empresa en el sistema anterior; si SUNAT cambia un %, se cambia aquí
     * (cada orden guarda el % con el que se emitió).
     */
    'detraction_reasons' => [
        '019' => ['Arrendamiento de bienes', 10],
        '020' => ['Mantenimiento y reparación de bienes muebles', 12],
        '021' => ['Movimiento de carga', 10],
        '022' => ['Otros servicios empresariales', 12],
        '026' => ['Servicio de transporte de personas', 10],
        '027' => ['Transporte de bienes de carga por vía terrestre', 4],
        '037' => ['Demás servicios gravados con el IGV', 12],
    ],
];
