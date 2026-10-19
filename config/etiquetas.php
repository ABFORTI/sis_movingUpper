<?php

return [
    'dpi' => (int) env('ZEBRA_DPI', 203),
    'ancho_mm' => (float) env('ZEBRA_ANCHO_MM', 75),
    'alto_mm' => (float) env('ZEBRA_ALTO_MM', 50),

    'ancho_cabezal_mm' => (float) env('ZEBRA_ANCHO_CABEZAL_MM', 72),

    'margen_mm' => (float) env('ZEBRA_MARGEN_MM', 2),

    // Cuánto se sube el QR al imprimir (mm). Hoy solo la etiqueta de prueba lleva QR. El comando ^BQ de Zebra coloca el QR unos
    // puntos por debajo de su posición; la vista previa no se ve afectada.
    // Si en la etiqueta impresa el QR sigue bajo, aumentar; si queda alto, reducir.
    'ajuste_qr_mm' => (float) env('ZEBRA_AJUSTE_QR_MM', 2.5),

    // Ajuste fino vertical de todo el contenido (mm, ^LT). Solo si después de
    // calibrar el papel la etiqueta de prueba sigue corrida: negativo sube, positivo baja.
    'ajuste_superior_mm' => (float) env('ZEBRA_AJUSTE_SUPERIOR_MM', 0),

    'media' => env('ZEBRA_MEDIA', 'gap'),
];
