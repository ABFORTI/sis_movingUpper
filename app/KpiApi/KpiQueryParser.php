<?php

namespace App\KpiApi;

use App\KpiApi\Contracts\Kpi;
use App\KpiApi\Exceptions\KpiApiException as E;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class KpiQueryParser {
    private const MENSAJES = [
        'date_format' => 'El parámetro :attribute debe tener el formato YYYY-MM-DD.',
        'in' => 'El valor de :attribute no es válido. Valores permitidos: :values.',
        'integer' => 'El parámetro :attribute debe ser un número entero.',
        'numeric' => 'El parámetro :attribute debe ser numérico.',
        'string' => 'El parámetro :attribute debe enviarse una sola vez como texto.',
        'boolean' => 'El parámetro :attribute debe ser true, false, 1 o 0.',
        'required' => 'El parámetro :attribute es obligatorio.',
        'min' => 'El parámetro :attribute debe ser al menos :min.',
        'max' => 'El parámetro :attribute no debe ser mayor a :max.',
        'regex' => 'El formato de :attribute no es válido.',
    ];

    public static function parse(Request $request, Kpi $kpi): KpiQuery {
        $entrada = $request->query();
        $parametrosKpi = $kpi->parametros();
        $filtrosKpi = array_keys($kpi->filtros());

        self::rechazarDesconocidos($entrada, $kpi, $parametrosKpi, $filtrosKpi);

        $reglas = [
            KpiApi::PARAM_FECHA_INICIO => ['sometimes', 'string', 'date_format:Y-m-d'],
            KpiApi::PARAM_FECHA_FIN => ['sometimes', 'string', 'date_format:Y-m-d'],
            KpiApi::PARAM_AGRUPACION => ['sometimes', 'string', 'in:' . implode(',', $kpi->agrupaciones())],
        ];
        foreach ($filtrosKpi as $filtro) {
            $reglas[$filtro] = ['sometimes', 'string', 'max:5000', 'regex:/^[^,]{1,100}(,[^,]{1,100})*$/'];
        }
        if ($kpi->paginable()) {
            $reglas[KpiApi::PARAM_PAGINA] = ['sometimes', 'integer', 'min:1'];
            $reglas[KpiApi::PARAM_POR_PAGINA] = ['sometimes', 'integer', 'min:1', 'max:' . KpiApi::POR_PAGINA_MAX];
        }
        foreach ($parametrosKpi as $nombre => $p) {
            $reglas[$nombre] = array_merge(
                [($p['requerido'] ?? false) ? 'required' : 'sometimes'],
                self::reglasDeTipo($p['tipo']),
                $p['reglas'] ?? []
            );
        }

        $validador = Validator::make($entrada, $reglas, self::MENSAJES);
        if ($validador->fails()) {
            $errores = [];
            foreach ($validador->errors()->messages() as $campo => $mensajes) {
                $errores[] = E::error(E::PARAMETRO_INVALIDO, $mensajes[0], $campo);
            }
            throw new E(422, $errores);
        }

        [$inicio, $fin] = self::resolverPeriodo($entrada);
        $agrupacion = $entrada[KpiApi::PARAM_AGRUPACION] ?? $kpi->agrupacionPorDefecto();

        if (Periodo::contarCubetas($inicio, $fin, $agrupacion) > KpiApi::MAX_PUNTOS_SERIE) {
            throw E::uno(422, E::RANGO_FECHAS_INVALIDO, sprintf(
                'El periodo genera más de %d puntos con agrupacion=%s. Use una agrupación mayor o un rango menor.',
                KpiApi::MAX_PUNTOS_SERIE,
                $agrupacion
            ), KpiApi::PARAM_AGRUPACION);
        }

        $filtros = [];
        foreach ($filtrosKpi as $filtro) {
            if (isset($entrada[$filtro])) {
                $valores = array_values(array_unique(array_map('trim', explode(',', $entrada[$filtro]))));
                if (count($valores) > KpiApi::MAX_VALORES_POR_FILTRO) {
                    throw E::uno(422, E::PARAMETRO_INVALIDO, 'Un filtro admite como máximo ' . KpiApi::MAX_VALORES_POR_FILTRO . ' valores.', $filtro);
                }
                $filtros[$filtro] = $valores;
            }
        }

        $parametros = [];
        foreach ($parametrosKpi as $nombre => $p) {
            $parametros[$nombre] = array_key_exists($nombre, $entrada)
                ? self::convertir($entrada[$nombre], $p['tipo'])
                : ($p['default'] ?? null);
        }

        return new KpiQuery(
            fechaInicio: $inicio,
            fechaFin: $fin,
            agrupacion: $agrupacion,
            filtros: $filtros,
            parametros: $parametros,
            pagina: $kpi->paginable() ? (int) ($entrada[KpiApi::PARAM_PAGINA] ?? 1) : null,
            porPagina: $kpi->paginable() ? (int) ($entrada[KpiApi::PARAM_POR_PAGINA] ?? KpiApi::POR_PAGINA_DEFAULT) : null,
        );
    }

    private static function rechazarDesconocidos(array $entrada, Kpi $kpi, array $parametrosKpi, array $filtrosKpi): void
    {
        $permitidos = array_merge(
            [KpiApi::PARAM_FECHA_INICIO, KpiApi::PARAM_FECHA_FIN, KpiApi::PARAM_AGRUPACION],
            $filtrosKpi,
            array_keys($parametrosKpi),
            $kpi->paginable() ? [KpiApi::PARAM_PAGINA, KpiApi::PARAM_POR_PAGINA] : []
        );

        $errores = [];
        foreach (array_keys($entrada) as $nombre) {
            if (in_array($nombre, $permitidos, true)) {
                continue;
            }

            $errores[] = in_array($nombre, KpiApi::FILTROS_GENERALES, true)
                ? E::error(E::FILTRO_NO_SOPORTADO, "El KPI {$kpi->clave()} no soporta el filtro {$nombre}.", $nombre)
                : E::error(E::PARAMETRO_DESCONOCIDO, "El parámetro {$nombre} no existe para el KPI {$kpi->clave()}.", $nombre);
        }

        if ($errores) {
            throw new E(422, $errores);
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function resolverPeriodo(array $entrada): array {
        $zona = config('app.timezone');
        $hoy = CarbonImmutable::now($zona);

        $fin = isset($entrada[KpiApi::PARAM_FECHA_FIN])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $entrada[KpiApi::PARAM_FECHA_FIN], $zona)
            : $hoy;

        $inicio = isset($entrada[KpiApi::PARAM_FECHA_INICIO])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $entrada[KpiApi::PARAM_FECHA_INICIO], $zona)
            : $fin->subMonthsNoOverflow(KpiApi::MESES_PERIODO_DEFAULT - 1)->startOfMonth();

        $inicio = $inicio->startOfDay();
        $fin = $fin->endOfDay();

        if ($inicio->gt($fin)) {
            throw E::uno(422, E::RANGO_FECHAS_INVALIDO, 'fecha_inicio no puede ser posterior a fecha_fin.', KpiApi::PARAM_FECHA_INICIO);
        }
        if ($inicio->diffInDays($fin) > KpiApi::RANGO_MAXIMO_DIAS) {
            throw E::uno(422, E::RANGO_FECHAS_INVALIDO, 'El rango de fechas no puede exceder ' . KpiApi::RANGO_MAXIMO_DIAS . ' días.', KpiApi::PARAM_FECHA_FIN);
        }

        return [$inicio, $fin];
    }

    private static function reglasDeTipo(string $tipo): array {
        return match ($tipo) {
            'integer' => ['integer'],
            'number' => ['numeric'],
            'boolean' => ['in:true,false,1,0'],
            'fecha' => ['string', 'date_format:Y-m-d'],
            default => ['string', 'max:255'],
        };
    }

    private static function convertir(mixed $valor, string $tipo): mixed{
        return match ($tipo) {
            'integer' => (int) $valor,
            'number' => (float) $valor,
            'boolean' => in_array($valor, ['true', '1'], true),
            default => (string) $valor,
        };
    }
}
