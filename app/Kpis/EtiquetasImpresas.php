<?php

namespace App\Kpis;

use App\KpiApi\KpiQuery;
use App\Models\Impresion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Impresiones confirmadas por el usuario (tabla impresiones, por created_at). Un
 * envío que el usuario no confirma ("No, imprimir de nuevo") no queda registrado.
 * Las filas anteriores a la bitácora vienen de tarimas.impresa_at y no tienen
 * número de etiquetas: se reportan en total_impresiones_sin_conteo.
 */
class EtiquetasImpresas extends SerieDiariaKpi {
    public function clave(): string { return 'etiquetas-impresas'; }
    public function nombre(): string { return 'Etiquetas impresas'; }
    public function descripcion(): string { return 'Etiquetas físicas impresas y confirmadas por periodo, con impresiones, reimpresiones y tarimas impresas.'; }
    public function unidad(): string { return 'etiquetas'; }

    public function filtros(): array {
        return [
            'usuario' => 'ID del usuario que confirmó la impresión (users.id).',
        ];
    }

    protected function camposPropios(): array {
        return [
            'total_etiquetas' => ['tipo' => 'integer', 'descripcion' => 'Etiquetas físicas de las impresiones confirmadas (suma de las que tienen conteo).'],
            'total_impresiones' => ['tipo' => 'integer', 'descripcion' => 'Impresiones confirmadas (cada una puede tener varias etiquetas).'],
            'total_reimpresiones' => ['tipo' => 'integer', 'descripcion' => 'Impresiones de una tarima que ya se había impreso antes.'],
            'total_tarimas_impresas' => ['tipo' => 'integer', 'descripcion' => 'Tarimas distintas con al menos una impresión en el periodo.'],
            'total_impresiones_sin_conteo' => ['tipo' => 'integer', 'descripcion' => 'Impresiones históricas (anteriores a la bitácora) sin número de etiquetas; si es mayor a 0, total_etiquetas está incompleto.'],
        ];
    }

    protected function campoComparado(): string {
        return 'total_etiquetas';
    }

    protected function diario(KpiQuery $consulta, CarbonImmutable $desde): Collection {
        $query = Impresion::query();

        if ($usuarios = $this->usuarios($consulta)) {
            $query->whereIn('impresiones.user_id', $usuarios);
        }

        return $this->agregarPorDia($query, 'impresiones.created_at', $consulta, $desde, [
            'COUNT(*) as total',
            'SUM(CASE WHEN impresiones.es_reimpresion = 1 THEN 1 ELSE 0 END) as reimpresiones',
            'COALESCE(SUM(impresiones.etiquetas), 0) as etiquetas',
            'SUM(CASE WHEN impresiones.etiquetas IS NULL THEN 1 ELSE 0 END) as sin_conteo',
        ], ['impresiones.tarima_id']);
    }

    protected function valores(Collection $filas): array {
        return [
            'total_etiquetas' => (int) $filas->sum('etiquetas'),
            'total_impresiones' => (int) $filas->sum('total'),
            'total_reimpresiones' => (int) $filas->sum('reimpresiones'),
            'total_tarimas_impresas' => $filas->pluck('tarima_id')->unique()->count(),
            'total_impresiones_sin_conteo' => (int) $filas->sum('sin_conteo'),
        ];
    }
}
