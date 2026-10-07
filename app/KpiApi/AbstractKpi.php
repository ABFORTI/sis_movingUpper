<?php

namespace App\KpiApi;

use App\KpiApi\Contracts\Kpi;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;


abstract class AbstractKpi implements Kpi {
    public function agrupaciones(): array {
        return [Periodo::TOTAL];
    }

    public function agrupacionPorDefecto(): string {
        return $this->agrupaciones()[0];
    }

    public function filtros(): array {
        return [];
    }

    public function parametros(): array {
        return [];
    }

    public function paginable(): bool {
        return false;
    }

    /**
     *
     * @param iterable<mixed>                          $registros
     * @param Closure(mixed): ?CarbonInterface        $fechaDe   fecha que ubica al registro en su cubeta
     * @param Closure(Collection): array<string,mixed> $calcular  valores del renglón para un grupo
     */
    protected function agruparPorPeriodo(KpiQuery $consulta, iterable $registros, Closure $fechaDe, Closure $calcular): array
    {
        $registros = Collection::make($registros);

        if ($consulta->agrupacion === Periodo::TOTAL) {
            return [$calcular($registros)];
        }

        $zona = $consulta->fechaInicio->getTimezone();
        $grupos = $registros->groupBy(function ($registro) use ($fechaDe, $consulta, $zona) {
            $fecha = $fechaDe($registro);

            return $fecha ? Periodo::etiqueta($fecha->copy()->setTimezone($zona), $consulta->agrupacion) : '';
        });

        return array_values(array_map(
            fn (array $cubeta) => $cubeta + $calcular($grupos->get($cubeta['periodo'], new Collection())),
            Periodo::cubetas($consulta->fechaInicio, $consulta->fechaFin, $consulta->agrupacion)
        ));
    }

    protected function porcentaje(int|float $parte, int|float $total): ?float
    {
        return $total == 0 ? null : round(($parte / $total) * 100, 1);
    }
}
