<?php

namespace App\Kpis;

use App\Enums\TipoRegistro;
use App\KpiApi\KpiQuery;
use App\Models\Registro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * SKUs capturados en tarimas (registros vivos) por registros.created_at.
 * Un mismo SKU en dos tarimas son dos registros y un solo SKU distinto.
 */
class SkusRegistrados extends SerieDiariaKpi {
    public function clave(): string { return 'skus-registrados'; }
    public function nombre(): string { return 'SKUs registrados'; }
    public function descripcion(): string { return 'SKUs capturados en tarimas por periodo (padre con N/S y simples), con el número de SKUs distintos. Excluye los enviados a la papelera.'; }
    public function unidad(): string { return 'registros de SKU'; }

    public function filtros(): array {
        return [
            'usuario' => 'ID del usuario que capturó el SKU (users.id).',
            'producto' => 'SKU capturado (coincidencia exacta).',
        ];
    }

    protected function camposPropios(): array {
        return [
            'total_registros' => ['tipo' => 'integer', 'descripcion' => 'SKUs capturados en tarimas en el periodo.'],
            'total_registros_padre' => ['tipo' => 'integer', 'descripcion' => 'De ellos, SKUs padre (llevan N/S).'],
            'total_registros_simples' => ['tipo' => 'integer', 'descripcion' => 'De ellos, SKUs simples (sin N/S).'],
            'total_skus_distintos' => ['tipo' => 'integer', 'descripcion' => 'SKUs diferentes capturados en el periodo (sin distinguir mayúsculas).'],
        ];
    }

    protected function campoComparado(): string {
        return 'total_registros';
    }

    protected function diario(KpiQuery $consulta, CarbonImmutable $desde): Collection {
        $query = Registro::query();

        if ($usuarios = $this->usuarios($consulta)) {
            $query->whereIn('registros.user_id', $usuarios);
        }
        if ($skus = $consulta->filtro('producto')) {
            $query->whereIn('registros.sku', $skus);
        }

        $padre = TipoRegistro::Padre->value;

        return $this->agregarPorDia($query, 'registros.created_at', $consulta, $desde, [
            'COUNT(*) as total',
            "SUM(CASE WHEN registros.tipo = '{$padre}' THEN 1 ELSE 0 END) as padres",
        ], ['registros.sku']);
    }

    protected function valores(Collection $filas): array {
        $total = (int) $filas->sum('total');
        $padres = (int) $filas->sum('padres');

        return [
            'total_registros' => $total,
            'total_registros_padre' => $padres,
            'total_registros_simples' => $total - $padres,
            // Minúsculas para coincidir con la intercalación de MySQL, que no distingue mayúsculas.
            'total_skus_distintos' => $filas->map(fn ($fila) => mb_strtolower($fila->sku))->unique()->count(),
        ];
    }
}
