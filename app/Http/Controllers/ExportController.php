<?php

namespace App\Http\Controllers;

use App\Contracts\ExportadorTarimas;
use App\Models\Partida;
use App\Models\Tarima;
use App\Services\BitacoraService;
use App\Support\MudanzaActiva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller {
    public function __construct(
        private readonly ExportadorTarimas $exportador,
        private readonly BitacoraService $bitacora,
    ) { }

    public const COMPLETO = 'completo';
    public const POR_EMBARQUE = 'embarque';

    public function todas(Request $request, MudanzaActiva $mudanzaActiva): StreamedResponse|RedirectResponse {
        $filtros = $request->validate(HistorialController::FILTROS);
        ['alcance' => $modo, 'embarque' => $embarqueId] = $request->validate([
            'alcance' => ['nullable', Rule::in([self::COMPLETO, self::POR_EMBARQUE])],
            'embarque' => ['nullable', 'required_if:alcance,'.self::POR_EMBARQUE, 'integer'],
        ], [
            'embarque.required_if' => 'Elige el embarque que quieres exportar.',
        ]) + ['alcance' => null, 'embarque' => null];
        $porEmbarque = $modo === self::POR_EMBARQUE;

        $usuario = $request->user();
        $mudanza = $mudanzaActiva->elegida($filtros['mudanza'] ?? null);

        $tarimas = Tarima::exportables()
            ->where('mudanza_id', $mudanza?->id ?? 0)
            ->when($porEmbarque, fn ($q) => $q->whereKey($embarqueId), fn ($q) => $q->filtrosHistorial($filtros))
            ->with(['mudanza', 'registros.numerosSerie'])
            ->orderBy('consecutivo_inicial')
            ->orderBy('id')
            ->get();

        if ($tarimas->isEmpty()) {
            return redirect()->route('historial', $filtros)->withErrors(['tarima' => $porEmbarque
                ? 'El embarque elegido no existe en esta mudanza, está anulado o no tiene piezas.'
                : 'No hay embarques guardados para exportar.']);
        }

        $filtrado = array_filter(Arr::except($filtros, 'mudanza'), 'filled') !== [];
        $alcance = $mudanza->folio.'_'.match (true) {
            $porEmbarque => $tarimas->first()->codigo,
            $filtrado => 'Tarimas_Filtradas',
            default => 'Todas_Tarimas',
        };

        $this->bitacora->registrar(BitacoraService::EXCEL_HISTORIAL, $usuario, $porEmbarque ? $tarimas->first() : null, [
            'alcance' => $alcance,
            'filtros' => $porEmbarque ? [] : array_filter($filtros, 'filled'),
            'tarimas' => $tarimas->pluck('numero')->all(),
            'piezas' => Partida::enTarima()->whereIn('tarima_id', $tarimas->modelKeys())->count(),
        ], mudanza: $mudanza);

        return $this->exportador->todas($tarimas, $alcance);
    }
}
