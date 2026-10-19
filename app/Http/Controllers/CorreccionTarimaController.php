<?php

namespace App\Http\Controllers;

use App\Enums\TipoRegistro;
use App\Models\MovimientoPartida;
use App\Models\NumeroSerie;
use App\Models\Registro;
use App\Models\Tarima;
use App\Services\PartidaService;
use App\Services\TarimaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CorreccionTarimaController extends Controller {
    private const REGLAS = [
        'partidas' => ['required', 'array', 'min:1'],
        'partidas.*' => ['integer'],
        'motivo' => ['required', 'string', 'min:5', 'max:255'],
    ];

    private const MENSAJES = [
        'partidas.*' => 'Selecciona al menos una pieza.',
        'motivo.*' => 'Escribe el motivo (5 a 255 caracteres).',
        'destino.*' => 'Elige el embarque destino.',
        'modelo.*' => 'Elige el modelo (SKU) al que se agrega la N/S.',
        'numero_serie.*' => 'Escanea o escribe la N/S (hasta 150 caracteres).',
        'sku.*' => 'Escribe el SKU de la pieza.',
        'partida.*' => 'Elige la pieza que quieres editar.',
    ];

    public function __construct(
        private readonly PartidaService $partidas,
        private readonly TarimaService $tarimas,
    ) { }

    public function show(Tarima $tarima): View {
        $tarima->load(['mudanza', 'partidas.incidenciaPendiente', 'partidas.incidencias' => fn ($q) => $q->vigentes()]);

        return view('tarimas.corregir', [
            'tarima' => $tarima,
            'destinos' => Tarima::vigentes()->where('mudanza_id', $tarima->mudanza_id)
                ->whereKeyNot($tarima->id)->orderBy('numero')->get(),
            'modelos' => Registro::where('tarima_id', $tarima->id)->where('tipo', TipoRegistro::Padre)->orderBy('sku')->get(),
            'trasladadas' => $tarima->partidas->isEmpty() ? $tarima->trasladadaA() : collect(),
            // Guardado sin partidas (inconsistencia): sus N/S existen pero no tienen consecutivo.
            'sinPartida' => $this->tarimas->piezasSinPartida($tarima),
            'movimientos' => MovimientoPartida::with(['partida', 'origen', 'destino', 'usuario'])
                ->where(fn ($q) => $q->where('tarima_origen_id', $tarima->id)->orWhere('tarima_destino_id', $tarima->id))
                ->whereNot('tipo', MovimientoPartida::ALTA)
                ->latest('id')->limit(50)->get(),
        ]);
    }

    /** Completa las partidas de un embarque que quedó guardado sin ellas (TarimaService::completarPartidas). */
    public function completar(Request $request, Tarima $tarima): RedirectResponse {
        $n = $this->tarimas->completarPartidas($tarima, $request->user());
        $tarima->refresh();

        return redirect()->route('tarimas.corregir', $tarima)->with('ok', $n === 0
            ? "El {$tarima->nombre} ya tenía todas sus piezas con consecutivo."
            : "Se completaron {$n} ".($n === 1 ? 'pieza' : 'piezas')." del {$tarima->nombre}: ya aparecen aquí y en el Excel de trabajo."
                .($tarima->requiereReimpresion() ? ' Confirma la impresión de sus etiquetas en «Reimprimir».' : ''));
    }

    public function mover(Request $request, Tarima $tarima): RedirectResponse {
        $datos = $request->validate(self::REGLAS + ['destino' => ['required', 'uuid', 'exists:tarimas,uuid']], self::MENSAJES);
        $destino = Tarima::where('uuid', $datos['destino'])->firstOrFail();

        $n = $this->partidas->mover($tarima, $datos['partidas'], $destino, $request->user(), $datos['motivo']);
        $todas = ! $tarima->partidas()->exists();

        return redirect()->route('tarimas.corregir', $tarima)
            ->with('ok', ($todas
                ? "Las piezas se movieron al {$destino->nombre} ({$n} ".($n === 1 ? 'pieza' : 'piezas').').'
                : "{$n} ".($n === 1 ? 'pieza movida' : 'piezas movidas')." al {$destino->nombre}.")
                .' Imprime la etiqueta nueva de cada pieza movida y pégala sobre la anterior.');
    }

    public function retirar(Request $request, Tarima $tarima): RedirectResponse {
        $datos = $request->validate(self::REGLAS, self::MENSAJES);

        $n = $this->partidas->retirar($tarima, $datos['partidas'], $request->user(), $datos['motivo']);

        return redirect()->route('tarimas.corregir', $tarima)
            ->with('ok', "{$n} ".($n === 1 ? 'pieza retirada' : 'piezas retiradas')." del {$tarima->nombre}. Las etiquetas de las demás piezas no cambian.");
    }

    /** Eliminar N/S: piezas registradas por error. Quedan en el historial como "N/S eliminada". */
    public function eliminar(Request $request, Tarima $tarima): RedirectResponse {
        $datos = $request->validate(self::REGLAS, self::MENSAJES);

        $n = $this->partidas->retirar($tarima, $datos['partidas'], $request->user(), $datos['motivo'], MovimientoPartida::NS_ELIMINADA);

        return redirect()->route('tarimas.corregir', $tarima)
            ->with('ok', "{$n} ".($n === 1 ? 'N/S eliminada' : 'N/S eliminadas')." del {$tarima->nombre}. Las etiquetas de las demás piezas no cambian.");
    }

    public function agregar(Request $request, Tarima $tarima): RedirectResponse {
        $datos = $request->validate([
            'modelo' => ['required', 'integer'],
            'numero_serie' => ['required', 'string', 'max:150'],
            'motivo' => self::REGLAS['motivo'],
        ], self::MENSAJES);
        $modelo = Registro::where('tarima_id', $tarima->id)->findOrFail($datos['modelo']);

        $serie = $this->partidas->agregar($tarima, $modelo, $datos['numero_serie'], $request->user(), $datos['motivo']);

        return $this->conReimpresion($tarima, $serie, "N/S {$serie->numero_serie} agregada al SKU {$modelo->sku}.");
    }

    public function editar(Request $request, Tarima $tarima): RedirectResponse {
        $datos = $request->validate([
            'partida' => ['required', 'integer'],
            'sku' => ['required', 'string', 'max:100'],
            'numero_serie' => ['required', 'string', 'max:150'],
            'motivo' => self::REGLAS['motivo'],
        ], self::MENSAJES);

        $serie = $this->partidas->editar($tarima, (int) $datos['partida'], $datos['sku'], $datos['numero_serie'], $request->user(), $datos['motivo']);

        return $this->conReimpresion($tarima, $serie, "N/S actualizada: {$serie->numero_serie} (SKU {$serie->registro->sku}).");
    }

    /** Tras agregar o editar: el botón Reimprimir abre la impresión de solo esa etiqueta. */
    private function conReimpresion(Tarima $tarima, NumeroSerie $serie, string $mensaje): RedirectResponse {
        return redirect()->route('tarimas.corregir', $tarima)
            ->with('ok', "{$mensaje} Reimprime su etiqueta y pégala en la pieza.")
            ->with('reimprimir', [
                'url' => route('etiquetas.show', ['tarima' => $tarima, 'piezas' => $serie->id]),
                'numero_serie' => $serie->numero_serie,
            ]);
    }
}
