<?php

namespace App\KpiApi;

use App\KpiApi\Contracts\Kpi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class KpiResponse {
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<int, array<string, mixed>> $datos
     * @param array<string, string[]>          $filtros
     * @param array<string, int>|null          $paginacion
     */
    public static function exito(
        Request $request,
        array $datos,
        ?Kpi $kpi = null,
        ?KpiQuery $consulta = null,
        ?array $paginacion = null,
        ?int $totalRegistros = null,
    ): JsonResponse {
        return self::construir($request, 200, [
            'exito' => true,
            'kpi' => $kpi ? ['clave' => $kpi->clave(), 'nombre' => $kpi->nombre(), 'unidad' => $kpi->unidad()] : null,
            'periodo' => $consulta?->periodoParaRespuesta(),
            'filtros' => (object) ($consulta?->filtros ?? []),
            'datos' => array_values($datos),
            'metadatos' => self::metadatos($request, $totalRegistros ?? count($datos), $paginacion),
            'errores' => [],
        ]);
    }

    /** @param array<int, array{codigo: string, mensaje: string, campo: ?string}> $errores */
    public static function error(Request $request, int $status, array $errores, array $headers = []): JsonResponse
    {
        return self::construir($request, $status, [
            'exito' => false,
            'kpi' => null,
            'periodo' => null,
            'filtros' => (object) [],
            'datos' => null,
            'metadatos' => self::metadatos($request, 0, null),
            'errores' => array_values($errores),
        ], $headers);
    }

    private static function construir(Request $request, int $status, array $cuerpo, array $headers = []): JsonResponse {
        $version = KpiApi::versionDe($request);

        $sobre = [
            'exito' => $cuerpo['exito'],
            'version_api' => $version,
            'sistema' => KpiApi::sistema(),
        ] + $cuerpo;

        return (new JsonResponse($sobre, $status, [], self::JSON_FLAGS))
            ->withHeaders($headers + [
                'X-Request-Id' => KpiApi::idPeticion($request),
                'X-Api-Version' => (string) $version,
                'X-Source-System' => KpiApi::sistema()['clave'],
                'Cache-Control' => 'no-store, private',
            ]);
    }

    private static function metadatos(Request $request, int $total, ?array $paginacion): array {
        return [
            'total_registros' => $total,
            'paginacion' => $paginacion,
            'generado_en' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'id_peticion' => KpiApi::idPeticion($request),
            'version_estandar' => KpiApi::VERSION_ESTANDAR,
        ];
    }
}
