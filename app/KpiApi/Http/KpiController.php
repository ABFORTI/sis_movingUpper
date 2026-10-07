<?php

namespace App\KpiApi\Http;

use App\Http\Controllers\Controller;
use App\KpiApi\Exceptions\KpiApiException;
use App\KpiApi\KpiApi;
use App\KpiApi\KpiQueryParser;
use App\KpiApi\KpiRegistry;
use App\KpiApi\KpiResponse;
use App\KpiApi\Periodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class KpiController extends Controller {
    public function __construct(private readonly KpiRegistry $registry)
    {
    }

    public function sistema(Request $request): JsonResponse {
        $version = KpiApi::versionDe($request);

        return KpiResponse::exito($request, [[
            'clave' => KpiApi::sistema()['clave'],
            'nombre' => KpiApi::sistema()['nombre'],
            'version_api' => $version,
            'versiones_disponibles' => array_keys(config('kpi-api.versiones', [])),
            'version_estandar' => KpiApi::VERSION_ESTANDAR,
            'zona_horaria' => config('app.timezone'),
            'total_kpis' => count($this->registry->deVersion($version)),
            'estado' => 'operativo',
        ]]);
    }

    public function catalogo(Request $request): JsonResponse {
        $version = KpiApi::versionDe($request);

        $datos = array_map(
            fn ($kpi) => KpiRegistry::definicion($kpi, $version),
            array_values($this->registry->deVersion($version))
        );

        return KpiResponse::exito($request, $datos);
    }

    public function mostrar(Request $request, string $clave): JsonResponse {
        $version = KpiApi::versionDe($request);
        $kpi = $this->registry->buscar($version, $clave);

        if (!$kpi) {
            throw KpiApiException::uno(404, KpiApiException::KPI_NO_ENCONTRADO,
                "El KPI {$clave} no existe en {$version}. Consulte GET /api/{$version}/kpis.", 'clave');
        }

        $consulta = KpiQueryParser::parse($request, $kpi);
        $datos = $kpi->calcular($consulta);
        $total = count($datos);
        $paginacion = null;

        if ($kpi->paginable()) {
            $datos = array_slice($datos, ($consulta->pagina - 1) * $consulta->porPagina, $consulta->porPagina);
            $paginacion = [
                'pagina' => $consulta->pagina,
                'por_pagina' => $consulta->porPagina,
                'total_paginas' => max(1, (int) ceil($total / $consulta->porPagina)),
            ];
        }

        return KpiResponse::exito($request, $datos, $kpi, $consulta, $paginacion, $total);
    }
}
