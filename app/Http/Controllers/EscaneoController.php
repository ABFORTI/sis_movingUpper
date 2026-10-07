<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgregarNumeroSerieRequest;
use App\Http\Requests\RegistrarSkuRequest;
use App\Services\EscaneoService;
use App\Support\SkuActivo;
use Illuminate\Http\RedirectResponse;

class EscaneoController extends Controller {
    public function __construct(
        private readonly EscaneoService $escaneo,
        private readonly SkuActivo $skuActivo,
    ) { }

    public function activarPadre(RegistrarSkuRequest $request): RedirectResponse {
        if ($this->skuActivo->obtener()) {
            return back()->withErrors(['sku' => 'Cierra el SKU padre activo antes de abrir otro.']);
        }

        $tarima = $request->tarima();
        $registro = $this->escaneo->registrarSkuPadre($tarima, $request->datosSku(), $request->user());
        $this->skuActivo->activar($registro);
        $this->recordarSeleccion($request);

        return redirect()->route('wms')->with('ok', "SKU padre {$registro->sku} activo en {$tarima->nombre}. Escanea las N/S.");
    }

    public function cerrarPadre(): RedirectResponse {
        $registro = $this->skuActivo->obtener();
        $this->skuActivo->cerrar();

        return redirect()->route('wms')->with('warn', $registro ? "SKU padre {$registro->sku} cerrado." : null);
    }

    public function agregarNumeroSerie(AgregarNumeroSerieRequest $request): RedirectResponse {
        $padre = $this->skuActivo->obtener();
        if (! $padre) {
            return back()->withErrors(['numero_serie' => 'No hay SKU padre activo (pudo haberse vaciado la tarima).']);
        }

        if ($request->user()->cannot('view', $padre->tarima)) {
            $this->skuActivo->cerrar();
            abort(403);
        }

        $ns = $this->escaneo->agregarNumeroSerie($padre, $request->validated('numero_serie'), $request->user());
        $total = $padre->numerosSerie()->count();

        return redirect()->route('wms')->with('ok', "N/S {$ns->numero_serie} agregada ({$total} en total).");
    }

    public function agregarSimple(RegistrarSkuRequest $request): RedirectResponse {
        $tarima = $request->tarima();
        $registro = $this->escaneo->registrarSkuSimple($tarima, $request->datosSku(), $request->user());
        $this->recordarSeleccion($request);

        return redirect()->route('wms')->with('ok', "{$registro->sku} agregado a {$tarima->nombre}.");
    }

    private function recordarSeleccion(RegistrarSkuRequest $request): void {
        $request->session()->put([
            'wms.tarima' => $request->validated('tarima'),
            'wms.destino' => (int) $request->validated('destino_id'),
        ]);
    }
}
