<?php

namespace App\Http\Controllers;

use App\Models\Entrega;
use App\Models\Mudanza;
use App\Services\BitacoraService;
use App\Services\DocumentoService;
use App\Services\EntregaService;
use App\Support\MudanzaActiva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;


class EntregaController extends Controller {
    private const FECHA = ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'];

    private const MENSAJES = ['fecha.*' => 'Elige un día válido (hoy o anterior).'];

    public function __construct(private readonly EntregaService $entregas) { }

    public function index(Request $request, MudanzaActiva $mudanzaActiva): View {
        $filtros = $request->validate([
            'mudanza' => ['nullable', 'integer', 'exists:mudanzas,id'],
            'fecha' => self::FECHA,
        ], self::MENSAJES);
        $mudanza = $mudanzaActiva->elegida($filtros['mudanza'] ?? null);
        [$dia, $finDia] = EntregaService::dia($filtros['fecha'] ?? now());

        return view('entregas.index', [
            'mudanza' => $mudanza,
            'mudanzas' => Mudanza::whereNot('estado', Mudanza::BORRADOR)->orderByDesc('id')->get(),
            'fecha' => $dia,
            'entregas' => Entrega::with('generadaPor')->where('mudanza_id', $mudanza?->id ?? 0)
                ->where(fn ($q) => $q->whereDate('fecha', $dia->format('Y-m-d'))
                    ->orWhere(fn ($h) => $h->whereNull('fecha')->whereBetween('created_at', [$dia, $finDia])))
                ->orderByDesc('version')->get(),
            'resumen' => $mudanza ? $this->entregas->resumenDelDia($mudanza, $dia) : collect(),
            'pendientes' => $mudanza ? $this->entregas->pendientes($mudanza, $dia) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse {
        $datos = $request->validate([
            'mudanza' => ['required', 'integer', 'exists:mudanzas,id'],
            'fecha' => self::FECHA,
            'notas' => ['nullable', 'string', 'max:500'],
        ], self::MENSAJES);
        $mudanza = Mudanza::findOrFail($datos['mudanza']);
        [$dia] = EntregaService::dia($datos['fecha'] ?? now());

        $entrega = $this->entregas->generar($mudanza, $request->user(), $datos['notas'] ?? null, $dia);

        return redirect()->route('entregas.index', ['mudanza' => $mudanza->id, 'fecha' => $dia->format('Y-m-d')])
            ->with('ok', "Reporte del {$dia->format('d/m/Y')} generado (v{$entrega->version}): {$entrega->total_partidas} piezas. Descárgalo y envíalo al cliente.");
    }

    public function descargar(Request $request, Entrega $entrega, DocumentoService $documentos, BitacoraService $bitacora): StreamedResponse {
        $respuesta = $documentos->descargar($entrega->archivo, $entrega->sha256, $entrega->nombre_archivo);
        $bitacora->registrar('entrega.descargada', $request->user(), $entrega, ['version' => $entrega->version], mudanza: $entrega->mudanza);

        return $respuesta;
    }
}
