<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgregarNumeroSerieRequest;
use App\Http\Requests\RegistrarSkuRequest;
use App\Models\InventarioItem;
use App\Models\NumeroSerie;
use App\Models\Registro;
use App\Models\Tarima;
use App\Services\EscaneoService;
use App\Services\Inventario\InventarioService;
use App\Support\SkuActivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EscaneoController extends Controller {
    public function __construct(
        private readonly EscaneoService $escaneo,
        private readonly SkuActivo $skuActivo,
    ) { }

    /**
     * Opciones del selector de SKU: las del inventario del almacén de origen de
     * la tarima elegida, filtradas por lo escrito. Pocas a la vez (nunca el
     * inventario completo).
     */
    public function skus(Request $request, InventarioService $inventario): JsonResponse {
        $datos = $request->validate([
            'tarima' => ['required', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $tarima = Tarima::with('movimiento.origen')->where('uuid', $datos['tarima'])->firstOrFail();
        abort_unless($request->user()->can('view', $tarima), 403);

        $almacen = $tarima->almacenOrigen();
        $carga = $inventario->vigente($almacen);

        return response()->json([
            'almacen' => $almacen?->etiqueta,
            'inventario' => $carga !== null,
            'mensaje' => match (true) {
                $almacen === null => "El {$tarima->nombre} no tiene almacén de origen.",
                $carga === null => "No hay inventario cargado para el {$almacen->etiqueta}. Pide a supervisión que lo cargue.",
                default => null,
            },
            'opciones' => $carga ? $inventario->buscarSkus($carga, $datos['q'] ?? null)->values() : [],
        ]);
    }

    public function activarPadre(RegistrarSkuRequest $request): RedirectResponse {
        if ($this->skuActivo->obtener()) {
            return back()->withErrors(['sku' => 'Cierra el SKU activo antes de abrir otro.']);
        }

        $tarima = $request->tarima();
        $registro = $this->escaneo->registrarSkuPadre($tarima, $request->validated('sku'), $request->user());
        $this->skuActivo->activar($registro);
        $request->session()->put('wms.tarima', $tarima->uuid);
        $conversion = $registro->fueConvertido() ? " Conversión aplicada: {$registro->sku_original} → {$registro->sku}." : '';

        if (! $registro->wasRecentlyCreated) {
            $n = $registro->numerosSerie()->count();

            return redirect()->route('wms')->with('ok', "SKU {$registro->sku} reabierto en {$tarima->nombre} (lleva {$n} N/S). Sigue con las N/S.{$conversion}");
        }

        return redirect()->route('wms')->with('ok', "SKU {$registro->sku} activo en {$tarima->nombre}. Escanea o escribe las N/S.{$conversion}");
    }

    public function cerrarPadre(): RedirectResponse {
        $registro = $this->skuActivo->obtener();
        $this->skuActivo->cerrar();

        return redirect()->route('wms')->with('warn', $registro ? "SKU {$registro->sku} cerrado." : null);
    }

    /**
     * N/S escaneada o escrita a mano. Responde JSON al handheld (escaneo
     * continuo) y redirige cuando el formulario se envía sin JavaScript. Una N/S
     * que no está en el inventario se registra con su incidencia sin preguntar.
     */
    public function agregarNumeroSerie(AgregarNumeroSerieRequest $request): RedirectResponse|JsonResponse {
        $padre = $this->skuActivo->obtener();
        if (! $padre) {
            throw ValidationException::withMessages(['numero_serie' => 'No hay SKU activo (pudo haberse vaciado el embarque).']);
        }

        if ($request->user()->cannot('trabajar', $padre->tarima)) {
            $this->skuActivo->cerrar();
            abort(403);
        }

        $r = $this->escaneo->agregarNumeroSerie($padre, $request->validated('numero_serie'), $request->user());
        $total = $padre->numerosSerie()->count();

        $mensaje = match (true) {
            $r['estado'] === EscaneoService::REPETIDA => "La N/S {$r['numero_serie']} ya estaba registrada en este SKU. No se duplicó.",
            $r['incidencia'] !== null => "N/S {$r['numero_serie']} no está en el inventario: se registró y quedó pendiente de revisión (incidencia {$r['incidencia']->folio}). {$total} en total.",
            default => "N/S {$r['numero_serie']} encontrada en el inventario (".InventarioItem::CAMPOS_SERIE[$r['coincidencia']]."). {$total} en total.",
        };

        if ($request->expectsJson()) {
            return response()->json([
                'estado' => $r['estado'],
                'mensaje' => $mensaje,
                'pista' => $r['pista'],
                'numero_serie' => $r['numero_serie'],
                'numero_serie_id' => $r['serie']?->id,
                'coincidencia' => $r['coincidencia'] ? InventarioItem::CAMPOS_SERIE[$r['coincidencia']] : null,
                'incidencia' => $r['incidencia'] ? ['folio' => $r['incidencia']->folio, 'url' => route('incidencias.show', $r['incidencia'])] : null,
                'registro' => $padre->id,
                'tarima' => $padre->tarima->uuid,
                'total_registro' => $total,
                'piezas_tarima' => $padre->tarima->fresh()->piezas(),
                // Piezas del embarque sin etiqueta impresa (una etiqueta por pieza).
                'etiquetas_pendientes' => NumeroSerie::whereHas('registro', fn ($q) => $q->where('tarima_id', $padre->tarima_id))->whereNull('etiqueta_impresa_at')->count(),
            ]);
        }

        return redirect()->route('wms')->with($r['estado'] === EscaneoService::REPETIDA || $r['incidencia'] ? 'warn' : 'ok', $mensaje);
    }

    /**
     * "Editar números de serie" → Agregar: una N/S a ese modelo del embarque,
     * con las mismas validaciones que el escaneo.
     */
    public function agregarAlModelo(Request $request, Tarima $tarima, Registro $registro): RedirectResponse {
        $ns = $request->validate(['nueva_ns' => ['required', 'string', 'max:150']], ['nueva_ns.*' => 'Escanea o escribe la N/S que quieres agregar.'])['nueva_ns'];

        $r = $this->enPanel(fn () => $this->escaneo->agregarAlModelo($tarima, $registro, $ns, $request->user()));
        $mensaje = match (true) {
            $r['estado'] === EscaneoService::REPETIDA => "La N/S {$r['numero_serie']} ya estaba en el SKU {$registro->sku}. No se duplicó.",
            $r['incidencia'] !== null => "N/S {$r['numero_serie']} agregada al SKU {$registro->sku}. No está en el inventario: quedó pendiente de revisión ({$r['incidencia']->folio}).",
            default => "N/S {$r['numero_serie']} agregada al SKU {$registro->sku}.",
        };

        return redirect()->route('wms')->with($r['estado'] === EscaneoService::REPETIDA || $r['incidencia'] ? 'warn' : 'ok', $mensaje)->with('panel', $registro->id);
    }

    /** "Editar números de serie" → Editar: corrige el número de serie, el SKU o ambos. */
    public function editarNumeroSerie(Request $request, Tarima $tarima, NumeroSerie $numeroSerie): RedirectResponse {
        $datos = $request->validate([
            'nuevo_sku' => ['required', 'string', 'max:100'],
            'nueva_ns' => ['required', 'string', 'max:150'],
        ], ['nuevo_sku.*' => 'Elige el SKU de la pieza.', 'nueva_ns.*' => 'Escribe la N/S (hasta 150 caracteres).']);

        $r = $this->enPanel(fn () => $this->escaneo->editarNumeroSerie($tarima, $numeroSerie, $datos['nuevo_sku'], $datos['nueva_ns'], $request->user()));
        $serie = $r['serie'];
        $mensaje = "N/S corregida: {$r['antes']['numero_serie']} (SKU {$r['antes']['sku']}) → {$serie->numero_serie} (SKU {$serie->registro->sku}). Imprime su etiqueta antes de guardar."
            .($r['incidencia'] ? " No está en el inventario: quedó pendiente de revisión ({$r['incidencia']->folio})." : '');

        return redirect()->route('wms')->with($r['incidencia'] ? 'warn' : 'ok', $mensaje)->with('panel', $serie->registro_id);
    }

    /**
     * Los errores de las acciones del panel se muestran dentro del panel (campo
     * "anomalia"), no en el campo de escaneo principal.
     */
    private function enPanel(callable $accion): mixed {
        try {
            return $accion();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['anomalia' => collect($e->errors())->flatten()->first()]);
        }
    }

    /**
     * Marcar o quitar daño/anomalía en N/S de la tarima, antes de guardarla, o
     * eliminar las N/S elegidas en las casillas (solo sin etiquetas impresas).
     * Al guardar la tarima, cada N/S marcada abre su incidencia de producto dañado.
     */
    public function anomalias(Request $request, Tarima $tarima): RedirectResponse|JsonResponse {
        $datos = $request->validate([
            'accion' => ['required', 'in:marcar,quitar,eliminar'],
            'ns' => ['array', 'max:500'],
            'ns.*' => ['integer'],
            'numero_serie' => ['nullable', 'string', 'max:150'],
            'nota' => ['nullable', 'string', 'max:1000'],
        ], ['nota.max' => 'La descripción admite hasta 1000 caracteres.']);
        $panel = $request->integer('registro_panel') ?: null;

        if ($datos['accion'] === 'eliminar') {
            $numeros = $this->escaneo->eliminarNumerosSerie($tarima, array_map('intval', $datos['ns'] ?? []), $request->user());
            $mensaje = (count($numeros) === 1 ? 'Se eliminó la N/S ' : 'Se eliminaron las N/S ').implode(', ', $numeros)." del {$tarima->nombre}.";

            return $request->expectsJson()
                ? response()->json(['mensaje' => $mensaje, 'numeros_serie' => $numeros])
                : redirect()->route('wms')->with('ok', $mensaje)->with('panel', $panel);
        }

        $marcar = $datos['accion'] === 'marcar';
        $numeros = $this->escaneo->marcarAnomalias(
            $tarima, array_map('intval', $datos['ns'] ?? []), $datos['numero_serie'] ?? null, $datos['nota'] ?? null, $marcar, $request->user(),
        );
        $lista = implode(', ', $numeros);
        $mensaje = $marcar
            ? "Daño marcado en N/S {$lista}. Se registrará como incidencia de producto dañado al guardar el {$tarima->nombre}."
            : "Se quitó la marca de daño de N/S {$lista}.";

        return $request->expectsJson()
            ? response()->json(['mensaje' => $mensaje, 'numeros_serie' => $numeros])
            : redirect()->route('wms')->with('ok', $mensaje)->with('panel', $panel);
    }
}
