<?php

namespace App\KpiApi\Contracts;

use App\KpiApi\KpiQuery;


interface Kpi {
    public function clave(): string;

    public function nombre(): string;

    public function descripcion(): string;

    public function unidad(): string;

    public function agrupaciones(): array;

    public function agrupacionPorDefecto(): string;

    /**
     *
     * @return array<string, string>
     */
    public function filtros(): array;

    /**
     *
     * @return array<string, array{tipo: string, descripcion: string, reglas?: array, default?: mixed, requerido?: bool}>
     */
    public function parametros(): array;

    /**
     *
     * @return array<string, array{tipo: string, descripcion: string}>
     */
    public function campos(): array;

    public function paginable(): bool;

    /**
     *
     * @return array<int, array<string, mixed>>
     */
    public function calcular(KpiQuery $consulta): array;
}
