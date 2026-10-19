<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\InventarioCarga;
use App\Services\BitacoraService;
use App\Services\Exportacion\InventarioExcel;
use App\Services\Inventario\ImportadorInventario;
use App\Services\Inventario\InventarioService;
use App\Support\MudanzaActiva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventarioController extends Controller {
    public function index(Request $request, MudanzaActiva $mudanzaActiva): View {
        $mudanza = $mudanzaActiva->obtener()?->load(['movimientos.origen', 'movimientos.destino']);

        return view('inventarios.index', [
            'mudanza' => $mudanza,
            'almacenes' => $this->almacenes($mudanza?->movimientos ?? collect()),
            'ultimaCarga' => session('carga') ? InventarioCarga::with('almacen')->find(session('carga')) : null,
            'almacenSeleccionado' => (int) old('almacen', $request->query('almacen')),
        ]);
    }

    public function store(Request $request, ImportadorInventario $importador): RedirectResponse {
        $datos = $request->validate([
            'almacen' => ['required', 'integer', 'exists:almacenes,id'],
            'archivo' => ['required', 'file', 'max:'.(int) config('inventarios.tamano_maximo_kb'), 'extensions:'.implode(',', config('inventarios.extensiones'))],
            'reemplazar' => ['sometimes', 'boolean'],
        ], [
            'almacen.*' => 'Elige el almacén al que corresponde el inventario.',
            'archivo.required' => 'Elige el archivo Excel del inventario.',
            'archivo.extensions' => 'El archivo debe ser Excel (.xlsx, .xls) o CSV.',
            'archivo.max' => 'El archivo pesa demasiado (máximo '.round(config('inventarios.tamano_maximo_kb') / 1024).' MB).',
            'archivo.*' => 'No se pudo recibir el archivo. Vuelve a intentarlo.',
        ]);

        $almacen = Almacen::findOrFail($datos['almacen']);
        $carga = $importador->importar($almacen, $request->file('archivo'), $request->user(), $request->boolean('reemplazar'));

        $atencion = $carga->filas_omitidas > 0 || ($carga->observaciones['total_avisos'] ?? 0) > 0;

        return redirect()->route('inventarios.index')
            ->with('carga', $carga->id)
            ->with($atencion ? 'warn' : 'ok', "Inventario del {$almacen->etiqueta} cargado: {$carga->filas_importadas} productos."
                .($atencion ? ' Hay filas que requieren atención: revisa el detalle.' : ''));
    }

    /**
     * Alta de un SKU solo con su código (descripción opcional), para escanear un
     * producto que todavía no viene en el inventario de Excel del almacén.
     */
    public function altaSku(Request $request, InventarioService $inventario, BitacoraService $bitacora): RedirectResponse {
        $datos = $request->validateWithBag('altaSku', [
            'almacen' => ['required', 'integer', 'exists:almacenes,id'],
            'sku' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ], [
            'almacen.*' => 'Elige el almacén donde está el producto.',
            'sku.required' => 'Escribe o escanea el SKU.',
            'sku.max' => 'El SKU admite hasta 100 caracteres.',
            'descripcion.max' => 'La descripción admite hasta 255 caracteres.',
        ]);
        $almacen = Almacen::findOrFail($datos['almacen']);

        try {
            $alta = $inventario->darDeAltaSku($almacen, $datos['sku'], $datos['descripcion'] ?? null, $request->user());
        } catch (ValidationException $e) {
            throw $e->errorBag('altaSku');
        }
        $bitacora->registrar('sku.alta', $request->user(), $alta, ['almacen' => $almacen->codigo, 'sku' => $alta->sku]);

        return redirect()->route('inventarios.index', ['almacen' => $almacen->id])
            ->with('ok', "SKU {$alta->sku} dado de alta en el {$almacen->etiqueta}. Ya se puede elegir en Escaneo; sus N/S quedarán pendientes de revisión porque no vienen en el inventario.");
    }

    public function exportar(Request $request, Almacen $almacen, InventarioExcel $excel, BitacoraService $bitacora): StreamedResponse|RedirectResponse {
        $carga = $almacen->inventarioVigente()->first();
        if (! $carga) {
            return redirect()->route('inventarios.index')->withErrors(['almacen' => "El {$almacen->etiqueta} no tiene inventario cargado."]);
        }

        $bitacora->registrar('inventario.exportado', $request->user(), $carga, ['almacen' => $almacen->codigo, 'carga' => $carga->id]);

        return $excel->descargar($carga);
    }

    /**
     * Primero los almacenes de origen de la mudanza en curso (los que se
     * escanean), luego los de destino y después los demás almacenes registrados.
     */
    private function almacenes(Collection $movimientos): Collection {
        $deLaMudanza = $movimientos->pluck('origen')->merge($movimientos->pluck('destino'))->unique('id');

        return $deLaMudanza
            ->merge(Almacen::whereNotIn('id', $deLaMudanza->pluck('id')->all())->orderBy('codigo')->get())
            ->each(function (Almacen $a) use ($movimientos) {
                $a->load(['inventarioVigente.cargadaPor', 'skusAlta']);
                $a->setAttribute('es_origen', $movimientos->contains('almacen_origen_id', $a->id));
                $a->setAttribute('rutas', $movimientos->filter(fn ($m) => in_array($a->id, [$m->almacen_origen_id, $m->almacen_destino_id], true))->map->nombre->join(', '));
            })
            ->values();
    }
}
