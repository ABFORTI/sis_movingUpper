<?php

return [
    'dpi' => (int) env('ZEBRA_DPI', 203),
    'ancho_mm' => (float) env('ZEBRA_ANCHO_MM', 75),
    'alto_mm' => (float) env('ZEBRA_ALTO_MM', 50),
    'ancho_cabezal_mm' => (float) env('ZEBRA_ANCHO_CABEZAL_MM', 72),
    'margen_mm' => (float) env('ZEBRA_MARGEN_MM', 2),
    'ajuste_qr_mm' => (float) env('ZEBRA_AJUSTE_QR_MM', 2.5),
    'ajuste_superior_mm' => (float) env('ZEBRA_AJUSTE_SUPERIOR_MM', 0),
    'media' => env('ZEBRA_MEDIA', 'gap'),
];
