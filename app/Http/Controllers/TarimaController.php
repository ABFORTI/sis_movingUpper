<?php

namespace App\Http\Controllers;

use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Tarima;
use App\Services\EmbarqueService;
use App\Services\Etiquetas\EtiquetaService;
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
        $movimiento = $request->validate(['movimiento' => ['nullable', 'integer']])['movimiento'] ?? null;
        $tarima = $this->tarimas->crear($request->user(), $movimiento !== null ? (int) $movimiento : null);
        $request->session()->put(['wms.tarima' => $tarima->uuid, 'wms.movimiento' => $tarima->mudanza_movimiento_id]);

        return redirect()->route('wms')->with('ok', "{$tarima->nombre} creado ({$tarima->movimiento->nombre}).");
    }

    public function vaciar(Request $request, Tarima $tarima, SkuActivo $skuActivo): RedirectResponse {
        if ($skuActivo->obtener()?->tarima_id == $tarima->id) {
            $skuActivo->cerrar();
        }

        $this->tarimas->vaciar($tarima, $request->user());

        return redirect()->route('wms')->with('warn', "Contenido del {$tarima->nombre} enviado a la papelera.");
    }

    public function anular(Request $request, Tarima $tarima, SkuActivo $skuActivo): RedirectResponse {
        $motivo = $request->validate(
            ['motivo' => ['required', 'string', 'min:5', 'max:255']],
            ['motivo.*' => 'Escribe el motivo de la anulación (5 a 255 caracteres).'],
        )['motivo'];

        if ($skuActivo->obtener()?->tarima_id == $tarima->id) {
            $skuActivo->cerrar();
        }

        if (! $tarima->estaGuardada()) {
            $reutiliza = $this->tarimas->eliminar($tarima, $request->user(), $motivo);
            if ($request->session()->get('wms.tarima') === $tarima->uuid) {
                $request->session()->forget('wms.tarima');
            }

            return redirect()->route('wms')->with('warn', "{$tarima->nombre} eliminado. ".($reutiliza
                ? "El número {$tarima->codigo} se asignará al siguiente embarque."
                : "El número {$tarima->codigo} no se reutiliza: ya hay embarques con números posteriores."));
        }

        $this->tarimas->anular($tarima, $request->user(), $motivo);

        return back(fallback: route('historial'))->with('warn', "{$tarima->nombre} anulado. Conserva su número y su contenido queda en el registro.");
    }

    
    public function marcarImpresa(Request $request, Tarima $tarima, ImpresionService $impresiones): JsonResponse {
        $pendientes = $impresiones->registrar($tarima, $request->user(), EtiquetaService::ids($request->input('piezas')));

        return response()->json(['impresa' => true, 'pendientes' => $pendientes]);
    }


    public function guardar(Request $request, Tarima $tarima, SkuActivo $skuActivo): JsonResponse {
        $guardadaAhora = $this->tarimas->guardar($tarima, $request->user());
        $mensaje = $guardadaAhora
            ? 'Información Guardada. Dentro del historial puedes visualizarla'
            : "El {$tarima->nombre} ya estaba guardado. Dentro del historial puedes visualizarlo";

        $anomalias = $guardadaAhora
            ? NumeroSerie::whereHas('registro', fn ($q) => $q->where('tarima_id', $tarima->id))->whereNotNull('anomalia_at')->count() : 0;
        if ($anomalias) {
            $mensaje .= $anomalias === 1 ? '. Se registró 1 incidencia de producto dañado' : ". Se registraron {$anomalias} incidencias de producto dañado";
        }


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


    public function publico(Tarima $tarima, EmbarqueService $embarques): View {
        $situaciones = Partida::with('carga.embarque')->where('tarima_id', $tarima->id)->whereNotNull('numero_serie_id')->get()
            ->mapWithKeys(fn (Partida $p) => [
                $p->numero_serie_id => $p->estaRetirada() ? 'Retirada' : $embarques->situacion($p).($p->carga ? " · tráiler {$p->carga->embarque->folio}" : ''),
            ]);

        return view('tarimas.publico', [
            'tarima' => $tarima,
            'filas' => $this->tarimas->filas($tarima),
            'destino' => $this->tarimas->destino($tarima),
            'situaciones' => $situaciones,
        ]);
    }
}
