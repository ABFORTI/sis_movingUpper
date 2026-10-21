<?php

namespace App\Http\Controllers;

use App\Models\Tarima;
use App\Services\Etiquetas\EtiquetaService;
use App\Services\Etiquetas\FormatoEtiqueta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;


class EtiquetaController extends Controller {
    public function __construct(private readonly EtiquetaService $etiquetas)
    { }

    public function show(Request $request, Tarima $tarima): View|RedirectResponse {
        $volver = $tarima->estaGuardada() ? 'historial' : 'wms';
        if ($tarima->estaAnulada()) {
            return redirect()->route($volver)->withErrors(['tarima' => "El {$tarima->nombre} está anulado; sus etiquetas ya no son válidas."]);
        }

        $todas = $this->etiquetas->piezas($tarima);
        if ($todas->isEmpty()) {
            return redirect()->route($volver)->withErrors(['tarima' => "El {$tarima->nombre} no tiene piezas con N/S para etiquetar."]);
        }

        $pendientes = $todas->filter->etiquetaPendiente()->values();
        $elegidas = EtiquetaService::ids($request->query('piezas'));
        [$modo, $seleccion] = match (true) {
            $elegidas !== null && $todas->whereIn('id', $elegidas)->isNotEmpty() => ['pieza', $todas->whereIn('id', $elegidas)->values()],
            $request->query('ver') === 'todas' || $pendientes->isEmpty() => ['todas', $todas],
            default => ['pendientes', $pendientes],
        };
        $ids = $seleccion->modelKeys();

        return view('etiquetas.show', [
            'tarima' => $tarima->loadMissing('mudanza', 'movimiento.origen', 'movimiento.destino'),
            'todas' => $todas,
            'pendientes' => $pendientes,
            'modo' => $modo,
            'seleccion' => $seleccion,
            'piezasParam' => implode(',', $ids),
            'modelos' => $this->etiquetas->modelos($tarima, $todas),
            'etiquetasSvg' => $this->etiquetas->svg($tarima, $ids),
            'formato' => FormatoEtiqueta::desdeConfig(),
            'volver' => $volver,
        ]);
    }

    public function zpl(Request $request, Tarima $tarima): Response {
        abort_if($tarima->estaAnulada(), 422, 'El embarque está anulado.');
        $piezas = $this->etiquetas->piezas($tarima, EtiquetaService::ids($request->query('piezas')));
        abort_if($piezas->isEmpty(), 422, 'El embarque no tiene piezas para etiquetar.');

        $respuesta = response($this->etiquetas->zpl($tarima, $piezas->modelKeys()), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);

        if ($request->boolean('descargar')) {
            $respuesta->header('Content-Disposition', "attachment; filename=\"{$tarima->mudanza->folio}_{$tarima->codigo}.zpl\"");
        }

        return $respuesta;
    }
}
