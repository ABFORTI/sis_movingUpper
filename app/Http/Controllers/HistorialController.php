<?php

namespace App\Http\Controllers;

use App\Models\Mudanza;
use App\Models\Tarima;
use App\Services\TarimaService;
use App\Support\MudanzaActiva;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistorialController extends Controller {
    private const POR_PAGINA = 15;

    public const FILTROS = [
        'mudanza' => ['nullable', 'integer', 'exists:mudanzas,id'],
        'tarima' => ['nullable', 'string', 'max:30'],
        'descripcion' => ['nullable', 'string', 'max:255'],
        'numero_serie' => ['nullable', 'string', 'max:150'],
    ];

    public function __construct(private readonly TarimaService $tarimas)
    { }

    public function index(Request $request, MudanzaActiva $mudanzaActiva): View {
        $filtros = $request->validate(self::FILTROS);
        $mudanza = $mudanzaActiva->elegida($filtros['mudanza'] ?? null);

        $tarimas = Tarima::guardadas()
            ->where('mudanza_id', $mudanza?->id ?? 0)
            ->with(['mudanza', 'movimiento.origen', 'movimiento.destino', 'registros.numerosSerie.incidenciaPendiente', 'creador', 'anuladaPor'])
            ->filtrosHistorial($filtros)
            ->orderByDesc('guardada_at')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return view('historial.index', [
            'tarimas' => $tarimas,
            'filtros' => $filtros,
            'mudanza' => $mudanza,
            'mudanzas' => Mudanza::whereNot('estado', Mudanza::BORRADOR)->orderByDesc('id')->get(),
            'embarquesExcel' => Tarima::exportables()->where('mudanza_id', $mudanza?->id ?? 0)->orderBy('numero')->get(['id', 'numero']),
            'urlsQr' => $tarimas->getCollection()->mapWithKeys(fn (Tarima $t) => [$t->id => $this->tarimas->urlDetalle($t)]),
        ]);
    }
}
