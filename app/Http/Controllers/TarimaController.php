<?php

namespace App\Http\Controllers;

use App\Models\Tarima;
use App\Services\ImpresionService;
use App\Services\TarimaService;
use App\Support\SkuActivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TarimaController extends Controller {
    public function __construct(private readonly TarimaService $tarimas)
    { }

    public function store(Request $request): RedirectResponse {
        $tarima = $this->tarimas->crear($request->user());
        $request->session()->put('wms.tarima', $tarima->uuid);

        return redirect()->route('wms')->with('ok', "{$tarima->nombre} creada.");
    }

    public function vaciar(Request $request, Tarima $tarima, SkuActivo $skuActivo): RedirectResponse {
        if ($skuActivo->obtener()?->tarima_id == $tarima->id) {
            $skuActivo->cerrar();
        }

        $this->tarimas->vaciar($tarima, $request->user());

        $detalle = $request->user()->isAdmin() ? 'eliminado definitivamente' : 'enviado a la papelera';

        return redirect()->route('wms')->with('warn', "Contenido de la {$tarima->nombre} {$detalle}.");
    }

    public function destroy(Tarima $tarima, SkuActivo $skuActivo): RedirectResponse {
        if ($skuActivo->obtener()?->tarima_id == $tarima->id) {
            $skuActivo->cerrar();
        }

        $nombre = $tarima->nombre;
        $this->tarimas->eliminar($tarima);

        return redirect()->route('wms')->with('warn', "{$nombre} eliminada definitivamente.");
    }

    /** Lo llama impresion.js cuando el usuario confirma que la etiqueta física salió bien. */
    public function marcarImpresa(Request $request, Tarima $tarima, ImpresionService $impresiones): JsonResponse {
        $impresiones->registrar($tarima, $request->user());

        return response()->json(['impresa' => $tarima->estaImpresa() || $tarima->estaGuardada()]);
    }

    /**
     * Temporal → guardada. Responde JSON para que la vista quite la tarjeta sin
     * recargar. Si ya estaba guardada no crea nada y responde igual de exitoso.
     */
    public function guardar(Request $request, Tarima $tarima, SkuActivo $skuActivo): JsonResponse {
        $guardadaAhora = $this->tarimas->guardar($tarima, $request->user());
        $mensaje = $guardadaAhora
            ? 'Información Guardada. Dentro del historial puedes visualizarla'
            : "La {$tarima->nombre} ya estaba guardada. Dentro del historial puedes visualizarla";

        // Si el SKU padre abierto era de esta tarima, se cierra; el panel de captura
        // cambia, así que la vista recarga y muestra el mensaje como flash.
        $recargar = false;
        if ($skuActivo->obtener()?->tarima_id == $tarima->id) {
            $skuActivo->cerrar();
            $request->session()->flash('ok', $mensaje);
            $recargar = true;
        }

        return response()->json([
            'guardada' => true,
            'nueva' => $guardadaAhora,
            'mensaje' => $mensaje,
            'recargar' => $recargar,
        ]);
    }

    public function publico(Tarima $tarima): View {
        return view('tarimas.publico', [
            'tarima' => $tarima,
            'filas' => $this->tarimas->filas($tarima),
            'destino' => $this->tarimas->destino($tarima),
        ]);
    }
}
