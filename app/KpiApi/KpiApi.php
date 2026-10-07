<?php

namespace App\KpiApi;

use App\KpiApi\Exceptions\ExceptionRenderer;
use App\KpiApi\Exceptions\KpiApiException;
use App\KpiApi\Http\ForzarRespuestaJson;
use App\KpiApi\Http\KpiController;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;


final class KpiApi {
    public const VERSION_ESTANDAR = '1.0';
    public const ABILITY = 'kpis:read';
    public const PARAM_FECHA_INICIO = 'fecha_inicio';
    public const PARAM_FECHA_FIN = 'fecha_fin';
    public const PARAM_AGRUPACION = 'agrupacion';
    public const PARAM_PAGINA = 'pagina';
    public const PARAM_POR_PAGINA = 'por_pagina';
    public const FILTROS_GENERALES = ['usuario', 'area', 'producto', 'cliente', 'turno'];

    public const MAX_VALORES_POR_FILTRO = 50;
    public const RANGO_MAXIMO_DIAS = 1830;
    public const MAX_PUNTOS_SERIE = 400;
    public const POR_PAGINA_DEFAULT = 50;
    public const POR_PAGINA_MAX = 500;
    public const MESES_PERIODO_DEFAULT = 12;

    public static function rutas(): void {
        foreach (array_keys(config('kpi-api.versiones', [])) as $version) {
            Route::prefix($version)
                ->name("kpi-api.{$version}.")
                ->middleware(['auth:sanctum', 'abilities:' . self::ABILITY, 'throttle:kpi-api'])
                ->group(function () {
                    Route::get('/sistema', [KpiController::class, 'sistema'])->name('sistema');
                    Route::get('/kpis', [KpiController::class, 'catalogo'])->name('catalogo');
                    Route::get('/kpis/{clave}', [KpiController::class, 'mostrar'])
                        ->where('clave', '[a-z0-9]+(?:-[a-z0-9]+)*')
                        ->name('mostrar');
                });
        }
    }

    public static function middleware(Middleware $middleware): void {
        $middleware->prependToGroup('api', ForzarRespuestaJson::class);
    }

    public static function excepciones(Exceptions $exceptions): void {
        $exceptions->dontReport(KpiApiException::class);

        $exceptions->render(function (\Throwable $e, Request $request) {
            return self::atiende($request) ? ExceptionRenderer::render($e, $request) : null;
        });

        $exceptions->context(function () {
            $request = request();

            return $request instanceof Request && self::atiende($request)
                ? ['id_peticion' => self::idPeticion($request)]
                : [];
        });
    }

    public static function atiende(Request $request): bool {
        return self::versionDe($request) !== null;
    }

    public static function versionDe(Request $request): ?string {
        foreach (array_keys(config('kpi-api.versiones', [])) as $version) {
            if ($request->is("api/{$version}", "api/{$version}/*")) {
                return $version;
            }
        }

        return null;
    }

    public static function idPeticion(Request $request): string {
        if ($id = $request->attributes->get('kpi_api.id_peticion')) {
            return $id;
        }

        $entrante = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $entrante) ? $entrante : (string) Str::uuid();
        $request->attributes->set('kpi_api.id_peticion', $id);

        return $id;
    }

    /** @return array{clave: string, nombre: string, entorno: string} */
    public static function sistema(): array {
        return [
            'clave' => (string) config('kpi-api.sistema.clave'),
            'nombre' => (string) config('kpi-api.sistema.nombre'),
            'entorno' => (string) config('app.env'),
        ];
    }
}
