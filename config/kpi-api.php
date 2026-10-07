<?php

use App\Kpis\EtiquetasImpresas;
use App\Kpis\NumerosSerieEscaneados;
use App\Kpis\ProductividadPorUsuario;
use App\Kpis\SkusRegistrados;
use App\Kpis\TarimasRegistradas;

/*
 * API de KPIs (docs/kpi-api/ESTANDAR.md). Catálogo: docs/kpi-api/sistemas/movingupper.md
 */
return [

    'sistema' => [
        'clave' => env('KPI_API_SISTEMA_CLAVE', 'movingupper'),   // única en la empresa, kebab-case, inmutable
        'nombre' => env('KPI_API_SISTEMA_NOMBRE', 'MovingUpper — WMS Upper Logistics Qro'),
    ],

    'limite_por_minuto' => (int) env('KPI_API_LIMITE_POR_MINUTO', 60),

    // Publicado en v3 por acuerdo con el sistema central, no en v1 (ver movingupper.md).
    'versiones' => [
        'v3' => [
            NumerosSerieEscaneados::class,
            TarimasRegistradas::class,
            SkusRegistrados::class,
            EtiquetasImpresas::class,
            ProductividadPorUsuario::class,
        ],
    ],

];
