<?php

namespace App\Kpis;

use App\KpiApi\KpiQuery;
use App\Models\Tarima;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Tarimas registradas = tarimas guardadas (pasaron al historial), por
 * tarimas.guardada_at. Las temporales aún no cuentan; las eliminadas por el
 * administrador desaparecen de la base y tampoco.
 */
class TarimasRegistradas extends SerieDiariaKpi {
    public function clave(): string { return 'tarimas-registradas'; }
    public function nombre(): string { return 'Tarimas registradas'; }
    public function descripcion(): string { return 'Tarimas guardadas en el historial por periodo, según la fecha en que se guardaron.'; }
    public function unidad(): string { return 'tarimas'; }

    public function filtros(): array {
        return [
            'usuario' => 'ID del usuario que guardó la tarima (users.id).',
            'producto' => 'SKU contenido en la tarima (coincidencia exacta). Una tarima con varios SKUs filtrados cuenta una vez.',
        ];
    }

    protected function camposPropios(): array {
        return [
            'total_tarimas' => ['tipo' => 'integer', 'descripcion' => 'Tarimas guardadas en el periodo.'],
        ];
    }

    protected function campoComparado(): string {
        return 'total_tarimas';
    }

    protected function diario(KpiQuery $consulta, CarbonImmutable $desde): Collection {
        $query = Tarima::query()->guardadas();

        if ($usuarios = $this->usuarios($consulta)) {
            $query->whereIn('tarimas.guardada_by', $usuarios);
        }
        if ($skus = $consulta->filtro('producto')) {
            // whereExists y no join: una tarima con dos SKUs filtrados no se cuenta doble.
            $query->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('registros')
                ->whereColumn('registros.tarima_id', 'tarimas.id')
                ->whereNull('registros.deleted_at')
                ->whereIn('registros.sku', $skus));
        }

        return $this->agregarPorDia($query, 'tarimas.guardada_at', $consulta, $desde, ['COUNT(*) as total']);
    }

    protected function valores(Collection $filas): array {
        return [
            'total_tarimas' => (int) $filas->sum('total'),
        ];
    }
}
