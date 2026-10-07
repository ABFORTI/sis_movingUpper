<?php

namespace App\Kpis;

use App\KpiApi\KpiQuery;
use App\Models\NumeroSerie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * N/S escaneados vivos por fecha de escaneo (numeros_serie.created_at), en
 * tarimas temporales y guardadas. El índice único de numero_serie_activo impide
 * dos N/S vivos iguales, así que el total es también el de N/S únicos: una N/S
 * borrada (papelera) y vuelta a escanear cuenta una sola vez, en su último escaneo.
 */
class NumerosSerieEscaneados extends SerieDiariaKpi {
    public function clave(): string { return 'numeros-serie-escaneados'; }
    public function nombre(): string { return 'Números de serie escaneados'; }
    public function descripcion(): string { return 'N/S escaneados por periodo según la fecha y hora del escaneo. Excluye los enviados a la papelera; cada N/S viva es única en el sistema.'; }
    public function unidad(): string { return 'números de serie'; }

    public function filtros(): array {
        return [
            'usuario' => 'ID del usuario que escaneó la N/S (users.id).',
            'producto' => 'SKU padre al que pertenece la N/S (coincidencia exacta).',
        ];
    }

    protected function camposPropios(): array {
        return [
            'total_numeros_serie' => ['tipo' => 'integer', 'descripcion' => 'N/S escaneados y vivos en el periodo (equivale a N/S únicos).'],
        ];
    }

    protected function campoComparado(): string {
        return 'total_numeros_serie';
    }

    protected function diario(KpiQuery $consulta, CarbonImmutable $desde): Collection {
        $query = NumeroSerie::query();

        if ($usuarios = $this->usuarios($consulta)) {
            $query->whereIn('numeros_serie.user_id', $usuarios);
        }
        if ($skus = $consulta->filtro('producto')) {
            $query->join('registros', 'registros.id', '=', 'numeros_serie.registro_id')
                ->whereNull('registros.deleted_at')
                ->whereIn('registros.sku', $skus);
        }

        return $this->agregarPorDia($query, 'numeros_serie.created_at', $consulta, $desde, ['COUNT(*) as total']);
    }

    protected function valores(Collection $filas): array {
        return [
            'total_numeros_serie' => (int) $filas->sum('total'),
        ];
    }
}
