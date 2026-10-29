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

    // Área del requerimiento (sisgere: combo fijo cbarea de FrmReq); se suman los departamentos de la empresa
    'requisition_areas' => ['ADM-ADMINISTRATIVOS', 'OPE-OPERACIONES', 'LOG-LOGISTICA', 'MAN-MANTENIMIENTO', 'VEN-VENTAS',
        'SIS-SISTEMAS', 'CON-CONTABILIDAD', 'CHO-CHOFERES'],

    // Tipo de cuenta del proveedor (sisgere: combo fijo de FrmRegProveedor)
    'supplier_account_types' => ['CTE. SOLES', 'CTE. DOLARES', 'CCI. SOLES', 'CCI. DOLARES'],

    // Rubros sugeridos (sisgere: combo de FrmRegProveedor); se suman a los ya usados por la empresa
    'supplier_categories' => [
        'ACCESORIOS PARA AUTOMOVILES', 'ACEITES LUBRICANTES', 'ACTIVIDADES DE ARQUI Y CIVIL', 'AGENTE DE AUTOMOVILES',
        'AIRE ACONDICIONADO', 'ALQUILER CAJABAMBA', 'ALQUILER DE MAQUINARIA', 'ALQUILER OFICINA', 'ARTEFACTOS DE OFICINA',
        'BRAZO LIMPIAPARABRISAS', 'CERTIFICADOS DE INTERNET', 'COMBUSTIBLE', 'COMPUTADORAS', 'CONCESIONARIO DE CAMIONES',
        'CONCRETO', 'CONSULTORIA DE SEGURIDAD PRIVADA', 'CONSULTORIA SOBRE ESTRUCTURA', 'CONTOMETROS TERMICOS',
        'DESODORIZANTE, JABON LIQUIDO, PINO', 'DIESEL', 'DOMINIO PAGINAS WEB', 'ELABORACION DE PROYECTO DE AREAS COMERCIALES',
        'ELECTRICISTA', 'ELECTRICO AUTOMOTRIZ', 'ELECTROVALBULA', 'EPP DE PERSONAL', 'EXTINTORES',
        'FABRICACION DE CUBIERTAS Y CAMARAS DE CAUCHO', 'FILTROS DE MANTENIMIENTO', 'FLORES', 'FORMATOS DE MANIFIESTOS TICKETS',
        'FOTOSHECK Y LANYARD', 'GPS', 'HERRAMIENTAS', 'IMPRENTA', 'IMPRESORAS', 'LEVANTAMIENTO TOPOGRAFICO', 'LLANTAS',
        'MANGUERAS Y CONEXIONES', 'MANTENIMIENTO Y PRODUCTOS DE COMERCIOS ESPECIALIZADOS', 'MANTENIMIENTO Y REPARACION DE ATUOMOTORES',
        'MATERIALES DE CONSTRUCCION', 'MATERIALES DE LIMPIEZA', 'MATERIALES ELECTRICOS', 'MECANICO', 'MOVIL MODERT',
        'MUEBLES ESTANTES', 'NOTARIA', 'OBRERO', 'OFICINA PLAZA NORTE', 'PARABRISAS', 'PRODUCTOS DE LIMPIEZA',
        'PROYECTOS COMERCIALES', 'PUERTAS VENTANAS ETC.', 'RADIADORES', 'REDES SOCIALES', 'RENOVACION SUNAT', 'REPARACION',
        'REPUESTO', 'REPUESTOS', 'REPUESTOS AUTOMOTRICES', 'REPUESTOS MANTENIMIENTOS', 'REPUESTOS MERCEDEZ',
        'REPUESTOS PARA AUTOMOVILES', 'REPUESTOS Y ACCESORIOS', 'REPUESTOS Y MANTENIMIENTOS', 'REPUESTOS Y REPARACION',
        'SEGURIDAD', 'SEGUROS', 'SERVICIO', 'SERVICIO DE ADMINISTRACION TRIBUTARIA', 'SERVICIO DE AGUA',
        'SERVICIO DE ALQUILER SPRITER', 'SERVICIO DE ANFITRIONAJE', 'SERVICIO DE ASESORIA INFORMATICA',
        'SERVICIO DE ENCOMIENDAS PUENTE PIEDRA', 'SERVICIO DE ENCOMIENDAS VIRU', 'SERVICIO DE ENLLANTE',
        'SERVICIO DE INTERNET Y LINEAS MOVILES', 'SERVICIO DE LAVADA Y EXTRALAVADO', 'SERVICIO DE LUZ ELECTRICA',
        'SERVICIO DE MANO DE OBRA', 'SERVICIO DE MANTENIMIENTO', 'SERVICIO DE MAQUINA VIRTUAL AMAZON', 'SERVICIO DE REPARACION',
        'SERVICIO SCANEO', 'SERVICIOS DE INTERNET Y LINEAS MOVILES', 'SERVICIOS DE LIMPIEZA', 'SERVICIOS DE MANTENIMIENTO',
        'SERVICIOS DE OBRA', 'SERVICIOS DEVOLUCION DE IMPUESTOS', 'SERVICIOS TERRAPUERTO', 'SOLUCIONES ELECTRICAS',
        'TALLER MECANICO', 'TALONARIOS TIKETS', 'TANSPORTE DE EXCAVADORA', 'TEXTILERIA', 'TIENDA ELECTRONICA', 'TRANSPORTE',
        'TRANSPORTE DE CARGA POR CARRETERA', 'TUBERIAS', 'UREA LIQUIDA AUTOMOTRIZ', 'UTILES DE OFICINA', 'VALIDACION DE DATOS',
        'VEHICULOS Y REPUESTOS', 'VIATICOS CONDUCTOR CHAO',
    ],
];
