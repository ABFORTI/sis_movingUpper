<?php

namespace App\Http\Controllers;

use App\Models\Destino;
use App\Models\Tarima;
use App\Support\SkuActivo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WmsController extends Controller {
    public const MODO_SKU_NS = 1;
    public const MODO_SOLO_SKU = 2;

    public function index(Request $request, SkuActivo $skuActivo): View {
        $tarimas = Tarima::temporales()->visiblesPara($request->user())->with(['registros.destino', 'registros.numerosSerie'])->orderBy('id')->get();

        return view('wms.index', [
            'modo' => (int) $request->session()->get('wms.modo', self::MODO_SKU_NS),
            'skuActivo' => $skuActivo->obtener(),
            'destinos' => Destino::activos()->get(),
            'tarimas' => $tarimas,
            'tarimasConProductos' => $tarimas->filter(fn (Tarima $t) => $t->registros->isNotEmpty()),
            'tarimaSeleccionada' => old('tarima', $request->session()->get('wms.tarima')),
            'destinoSeleccionado' => (int) old('destino_id', $request->session()->get('wms.destino')),
        ]);
    }

    public function modo(Request $request, SkuActivo $skuActivo): RedirectResponse {
        $modo = (int) $request->validate(['modo' => ['required', 'in:1,2']])['modo'];

        $mensaje = null;
        if ($skuActivo->hay()) {
            $skuActivo->cerrar();
            $mensaje = 'Se cerró el SKU padre activo al cambiar de modo.';
        }

        $request->session()->put('wms.modo', $modo);

        return redirect()->route('wms')->with('warn', $mensaje);
    }
}
