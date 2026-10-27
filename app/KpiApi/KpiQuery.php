<?php

namespace App\KpiApi;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class KpiQuery {
    /**
     * @param array<string, string[]> $filtros    filtros generales aplicados
     * @param array<string, mixed>    $parametros parámetros propios del KPI
     */
    public function __construct(
        public readonly CarbonImmutable $fechaInicio,
        public readonly CarbonImmutable $fechaFin,
        public readonly string $agrupacion,
        public readonly array $filtros = [],
        public readonly array $parametros = [],
        public readonly ?int $pagina = null,
        public readonly ?int $porPagina = null,
    ) {
    }

    /** @return string[]|null valores del filtro, o null */
    public function filtro(string $nombre): ?array {
        return $this->filtros[$nombre] ?? null;
    }

    public function parametro(string $nombre): mixed {
        return $this->parametros[$nombre] ?? null;
    }

    public function aplicarPeriodo(Builder|QueryBuilder $query, string $columna): Builder|QueryBuilder
    {
        return $query->whereBetween($columna, [
            $this->fechaInicio->format('Y-m-d H:i:s'),
            $this->fechaFin->format('Y-m-d H:i:s'),
        ]);
    }

    public function aplicarFiltro(Builder|QueryBuilder $query, string $filtro, string $columna): Builder|QueryBuilder {
        if ($valores = $this->filtro($filtro)) {
            $query->whereIn($columna, $valores);
        }

        return $query;
    }

    /** @return array{fecha_inicio: string, fecha_fin: string, agrupacion: string, zona_horaria: string} */
    public function periodoParaRespuesta(): array {
        return [
            'fecha_inicio' => $this->fechaInicio->format('Y-m-d'),
            'fecha_fin' => $this->fechaFin->format('Y-m-d'),
            'agrupacion' => $this->agrupacion,
            'zona_horaria' => $this->fechaInicio->getTimezone()->getName(),
        ];
    }
}
