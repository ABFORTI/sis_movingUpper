<?php

namespace App\Services\Etiquetas;

use App\Models\NumeroSerie;
use App\Models\Tarima;
use App\Services\Inventario\InventarioService;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Una etiqueta por pieza escaneada (EtiquetaPieza). El ZPL de varias piezas es
 * la concatenación de sus etiquetas (^XA…^XZ cada una): la impresora las saca
 * una tras otra con el mismo mecanismo de siempre.
 */
class EtiquetaService {
    public function __construct(
        private readonly EtiquetaPieza $diseno,
        private readonly InventarioService $inventario,
        private readonly ZplRenderer $zpl,
        private readonly SvgRenderer $svg,
    ) { }

    /** "12,15,16" (como viaja en la URL de impresión) → [12, 15, 16]; vacío o ausente → null (todas). */
    public static function ids(mixed $valor): ?array {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $valor)))));

        return $ids === [] ? null : $ids;
    }

    /**
     * Piezas (N/S vivas) del embarque, en el orden en que se escanearon. Con
     * $ids, solo esas (las que no son de este embarque se ignoran).
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, NumeroSerie>
     */
    public function piezas(Tarima $tarima, ?array $ids = null): Collection {
        return NumeroSerie::with(['registro', 'inventarioItem:id,modelo'])
            ->whereHas('registro', fn ($q) => $q->where('tarima_id', $tarima->id))
            ->when($ids !== null, fn ($q) => $q->whereKey($ids))
            ->orderBy('id')
            ->get();
    }

    /** Piezas del embarque que todavía no tienen su etiqueta impresa. */
    public function pendientes(Tarima $tarima): Collection {
        return $this->piezas($tarima)->filter->etiquetaPendiente()->values();
    }

    /**
     * El modelo que va en la etiqueta: el del renglón del inventario con que
     * coincidió la N/S; si no tiene (o la N/S se registró como incidencia), el
     * del SKU en el inventario del almacén de origen; si tampoco hay, el SKU.
     * Un SKU convertido (catálogo de conversiones) se imprime como modelo: el
     * SKU anterior va debajo (EtiquetaPieza).
     *
     * @return array<int, string> por id de N/S
     */
    public function modelos(Tarima $tarima, Collection $piezas): array {
        $carga = $this->inventario->vigente($tarima->loadMissing('movimiento.origen')->almacenOrigen());
        $porSku = [];

        return $piezas->mapWithKeys(function (NumeroSerie $ns) use ($carga, &$porSku) {
            $sku = $ns->registro->sku;
            if ($ns->registro->fueConvertido()) {
                return [$ns->id => $sku];
            }
            $modelo = $ns->inventarioItem?->modelo
                ?: ($porSku[$sku] ??= ($carga ? $this->inventario->sku($carga, $sku)['modelo'] ?? null : null) ?: $sku);

            return [$ns->id => $modelo];
        })->all();
    }

    /**
     * @param  list<int>|null  $ids
     * @return list<Lienzo> una etiqueta por pieza
     */
    public function paginas(Tarima $tarima, ?array $ids = null): array {
        $piezas = $this->piezas($tarima, $ids);
        if ($piezas->isEmpty()) {
            throw new InvalidArgumentException("El {$tarima->nombre} no tiene piezas para etiquetar.");
        }

        $modelos = $this->modelos($tarima, $piezas);
        $embarque = $tarima->loadMissing('mudanza')->identificador;

        return $piezas->map(fn (NumeroSerie $ns) => $this->diseno->lienzo($modelos[$ns->id], $embarque, $ns->numero_serie, $ns->registro->sku_original))->all();
    }

    /** @param  list<int>|null  $ids */
    public function zpl(Tarima $tarima, ?array $ids = null): string {
        return implode('', array_map($this->zpl->render(...), $this->paginas($tarima, $ids)));
    }

    /**
     * @param  list<int>|null  $ids
     * @return array<int, string> un SVG a escala real por pieza, por id de N/S
     */
    public function svg(Tarima $tarima, ?array $ids = null): array {
        $piezas = $this->piezas($tarima, $ids);
        $paginas = $this->paginas($tarima, $piezas->modelKeys());

        return $piezas->values()->mapWithKeys(fn (NumeroSerie $ns, int $i) => [
            $ns->id => $this->svg->render($paginas[$i], "Etiqueta de la pieza {$ns->numero_serie}, {$tarima->nombre}"),
        ])->all();
    }
}
