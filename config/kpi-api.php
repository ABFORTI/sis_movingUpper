<?php

use App\Kpis\EtiquetasImpresas;
use App\Kpis\NumerosSerieEscaneados;
use App\Kpis\ProductividadPorUsuario;
use App\Kpis\SkusRegistrados;
use App\Kpis\TarimasRegistradas;


return [

    'sistema' => [
        'clave' => env('KPI_API_SISTEMA_CLAVE', 'movingupper'), 
        'nombre' => env('KPI_API_SISTEMA_NOMBRE', 'MovingUpper — WMS Upper Logistics Qro'),
    ],

    'limite_por_minuto' => (int) env('KPI_API_LIMITE_POR_MINUTO', 60),

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
