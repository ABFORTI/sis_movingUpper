<?php

namespace App\Http\Controllers;

use App\Models\Tarima;
use App\Support\MudanzaActiva;
use App\Support\SkuActivo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Pantalla de captura: SKU (del inventario del almacén de la tarima) + N/S. */
class WmsController extends Controller {
    public function index(Request $request, SkuActivo $skuActivo, MudanzaActiva $mudanzaActiva): View {
        $mudanza = $mudanzaActiva->obtener()?->load(['movimientos.origen', 'movimientos.destino']);
        $enCaptura = Tarima::temporales()->where('mudanza_id', $mudanza?->id ?? 0);

        $tarimas = (clone $enCaptura)->trabajablesPor($request->user())
            ->with(['mudanza', 'movimiento.origen', 'movimiento.destino', 'registros.destino', 'registros.numerosSerie.incidenciaPendiente'])
            ->orderBy('numero')->get();
        $deOtros = (clone $enCaptura)->whereNotIn('id', $tarimas->modelKeys())
            ->with(['mudanza', 'creador'])->orderBy('numero')->get();

        return view('wms.index', [
            'mudanza' => $mudanza,
            'movimientos' => $mudanza?->movimientos ?? collect(),
            'tarimasDeOtros' => $deOtros,
            'skuActivo' => $skuActivo->obtener()?->load('tarima.movimiento.origen'),
            'tarimas' => $tarimas,
            'tarimasConProductos' => $tarimas->filter(fn (Tarima $t) => $t->registros->isNotEmpty()),
            'tarimaSeleccionada' => old('tarima', $request->session()->get('wms.tarima')),
            'movimientoSeleccionado' => (int) old('movimiento', $request->session()->get('wms.movimiento')),
        ]);
    }
}
