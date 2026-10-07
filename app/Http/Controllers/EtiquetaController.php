<?php

namespace App\Http\Controllers;

use App\Models\Tarima;
use App\Services\Etiquetas\EtiquetaService;
use App\Services\Etiquetas\FormatoEtiqueta;
use App\Services\TarimaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EtiquetaController extends Controller {
    public function __construct(
        private readonly TarimaService $tarimas,
        private readonly EtiquetaService $etiquetas,
    ) { }

    public function show(Tarima $tarima): View|RedirectResponse {
        $tarima->cargarDetalle();
        if ($tarima->registros->isEmpty()) {
            return redirect()->route('wms')->withErrors(['tarima' => "La {$tarima->nombre} no tiene productos."]);
        }

        return view('etiquetas.show', [
            'tarima' => $tarima,
            'soloPadre' => $tarima->esSoloPadre(),
            'destino' => $this->tarimas->destino($tarima),
            'etiquetasSvg' => $this->etiquetas->svg($tarima),
            'formato' => FormatoEtiqueta::desdeConfig(),
        ]);
    }

    public function zpl(Request $request, Tarima $tarima): Response {
        $tarima->cargarDetalle();
        abort_if($tarima->registros->isEmpty(), 422, 'La tarima no tiene productos.');

        $respuesta = response($this->etiquetas->zpl($tarima), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);

        if ($request->boolean('descargar')) {
            $respuesta->header('Content-Disposition', "attachment; filename=\"tarima_{$tarima->id}.zpl\"");
        }

        return $respuesta;
    }
}
