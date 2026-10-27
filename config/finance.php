<?php

return [
    // Tasa del IGV (Perú: 18 %). Se guarda en cada OC/OS al emitirla, así que cambiarla no altera
    // las órdenes ya emitidas.
    'igv_rate' => (float) env('FINANCE_IGV_RATE', 0.18),
];
