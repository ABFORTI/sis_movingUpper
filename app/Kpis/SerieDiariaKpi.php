<?php

namespace App\Kpis;

use App\KpiApi\KpiQuery;
use App\KpiApi\Periodo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;


abstract class SerieDiariaKpi extends MovingUpperKpi {
    public const CAMPO_ANTERIOR = 'total_periodo_anterior';
    public const CAMPO_VARIACION = 'porcentaje_variacion';

    public function agrupaciones(): array {
        return [Periodo::MES, Periodo::DIA, Periodo::SEMANA, Periodo::TRIMESTRE, Periodo::ANIO, Periodo::TOTAL];
    }

    /** @return array<string, array{tipo: string, descripcion: string}> */
    abstract protected function camposPropios(): array;


    abstract protected function campoComparado(): string;


    abstract protected function diario(KpiQuery $consulta, CarbonImmutable $desde): Collection;

    /** @return array<string, mixed> valores de camposPropios() para un grupo de filas diarias */
    abstract protected function valores(Collection $filas): array;

    public function campos(): array {
        $comparado = $this->campoComparado();

        return $this->camposPropios() + [
            self::CAMPO_ANTERIOR => [
                'tipo' => 'integer',
                'descripcion' => "{$comparado} en el mismo tramo del periodo inmediato anterior.",
            ],
            self::CAMPO_VARIACION => [
                'tipo' => 'number|null',
                'descripcion' => "({$comparado} − total_periodo_anterior) / total_periodo_anterior × 100, con 1 decimal. Puede ser negativo o mayor a 100; null si total_periodo_anterior es 0.",
            ],
        ];
    }

    public function calcular(KpiQuery $consulta): array {
        $filas = $this->diario($consulta, $this->inicioConsulta($consulta));
        $porDia = $filas->groupBy('dia');
        $zona = $consulta->fechaInicio->getTimezone();
        $inicio = $consulta->fechaInicio->format('Y-m-d');
        $fin = $consulta->fechaFin->format('Y-m-d');

        $renglones = $this->agruparPorPeriodo(
            $consulta,
            $filas->filter(fn ($fila) => $fila->dia >= $inicio && $fila->dia <= $fin),
            fn ($fila) => CarbonImmutable::createFromFormat('!Y-m-d', $fila->dia, $zona),
            fn (Collection $grupo) => $this->valores($grupo),
        );

        $campo = $this->campoComparado();

        return array_map(function (array $renglon) use ($consulta, $porDia, $campo) {
            [$desde, $hasta] = $this->rangoAnterior($consulta, $renglon);
            $anterior = $this->valores($this->entre($porDia, $desde, $hasta))[$campo];

            return $renglon + [
                self::CAMPO_ANTERIOR => $anterior,
                self::CAMPO_VARIACION => $anterior == 0 ? null : round(($renglon[$campo] - $anterior) / $anterior * 100, 1),
            ];
        }, $renglones);
    }

    /**
     *
     * @param list<string> $agregados expresiones SQL con alias'
     * @param list<string> $extra     columnas adicionales de agrupación
     */
    protected function agregarPorDia(Builder $query, string $columna, KpiQuery $consulta, CarbonImmutable $desde, array $agregados, array $extra = []): Collection {
        $fecha = 'DATE('.$query->getQuery()->getGrammar()->wrap($columna).')';

        $base = $query
            ->whereBetween($columna, [$desde->format('Y-m-d H:i:s'), $consulta->fechaFin->format('Y-m-d H:i:s')])
            ->toBase()
            ->selectRaw("{$fecha} as dia")
            ->groupByRaw($fecha);

        foreach ($extra as $columnaExtra) {
            $base->addSelect($columnaExtra)->groupBy($columnaExtra);
        }
        foreach ($agregados as $agregado) {
            $base->selectRaw($agregado);
        }

        return $base->get();
    }

    private function inicioConsulta(KpiQuery $consulta): CarbonImmutable {
        if ($consulta->agrupacion === Periodo::TOTAL) {
            return $consulta->fechaInicio->subDays($this->diasDelPeriodo($consulta));
        }

        return $this->retroceder(Periodo::inicioDeCubeta($consulta->fechaInicio, $consulta->agrupacion), $consulta->agrupacion);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function rangoAnterior(KpiQuery $consulta, array $renglon): array {
        if ($consulta->agrupacion === Periodo::TOTAL) {
            return [$consulta->fechaInicio->subDays($this->diasDelPeriodo($consulta)), $consulta->fechaInicio->subDay()];
        }

        $zona = $consulta->fechaInicio->getTimezone();
        $agrupacion = $consulta->agrupacion;
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $renglon['periodo_inicio'], $zona);
        $fin = CarbonImmutable::createFromFormat('!Y-m-d', $renglon['periodo_fin'], $zona);

        $cubeta = Periodo::inicioDeCubeta($inicio, $agrupacion);
        $finCubeta = $this->avanzar($cubeta, $agrupacion)->subDay();
        $anterior = $this->retroceder($cubeta, $agrupacion);
        $finAnterior = $cubeta->subDay();

        $desde = $anterior->addDays($this->dias($cubeta, $inicio))->min($finAnterior);
        $hasta = $fin->eq($finCubeta)
            ? $finAnterior
            : $anterior->addDays($this->dias($cubeta, $fin))->min($finAnterior);

        return [$desde, $hasta];
    }

    
    private function entre(Collection $porDia, CarbonImmutable $desde, CarbonImmutable $hasta): Collection {
        $filas = [];
        for ($dia = $desde; $dia->lte($hasta); $dia = $dia->addDay()) {
            foreach ($porDia->get($dia->format('Y-m-d'), []) as $fila) {
                $filas[] = $fila;
            }
        }

        return new Collection($filas);
    }

    private function diasDelPeriodo(KpiQuery $consulta): int {
        return $this->dias($consulta->fechaInicio, $consulta->fechaFin->startOfDay()) + 1;
    }

    private function dias(CarbonImmutable $desde, CarbonImmutable $hasta): int {
        return (int) round($desde->diffInDays($hasta));
    }

    private function avanzar(CarbonImmutable $cubeta, string $agrupacion): CarbonImmutable {
        return match ($agrupacion) {
            Periodo::DIA => $cubeta->addDay(),
            Periodo::SEMANA => $cubeta->addWeek(),
            Periodo::MES => $cubeta->addMonthNoOverflow(),
            Periodo::TRIMESTRE => $cubeta->addMonthsNoOverflow(3),
            Periodo::ANIO => $cubeta->addYear(),
        };
    }

    private function retroceder(CarbonImmutable $cubeta, string $agrupacion): CarbonImmutable {
        return match ($agrupacion) {
            Periodo::DIA => $cubeta->subDay(),
            Periodo::SEMANA => $cubeta->subWeek(),
            Periodo::MES => $cubeta->subMonthNoOverflow(),
            Periodo::TRIMESTRE => $cubeta->subMonthsNoOverflow(3),
            Periodo::ANIO => $cubeta->subYear(),
        };
    }
}
