<?php

namespace App\Services\Inventario;

use App\Models\Almacen;
use App\Models\InventarioCarga;
use App\Models\InventarioItem;
use App\Models\SkuAlta;
use App\Models\User;
use App\Support\ConversionSku;
use App\Support\NumeroSerieNormalizado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;


class InventarioService {
    public function vigente(?Almacen $almacen): ?InventarioCarga {
        return $almacen ? InventarioCarga::where('almacen_id', $almacen->id)->where('vigente', true)->first() : null;
    }

    /**
     *
     * @return Collection<int, array{sku: string, modelo: ?string, descripcion: ?string, piezas: int, alta: bool}>
     */
    public function buscarSkus(InventarioCarga $carga, ?string $texto, ?int $limite = null): Collection {
        $texto = NumeroSerieNormalizado::de($texto);
        $like = addcslashes($texto, '%_\\');
        $limite ??= (int) config('inventarios.opciones_selector');

        $delInventario = InventarioItem::query()
            ->where('inventario_carga_id', $carga->id)
            ->when($texto !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('sku_norm', 'like', $like.'%')
                ->orWhere('modelo', 'like', '%'.$like.'%')
                ->orWhere('descripcion', 'like', '%'.$like.'%')))
            ->groupBy('sku_norm')
            ->orderBy('sku_norm')
            ->limit($limite)
            ->selectRaw('sku_norm, MIN(sku) as sku, MIN(modelo) as modelo, MIN(descripcion) as descripcion, COUNT(*) as piezas')
            ->get()
            ->map(fn (InventarioItem $i) => [
                'sku' => $i->sku,
                'sku_norm' => $i->sku_norm,
                'modelo' => $i->modelo,
                'descripcion' => $i->descripcion,
                'piezas' => (int) $i->getAttribute('piezas'),
                'alta' => false,
            ]);


        $deAlta = SkuAlta::where('almacen_id', $carga->almacen_id)
            ->when($texto !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('sku_norm', 'like', $like.'%')
                ->orWhere('descripcion', 'like', '%'.$like.'%')))
            ->whereNotIn('sku_norm', InventarioItem::where('inventario_carga_id', $carga->id)->select('sku_norm'))
            ->orderBy('sku_norm')->limit($limite)->get()
            ->map(fn (SkuAlta $a) => self::deAlta($a) + ['sku_norm' => $a->sku_norm, 'piezas' => 0]);

        return $delInventario->concat($deAlta)->sortBy('sku_norm')->take($limite)
            ->map(fn (array $o) => ['sku' => $o['sku'], 'modelo' => $o['modelo'], 'descripcion' => $o['descripcion'], 'piezas' => $o['piezas'], 'alta' => $o['alta']])
            ->values();
    }


    public function sku(InventarioCarga $carga, string $sku): ?array {
        foreach (ConversionSku::equivalentes($sku) as $norm) {
            $item = InventarioItem::where('inventario_carga_id', $carga->id)
                ->where('sku_norm', $norm)
                ->orderBy('id')
                ->first(['sku', 'modelo', 'descripcion']);
            if ($item) {
                return ['sku' => $item->sku, 'modelo' => $item->modelo, 'descripcion' => $item->descripcion, 'alta' => false];
            }

            $alta = SkuAlta::where('almacen_id', $carga->almacen_id)->where('sku_norm', $norm)->first();
            if ($alta) {
                return self::deAlta($alta);
            }
        }

        return null;
    }


    public function darDeAltaSku(Almacen $almacen, string $sku, ?string $descripcion, User $usuario): SkuAlta {
        $sku = NumeroSerieNormalizado::limpio($sku);
        $norm = NumeroSerieNormalizado::de($sku);
        if ($norm === '') {
            throw ValidationException::withMessages(['sku' => 'Escribe o escanea el SKU.']);
        }

        $carga = $this->vigente($almacen);
        if ($carga && InventarioItem::where('inventario_carga_id', $carga->id)->where('sku_norm', $norm)->exists()) {
            throw ValidationException::withMessages(['sku' => "El SKU {$sku} ya está en el inventario del {$almacen->etiqueta}: no hace falta darlo de alta."]);
        }
        $existente = SkuAlta::where('almacen_id', $almacen->id)->where('sku_norm', $norm)->first();
        if ($existente) {
            throw ValidationException::withMessages(['sku' => "El SKU {$existente->sku} ya se dio de alta en el {$almacen->etiqueta}."]);
        }

        try {
            return SkuAlta::create([
                'almacen_id' => $almacen->id,
                'sku' => $sku,
                'sku_norm' => $norm,
                'descripcion' => trim((string) $descripcion) ?: null,
                'created_by' => $usuario->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => "El SKU {$sku} se acaba de dar de alta en el {$almacen->etiqueta}."]);
        }
    }

    /** @return array{sku: string, modelo: null, descripcion: ?string, alta: true} */
    private static function deAlta(SkuAlta $alta): array {
        return ['sku' => $alta->sku, 'modelo' => null, 'descripcion' => $alta->descripcion, 'alta' => true];
    }

    /**
     *
     * @return Collection<int, InventarioItem>
     */
    public function coincidencias(InventarioCarga $carga, string $sku, string $numeroSerie): Collection {
        $ns = NumeroSerieNormalizado::de($numeroSerie);
        if ($ns === '') {
            return collect();
        }

        return InventarioItem::where('inventario_carga_id', $carga->id)
            ->whereIn('sku_norm', ConversionSku::equivalentes($sku))
            ->where(fn (Builder $q) => $q
                ->where('ns_fabricante_norm', $ns)
                ->orWhere('numero_serie_norm', $ns)
                ->orWhere('ns_lote_norm', $ns))
            ->orderBy('id')
            ->get()
            ->each(function (InventarioItem $item) use ($ns) {
                foreach (array_keys(InventarioItem::CAMPOS_SERIE) as $campo) {
                    if ($item->{$campo.'_norm'} === $ns) {
                        $item->setAttribute('campo', $campo);
                        break;
                    }
                }
            });
    }


    public function pista(InventarioCarga $carga, string $sku, string $numeroSerie): ?string {
        $ns = NumeroSerieNormalizado::de($numeroSerie);
        $porSerie = fn (Builder $q) => $q->where('ns_fabricante_norm', $ns)->orWhere('numero_serie_norm', $ns)->orWhere('ns_lote_norm', $ns);

        $otroSku = InventarioItem::where('inventario_carga_id', $carga->id)
            ->whereNotIn('sku_norm', ConversionSku::equivalentes($sku))
            ->where($porSerie)->value('sku');
        if ($otroSku !== null) {
            return "En este almacén esa N/S pertenece al SKU {$otroSku}, no al {$sku}.";
        }

        $otroAlmacen = InventarioItem::query()
            ->whereIn('inventario_carga_id', InventarioCarga::where('vigente', true)->where('almacen_id', '!=', $carga->almacen_id)->select('id'))
            ->where($porSerie)->with('almacen')->first();

        return $otroAlmacen ? "Esa N/S está en el inventario del {$otroAlmacen->almacen->etiqueta}, no en el de este embarque." : null;
    }
}
