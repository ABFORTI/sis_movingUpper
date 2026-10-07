<?php

return [
    'dpi' => (int) env('ZEBRA_DPI', 203),
    'ancho_mm' => (float) env('ZEBRA_ANCHO_MM', 75),
    'alto_mm' => (float) env('ZEBRA_ALTO_MM', 50),

    'ancho_cabezal_mm' => (float) env('ZEBRA_ANCHO_CABEZAL_MM', 72),

    'margen_mm' => (float) env('ZEBRA_MARGEN_MM', 2),

    // Cuánto se sube el QR al imprimir (mm). El comando ^BQ de Zebra coloca el QR unos
    // puntos por debajo de su posición; la vista previa no se ve afectada.
    // Si en la etiqueta impresa el QR sigue bajo, aumentar; si queda alto, reducir.
    'ajuste_qr_mm' => (float) env('ZEBRA_AJUSTE_QR_MM', 2.5),

    'media' => env('ZEBRA_MEDIA', 'gap'),

    'filas_por_etiqueta' => (int) env('ZEBRA_FILAS_POR_ETIQUETA', 9),

    'logos' => [
        'izquierdo' => public_path('images/upper_logo1.png'),
        'derecho' => public_path('images/Euroquip4.png'),
    ],
];
