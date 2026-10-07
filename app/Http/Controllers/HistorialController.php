<?php

namespace App\Http\Controllers;

use App\Models\Tarima;
use App\Services\TarimaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistorialController extends Controller {
    private const POR_PAGINA = 15;

    /** Reglas de los filtros; ExportController las reutiliza para exportar lo mismo. */
    public const FILTROS = [
        'tarima' => ['nullable', 'string', 'max:30'],
        'descripcion' => ['nullable', 'string', 'max:255'],
        'destino' => ['nullable', 'string', 'max:100'],
    ];

    public function __construct(private readonly TarimaService $tarimas)
    { }

    public function index(Request $request): View {
        $filtros = $request->validate(self::FILTROS);

        $tarimas = Tarima::guardadas()
            ->visiblesPara($request->user())
            ->with(['registros.destino', 'registros.numerosSerie', 'creador'])
            ->filtrosHistorial($filtros)
            ->orderByDesc('guardada_at')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return view('historial.index', [
            'tarimas' => $tarimas,
            'filtros' => $filtros,
            'urlsQr' => $tarimas->getCollection()->mapWithKeys(fn (Tarima $t) => [$t->id => $this->tarimas->urlDetalle($t)]),
        ]);
    }
}
