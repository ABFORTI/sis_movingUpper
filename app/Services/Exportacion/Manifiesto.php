<?php

namespace App\Services\Exportacion;

use App\Models\Embarque;
use App\Models\Incidencia;
use App\Models\InventarioCarga;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Services\Inventario\InventarioService;
use Illuminate\Support\Collection;


class Manifiesto {
    public function __construct(private readonly InventarioService $inventario) { }

    /** @return list<array> */
    public function deTrailer(Embarque $embarque): array {
        $piezas = Partida::with('tarima.movimiento')
            ->whereHas('carga', fn ($q) => $q->where('embarque_id', $embarque->id))
            ->orderBy('consecutivo')
            ->get()
            ->map(fn (Partida $p) => [
                'sku' => $p->sku,
                'descripcion' => $p->descripcion,
                'numero_serie_id' => $p->numero_serie_id,
                'numero_serie' => $p->numero_serie,
                'partida_id' => $p->id,
                'almacen_id' => $p->tarima?->movimiento?->almacen_origen_id,
            ]);

        return $this->filas($piezas);
    }

    /**
     * @param  Collection<int, array{sku: string, descripcion: ?string, numero_serie_id: ?int, numero_serie: ?string, partida_id: ?int, almacen_id: ?int}>  $piezas
     * @return list<array>
     */
    private function filas(Collection $piezas): array {
        $series = NumeroSerie::withTrashed()->with(['inventarioItem', 'incidenciaPendiente'])
            ->whereKey($piezas->pluck('numero_serie_id')->filter()->all())->get()->keyBy('id');
        [$nsDanadas, $partidasDanadas] = $this->danadas($piezas);
        $descripcionSku = [];

        return $piezas->values()->map(function (array $p, int $i) use ($series, $nsDanadas, $partidasDanadas, &$descripcionSku) {
            $serie = $p['numero_serie_id'] ? $series->get($p['numero_serie_id']) : null;
            $item = $serie?->inventarioItem;
            $descripcion = $item?->descripcion
                ?? ($descripcionSku[$p['almacen_id'].'|'.$p['sku']] ??= $this->descripcionDelSku($p['almacen_id'], $p['sku']))
                ?? $p['descripcion'];
            $danada = ($p['numero_serie_id'] && isset($nsDanadas[$p['numero_serie_id']])) || ($p['partida_id'] && isset($partidasDanadas[$p['partida_id']]));

            return [
                $i + 1,
                $p['sku'],
                $descripcion,
                ExcelExportador::CANTIDAD,
                ExcelExportador::UNIDAD,
                $item?->posicion,
                $danada ? 1 : 0,
                $p['numero_serie'],
                ExcelExportador::PENDIENTE => (bool) $serie?->pendienteRevision(),
            ];
        })->all();
    }

    private function descripcionDelSku(?int $almacenId, string $sku): ?string {
        $carga = $almacenId ? InventarioCarga::where('almacen_id', $almacenId)->where('vigente', true)->first() : null;

        return $carga ? ($this->inventario->sku($carga, $sku)['descripcion'] ?? null) : null;
    }

    /**
     *
     * @return array{0: array<int, true>, 1: array<int, true>}
     */
    private function danadas(Collection $piezas): array {
        $ns = $piezas->pluck('numero_serie_id')->filter()->values()->all();
        $partidas = $piezas->pluck('partida_id')->filter()->values()->all();
        if ($ns === [] && $partidas === []) {
            return [[], []];
        }

        $incidencias = Incidencia::with('partidas:id,numero_serie_id')
            ->where('tipo', Incidencia::DANIO)
            ->where(fn ($q) => $q->whereNull('tipo_resolucion')->orWhere('tipo_resolucion', '!=', 'descartada'))
            ->where(fn ($q) => $q->whereIn('numero_serie_id', $ns)->orWhereHas('partidas', fn ($p) => $p->whereKey($partidas)))
            ->get();

        $nsDanadas = [];
        $partidasDanadas = [];
        foreach ($incidencias as $incidencia) {
            if ($incidencia->numero_serie_id) {
                $nsDanadas[$incidencia->numero_serie_id] = true;
            }
            foreach ($incidencia->partidas as $p) {
                $partidasDanadas[$p->id] = true;
                if ($p->numero_serie_id) {
                    $nsDanadas[$p->numero_serie_id] = true;
                }
            }
        }

        return [$nsDanadas, $partidasDanadas];
    }
}
