<?php

namespace App\Http\Controllers;

use App\Models\Embarque;
use App\Models\Mudanza;
use App\Models\Tarima;
use App\Services\BitacoraService;
use App\Services\EmbarqueService;
use App\Services\Exportacion\ExcelExportador;
use App\Services\Exportacion\Manifiesto;
use App\Support\MudanzaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;


class EmbarqueController extends Controller {
    public function __construct(private readonly EmbarqueService $embarques) { }

    public function index(Request $request, MudanzaActiva $mudanzaActiva): View {
        $filtros = $request->validate(['mudanza' => ['nullable', 'integer', 'exists:mudanzas,id']]);
        $mudanza = $mudanzaActiva->elegida($filtros['mudanza'] ?? null);

        return view('embarques.index', [
            'mudanza' => $mudanza,
            'mudanzas' => Mudanza::whereNot('estado', Mudanza::BORRADOR)->orderByDesc('id')->get(),
            'movimientos' => $mudanza?->movimientos()->with(['origen', 'destino'])->get() ?? collect(),
            'embarques' => Embarque::where('mudanza_id', $mudanza?->id ?? 0)
                ->with(['movimiento.origen', 'movimiento.destino'])
                ->withCount(['cargas', 'cargas as recibidas_count' => fn ($q) => $q->whereNotNull('recibida_at')])
                ->orderByDesc('id')->get(),
            'resumen' => $mudanza ? $this->embarques->resumen($mudanza) : null,
            // Solo supervisión registra tráileres (formulario "Nuevo tráiler").
            'sugerencias' => $request->user()->esSupervision() && $mudanza?->estaEnCurso() ? $this->embarques->sugerencias() : [],
        ]);
    }

    public function store(Request $request, MudanzaActiva $mudanzaActiva): RedirectResponse {
        $datos = $request->validate([
            'placas' => ['required', 'string', 'min:3', 'max:20'],
            'remolque' => ['nullable', 'string', 'max:30'],
            'chofer' => ['nullable', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'movimiento' => ['nullable', 'integer'],
        ], ['placas.*' => 'Escribe las placas del tractor (3 a 20 caracteres).']);

        $mudanza = $mudanzaActiva->obtener() ?? abort(422, 'No hay una mudanza en curso.');
        $embarque = $this->embarques->crear($mudanza, $datos, $request->user());

        return redirect()->route('embarques.show', $embarque)->with('ok', "{$embarque->folio} listo para cargar.");
    }

    public function show(Embarque $embarque): View {
        $embarque->load(['mudanza', 'movimiento.origen', 'movimiento.destino', 'creadoPor', 'salidaPor', 'recibidoPor']);
        $cargas = $embarque->cargas()->with('tarima')->get();

        return view('embarques.show', [
            'embarque' => $embarque,
            // Por tarima: piezas cargadas y recibidas, en el orden en que se subieron.
            'tarimas' => $cargas->groupBy('tarima_id')->map(fn ($grupo) => [
                'tarima' => $grupo->first()->tarima,
                'piezas' => $grupo->count(),
                'recibidas' => $grupo->whereNotNull('recibida_at')->count(),
                'cargada_at' => $grupo->min('cargada_at'),
            ])->sortByDesc('cargada_at')->values(),
            'total' => $cargas->count(),
            'recibidas' => $cargas->whereNotNull('recibida_at')->count(),
            // El chofer se confirma en la salida.
            'sugerencias' => auth()->user()->esSupervision() && $embarque->enCarga() ? $this->embarques->sugerencias(['chofer']) : [],
        ]);
    }

    public function cargar(Request $request, Embarque $embarque): RedirectResponse|JsonResponse {
        $codigo = $this->codigo($request);

        return $this->responder($request, $embarque, $this->embarques->cargar($embarque, $codigo, $request->user()));
    }

    public function bajar(Request $request, Embarque $embarque, Tarima $tarima): RedirectResponse {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:255']], ['motivo.*' => 'Escribe el motivo (al menos 5 caracteres).']);

        $n = $this->embarques->bajar($embarque, $tarima, $datos['motivo'], $request->user());

        return redirect()->route('embarques.show', $embarque)->with('ok', "{$tarima->codigo} bajado del tráiler ({$n} piezas).");
    }

    public function despachar(Request $request, Embarque $embarque): RedirectResponse {
        $datos = $request->validate([
            'sello' => ['required', 'string', 'max:50'],
            'chofer' => ['nullable', 'string', 'max:150'],
        ], ['sello.*' => 'Escribe el número de sello del tráiler.']);

        $this->embarques->despachar($embarque, $datos, $request->user());

        return redirect()->route('embarques.show', $embarque)->with('ok', "{$embarque->folio} salió. En el destino, escanea lo que llegue.");
    }

    public function recibir(Request $request, Embarque $embarque): RedirectResponse|JsonResponse {
        $codigo = $this->codigo($request);

        return $this->responder($request, $embarque, $this->embarques->recibir($embarque, $codigo, $request->user()), $codigo);
    }

    /** Manifiesto del tráiler: las piezas cargadas en él, con las ocho columnas del manifiesto. */
    public function manifiesto(Embarque $embarque, ExcelExportador $excel, Manifiesto $manifiestos): StreamedResponse {
        $embarque->load('mudanza');

        return $excel->manifiesto($manifiestos->deTrailer($embarque), "{$embarque->mudanza->folio}_{$embarque->folio}_Manifiesto");
    }

    public function cerrar(Request $request, Embarque $embarque): RedirectResponse {
        $faltantes = $this->embarques->cerrarRecepcion($embarque, $request->user());

        return redirect()->route('embarques.show', $embarque)->with(
            $faltantes ? 'warn' : 'ok',
            $faltantes ? "Recepción cerrada con {$faltantes} ".($faltantes === 1 ? 'pieza faltante' : 'piezas faltantes').': aparecen como «Faltante en recepción» en el manifiesto y el reporte final.' : 'Recepción cerrada: llegó todo lo cargado.'
        );
    }

    public function reporte(Request $request, MudanzaActiva $mudanzaActiva, ExcelExportador $excel, BitacoraService $bitacora): StreamedResponse {
        $filtros = $request->validate(['mudanza' => ['nullable', 'integer', 'exists:mudanzas,id']]);
        $mudanza = $mudanzaActiva->elegida($filtros['mudanza'] ?? null) ?? abort(404);

        $filas = $this->embarques->filasReporte($mudanza);
        $porSituacion = collect($filas)->countBy(fn (array $f) => $f[11]);
        $resumen = [
            ['Mudanza', $mudanza->folio],
            ['Cliente', (string) $mudanza->cliente],
            ['Movimientos (origen → destino)', $mudanza->rutas],
            ['Estado de la mudanza', $mudanza->estado_nombre],
            ['Generado', now()->format('Y-m-d H:i:s')],
            ['Generado por', $request->user()->name],
            ['Piezas en el reporte', count($filas)],
            ...$porSituacion->sortKeys()->map(fn (int $n, string $s) => [$s, $n])->values()->all(),
        ];

        $bitacora->registrar('reporte.final', $request->user(), $mudanza, ['piezas' => count($filas), 'situaciones' => $porSituacion->all()], mudanza: $mudanza);

        return $excel->reporteFinal($mudanza->folio, $resumen, $filas);
    }

    private function codigo(Request $request): string {
        return $request->validate(
            ['codigo' => ['required', 'string', 'max:500']],
            ['codigo.*' => 'Escanea la etiqueta de la pieza (N/S), el número de embarque o el SKU.'],
        )['codigo'];
    }

    /** @param array{estado: string, mensaje: string, tarima: string, tarima_id: int, piezas: int} $resultado */
    private function responder(Request $request, Embarque $embarque, array $resultado, ?string $codigo = null): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            $cargas = $embarque->cargas()->get(['tarima_id', 'recibida_at']);
            $deLaTarima = $cargas->where('tarima_id', $resultado['tarima_id']);

            return response()->json($resultado + [
                'codigo' => $codigo,
                'total' => $cargas->count(),
                'recibidas' => $cargas->whereNotNull('recibida_at')->count(),
                'tarima_cargadas' => $deLaTarima->count(),
                'tarima_recibidas' => $deLaTarima->whereNotNull('recibida_at')->count(),
                'bajar_url' => $embarque->enCarga() && $deLaTarima->isNotEmpty()
                    ? route('embarques.bajar', [$embarque, Tarima::findOrFail($resultado['tarima_id'])]) : null,
            ]);
        }

        $redireccion = redirect()->route('embarques.show', $embarque);

        return match ($resultado['estado']) {
            EmbarqueService::NO_ESPERADA, EmbarqueService::REPETIDO => $redireccion->with('warn', $resultado['mensaje']),
            default => $redireccion->with('ok', $resultado['mensaje']),
        };
    }
}
