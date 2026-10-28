<?php

return [
    // Tasa del IGV para las OC/OS con precios por ítem (cada orden guarda la tasa con la que se emitió)
    'igv_rate' => (float) env('FINANCE_IGV_RATE', 0.18),

    /*
     * Motivos de detracción (sisgere: tabla Tdetraccion, combo de FrmReq). Código SUNAT => [motivo, %].
     * Son los que usaba la empresa en el sistema anterior; si SUNAT cambia un %, se cambia aquí
     * (cada orden guarda el % con el que se emitió).
     */
    /*
     * Conceptos del Estado de Flujos de Efectivo, método directo (sisgere: FLUJOEFECT, mostrar_flujo).
     * Solo salidas: es lo que se paga desde Tesorería. Actividad => conceptos.
     */
    'cash_flow_concepts' => [
        'Actividades de operación' => [
            'Pago a proveedores de bienes y servicios', 'Pagos a y por cuenta de los empleados', 'Pago de impuestos a las ganancias',
            'Pago de regalías', 'Otros pagos de efectivo relativos a la actividad de operación',
        ],
        'Actividades de inversión' => [
            'Pagos por anticipos y préstamos concedidos a terceros', 'Pagos por préstamos a entidades relacionadas',
            'Pagos por compra de propiedades, planta y equipo', 'Desembolsos por obras en curso de propiedades, planta y equipo',
            'Pagos por compra de propiedades de inversión', 'Pagos por compra de activos intangibles', 'Pago por compra de otros activos de largo plazo',
            'Pagos por compra de instrumentos financieros de patrimonio o deuda de otras entidades', 'Pagos por contratos derivados (futuro, a término, opciones)',
            'Otros pagos de efectivo relativos a la actividad de inversión',
        ],
        'Actividades de financiación' => [
            'Pagos o amortización de préstamos a corto plazo', 'Pagos o amortización de préstamos a largo plazo',
            'Pagos de préstamos de entidades relacionadas', 'Pagos de pasivos por arrendamiento financiero', 'Pagos de intereses y rendimientos',
            'Pagos de dividendos', 'Pagos por recompra o rescate de acciones de la entidad', 'Otros pagos de efectivo relativos a la actividad de financiación',
        ],
    ],

    // Concepto que se pone solo, si nadie elige otro. Deben existir en cash_flow_concepts (lo revisa un test).
    'cash_flow_defaults' => [
        'advance' => 'Pagos por anticipos y préstamos concedidos a terceros',
        'payroll' => 'Pagos a y por cuenta de los empleados',
        // Arrendamientos, seguros, servicios… se pagan como proveedores; el préstamo y el leasing tienen el suyo
        'fixed_cost' => [
            'PRESTAMOS' => 'Pagos o amortización de préstamos a corto plazo',
            'LEASING'   => 'Pagos de pasivos por arrendamiento financiero',
            '*'         => 'Pago a proveedores de bienes y servicios',
        ],
    ],

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
