<?php

namespace App\Http\Controllers;

use App\Models\Autorizacion;
use App\Models\Evidencia;
use App\Models\Incidencia;
use App\Models\Mudanza;
use App\Models\User;
use App\Services\DocumentoService;
use App\Services\IncidenciaService;
use App\Support\MudanzaActiva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;


class IncidenciaController extends Controller {
    private const POR_PAGINA = 20;

    public function __construct(private readonly IncidenciaService $incidencias) { }

    public function index(Request $request, MudanzaActiva $mudanzaActiva): View {
        $filtros = $request->validate([
            'mudanza' => ['nullable', 'integer', 'exists:mudanzas,id'],
            'estado' => ['nullable', Rule::in(['todas', ...array_keys(Incidencia::ESTADOS)])],
            'tipo' => ['nullable', Rule::in([...array_keys(Incidencia::TIPOS), 'historicos'])],
        ]);
        $mudanza = $mudanzaActiva->elegida($filtros['mudanza'] ?? null);
        $estado = $filtros['estado'] ?? Incidencia::ABIERTA;
        $tipo = $filtros['tipo'] ?? null;

        $incidencias = Incidencia::with(['responsable', 'creadaPor', 'tarima', 'almacen'])->withCount(['partidas', 'evidencias'])
            ->where('mudanza_id', $mudanza?->id ?? 0)
            ->when(isset(Incidencia::ESTADOS[$estado]), fn ($q) => $q->where('estado', $estado))
            ->when($tipo === 'historicos', fn ($q) => $q->whereNotIn('tipo', array_keys(Incidencia::TIPOS)))
            ->when(isset(Incidencia::TIPOS[$tipo]), fn ($q) => $q->where('tipo', $tipo))
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return view('incidencias.index', [
            'incidencias' => $incidencias,
            'mudanza' => $mudanza,
            'mudanzas' => Mudanza::whereNot('estado', Mudanza::BORRADOR)->orderByDesc('id')->get(),
            'estado' => $estado,
            'tipo' => $tipo,
            'hayHistoricas' => Incidencia::where('mudanza_id', $mudanza?->id ?? 0)->whereNotIn('tipo', array_keys(Incidencia::TIPOS))->exists(),
        ]);
    }

    public function create(Request $request, MudanzaActiva $mudanzaActiva): View|RedirectResponse {
        $mudanza = $mudanzaActiva->obtener();
        if (! $mudanza) {
            return redirect()->route('incidencias.index')->withErrors(['titulo' => 'No hay una mudanza en curso.']);
        }

        return view('incidencias.create', [
            'mudanza' => $mudanza,
            'tipo' => $request->query('tipo'),
            'piezas' => $request->query('piezas'),
        ]);
    }

    public function store(Request $request, MudanzaActiva $mudanzaActiva): RedirectResponse {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Incidencia::TIPOS))],
            'titulo' => ['required', 'string', 'min:5', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'bloquea_traslado' => ['boolean'],
            'piezas' => ['nullable', 'string', 'max:5000'],
        ] + Evidencia::reglas(), [
            'tipo.*' => 'Elige el tipo de incidencia.',
            'titulo.*' => 'Escribe un título de 5 a 150 caracteres.',
        ] + Evidencia::mensajes());

        $mudanza = $mudanzaActiva->obtener() ?? abort(422, 'No hay una mudanza en curso.');
        $incidencia = $this->incidencias->crear($mudanza, [
            ...$datos,
            'bloquea_traslado' => $request->boolean('bloquea_traslado'),
        ], $request->user(), $request->file('archivos', []));

        return redirect()->route('incidencias.show', $incidencia)->with('ok', "Incidencia {$incidencia->folio} registrada.");
    }

    public function show(Incidencia $incidencia): View {
        $incidencia->load(['mudanza', 'partidas.tarima', 'partidas.incidenciaPendiente', 'tarima', 'almacen', 'eventos.usuario', 'evidencias.subidaPor', 'responsable', 'creadaPor', 'resueltaPor']);

        return view('incidencias.show', [
            'incidencia' => $incidencia,
            'usuarios' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function comentar(Request $request, Incidencia $incidencia): RedirectResponse {
        $datos = $request->validate(['comentario' => ['nullable', 'string', 'max:5000']] + Evidencia::reglas(), Evidencia::mensajes());

        $this->incidencias->comentar($incidencia, $datos['comentario'] ?? null, $request->user(), $request->file('archivos', []));

        return redirect()->route('incidencias.show', $incidencia)->with('ok', 'Agregado al historial.');
    }

    public function vincular(Request $request, Incidencia $incidencia): RedirectResponse {
        $datos = $request->validate(['piezas' => ['required', 'string', 'max:5000']], ['piezas.*' => 'Escanea o escribe al menos una N/S o consecutivo.']);

        $n = $this->incidencias->vincular($incidencia, $datos['piezas'], $request->user());

        return redirect()->route('incidencias.show', $incidencia)->with('ok', $n ? "{$n} ".($n === 1 ? 'pieza vinculada' : 'piezas vinculadas').'.' : 'Esas piezas ya estaban vinculadas.');
    }

    public function asignar(Request $request, Incidencia $incidencia): RedirectResponse {
        $datos = $request->validate(['responsable_id' => ['nullable', 'integer', 'exists:users,id']]);

        $this->incidencias->asignar($incidencia, isset($datos['responsable_id']) ? User::find($datos['responsable_id']) : null, $request->user());

        return redirect()->route('incidencias.show', $incidencia)->with('ok', 'Responsable actualizado.');
    }

    public function reabrir(Request $request, Incidencia $incidencia): RedirectResponse {
        $datos = $request->validate(['nota' => ['nullable', 'string', 'max:1000']]);

        $this->incidencias->reabrir($incidencia, $request->user(), $datos['nota'] ?? null);

        return redirect()->route('incidencias.show', $incidencia)->with('ok', "Incidencia {$incidencia->folio} reabierta.");
    }

    public function resolver(Request $request, Incidencia $incidencia): RedirectResponse {
        $datos = $request->validate([
            'tipo_resolucion' => ['required', Rule::in(array_keys(Incidencia::RESOLUCIONES))],
            'resolucion' => ['required', 'string', 'min:5', 'max:5000'],
        ] + Evidencia::reglas(), [
            'tipo_resolucion.*' => 'Elige cómo se resolvió.',
            'resolucion.*' => 'Describe la resolución (al menos 5 caracteres).',
        ] + Evidencia::mensajes());

        $this->incidencias->resolver($incidencia, $datos['tipo_resolucion'], $datos['resolucion'], $request->user(), $request->file('archivos', []));

        return redirect()->route('incidencias.show', $incidencia)->with('ok', "Incidencia {$incidencia->folio} resuelta.");
    }

    public function evidencia(Request $request, Evidencia $evidencia, DocumentoService $documentos): StreamedResponse {
        abort_if($evidencia->evidenciable_type === (new Autorizacion)->getMorphClass() && ! $request->user()->esSupervision(), 403);

        $enLinea = in_array($evidencia->mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);

        return $documentos->descargar($evidencia->archivo, $evidencia->sha256, $evidencia->nombre_original, $enLinea);
    }
}
