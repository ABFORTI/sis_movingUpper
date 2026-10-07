<?php

namespace App\Http\Controllers;

use App\Contracts\ExportadorTarimas;
use App\Models\Tarima;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller {
    public function __construct(private readonly ExportadorTarimas $exportador)
    { }

    public function tarima(Tarima $tarima): StreamedResponse|RedirectResponse {
        if ($tarima->cargarDetalle()->registros->isEmpty()) {
            return redirect()->route('wms')->withErrors(['tarima' => "La {$tarima->nombre} no tiene productos."]);
        }

        return $this->exportador->tarima($tarima);
    }

    /**
     * Exporta el historial: solo tarimas guardadas, con los mismos filtros que la
     * pantalla de Historial. Filtrar no renumera: cada pieza conserva su consecutivo.
     */
    public function todas(Request $request): StreamedResponse|RedirectResponse {
        $filtros = $request->validate(HistorialController::FILTROS);

        $tarimas = Tarima::guardadas()
            ->visiblesPara($request->user())
            ->whereHas('registros')
            ->filtrosHistorial($filtros)
            ->with(['registros.destino', 'registros.numerosSerie'])
            ->orderBy('consecutivo_inicial')
            ->orderBy('id')
            ->get();

        if ($tarimas->isEmpty()) {
            return redirect()->route('historial', $filtros)->withErrors(['tarima' => 'No hay tarimas guardadas para exportar.']);
        }

        return $this->exportador->todas($tarimas);
    }
}
