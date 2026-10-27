<?php

namespace Tests\Feature;

use App\Models\Embarque;
use App\Models\Incidencia;
use App\Models\InventarioItem;
use App\Models\NumeroSerie;
use App\Models\Tarima;
use App\Models\User;
use App\Services\Exportacion\ExcelExportador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * Manifiesto del tráiler en Excel: exactamente ocho columnas,
 * en orden, una fila por pieza, con los valores tomados de los registros reales
 * (inventario cargado por Excel, incidencias, N/S capturada).
 */
class ManifiestoTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    private User $op;

    private Tarima $embarque;

    protected function setUp(): void {
        $this->prepararWms();
        Storage::fake('documentos');
        $this->op = $this->operador();

        // Inventario del almacén 58 cargado por Excel, con la posición de ubicación.
        $this->actingAs($this->auditor())->post(route('inventarios.store'), [
            'almacen' => $this->almacen('58')->id,
            'archivo' => $this->excel([
                ['SKU', 'Modelo', 'Descripción', 'Posición de ubicación', 'Número de serie'],
                ['REF-100', 'RF', 'Refrigerador dúplex 25 pies', 'A-01-03', '000123'],
                ['REF-100', 'RF', 'Refrigerador dúplex 25 pies', 'A-01-04', 'NS-2'],
                ['EST-200', 'ES', 'Estufa 6 quemadores', 'B-02-01', 'NS-3'],
            ]),
            'reemplazar' => '0',
        ])->assertSessionHasNoErrors();

        // Embarque: dos piezas encontradas, una no encontrada (incidencia) y una de otro SKU.
        $this->embarque = $this->tarima($this->op);
        $this->activarPadre($this->op, $this->embarque, 'REF-100');
        $this->escanearNs('000123', agregarAlInventario: false)->assertSessionHasNoErrors();
        $this->escanearNs('NS-2', agregarAlInventario: false)->assertSessionHasNoErrors();
        $this->escanearNs('NO-INV', agregarAlInventario: false)->assertSessionHasNoErrors();
        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($this->op, $this->embarque, 'EST-200');
        $this->escanearNs('NS-3', agregarAlInventario: false)->assertSessionHasNoErrors();
        $this->post(route('escaneo.padre.cerrar'));
    }

    private function excel(array $filas): UploadedFile {
        $libro = new Spreadsheet();
        foreach ($filas as $r => $fila) {
            foreach (array_values($fila) as $c => $valor) {
                $libro->getActiveSheet()->getCell([$c + 1, $r + 1])->setValueExplicit($valor);
            }
        }
        $ruta = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'inventario.xlsx', null, null, true);
    }

    private function libro(TestResponse $respuesta): Spreadsheet {
        $ruta = tempnam(sys_get_temp_dir(), 'man');
        file_put_contents($ruta, $respuesta->assertOk()->streamedContent());
        try {
            return IOFactory::load($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    /** Solo una hoja, con exactamente el encabezado y una fila por pieza. */
    private function assertSoloLasOchoColumnas(Spreadsheet $libro, int $piezas): array {
        $this->assertSame(1, $libro->getSheetCount(), 'Sin hojas adicionales.');
        $hoja = $libro->getSheet(0);
        $this->assertSame('H', $hoja->getHighestColumn(), 'Exactamente ocho columnas.');
        $this->assertSame($piezas + 1, $hoja->getHighestRow(), 'Sin títulos ni filas adicionales.');
        $filas = $hoja->toArray(null, false, false);
        $this->assertSame(
            ['Consecutivo', 'Número de parte (SKU)', 'Descripción', 'Cantidad', 'Unidad de medida', 'Categoría', 'Daño', 'Número de serie'],
            $filas[0],
        );
        $this->assertSame(ExcelExportador::MANIFIESTO, $filas[0]);

        return array_slice($filas, 1);
    }

    public function test_el_inventario_guarda_la_posicion_de_ubicacion(): void {
        $this->assertSame(['A-01-03', 'A-01-04', 'B-02-01'], InventarioItem::orderBy('fila')->pluck('posicion')->all());
    }

    /** Guarda el embarque y lo carga completo en un tráiler: su manifiesto trae todas las piezas. */
    private function trailerConTodo(): Embarque {
        if ($trailer = Embarque::first()) {
            return $trailer;
        }
        $this->actingAs($this->op)->postJson(route('tarimas.impresa', $this->embarque))->assertOk();
        $this->postJson(route('tarimas.guardar', $this->embarque))->assertOk();
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'ABC-123'])->assertSessionHasNoErrors();
        $trailer = Embarque::sole();
        $this->actingAs($this->op)->post(route('embarques.cargar', $trailer), ['codigo' => $this->embarque->codigo])->assertSessionHasNoErrors();

        return $trailer;
    }

    public function test_manifiesto_con_los_valores_de_cada_columna(): void {
        // Daño: NS-2 se marcó dañada y al guardar quedó su incidencia de "Producto dañado".
        $this->actingAs($this->op)->post(route('tarimas.anomalias', $this->embarque), [
            'accion' => 'marcar', 'ns' => [NumeroSerie::where('numero_serie', 'NS-2')->value('id')],
        ])->assertSessionHasNoErrors();
        $trailer = $this->trailerConTodo();
        $this->assertSame(1, Incidencia::where('tipo', Incidencia::DANIO)->count());

        $respuesta = $this->get(route('embarques.manifiesto', $trailer))
            ->assertDownload('MUD-0001_TRL-0001_Manifiesto_'.now()->format('Y-m-d').'.xlsx');
        $filas = $this->assertSoloLasOchoColumnas($this->libro($respuesta), 4);

        $this->assertSame([
            [1, 'REF-100', 'Refrigerador dúplex 25 pies', 1, 'PZ', 'A-01-03', 0, '000123'],
            [2, 'REF-100', 'Refrigerador dúplex 25 pies', 1, 'PZ', 'A-01-04', 1, 'NS-2'],
            // Sin coincidencia en el inventario: descripción del SKU, sin posición; su incidencia no es de daño.
            [3, 'REF-100', 'Refrigerador dúplex 25 pies', 1, 'PZ', null, 0, 'NO-INV'],
            [4, 'EST-200', 'Estufa 6 quemadores', 1, 'PZ', 'B-02-01', 0, 'NS-3'],
        ], $filas);
    }

    public function test_dano_descartado_no_cuenta(): void {
        // Incidencia de daño registrada a mano sobre NS-3 y luego descartada ("no era una incidencia").
        $this->trailerConTodo();
        $sup = $this->auditor();
        $this->actingAs($sup)->post(route('incidencias.store'), ['tipo' => Incidencia::DANIO, 'titulo' => 'Golpe', 'piezas' => 'NS-3'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->filaDe('NS-3')[6]);

        $this->actingAs($sup)->post(route('incidencias.resolver', Incidencia::where('tipo', Incidencia::DANIO)->sole()), [
            'tipo_resolucion' => 'descartada', 'resolucion' => 'El golpe era del empaque, no de la pieza',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->filaDe('NS-3')[6]);

        // Un daño resuelto de otra forma (p. ej. aceptado) sigue reportándose.
        $this->actingAs($sup)->post(route('incidencias.store'), ['tipo' => Incidencia::DANIO, 'titulo' => 'Rayón', 'piezas' => '000123'])->assertSessionHasNoErrors();
        $this->actingAs($sup)->post(route('incidencias.resolver', Incidencia::where('titulo', 'Rayón')->sole()), [
            'tipo_resolucion' => 'localizado', 'resolucion' => 'Se documentó el rayón',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->filaDe('000123')[6]);
    }

    private function filaDe(string $ns): array {
        $filas = $this->libro($this->actingAs($this->op)->get(route('embarques.manifiesto', $this->trailerConTodo())))->getSheet(0)->toArray(null, false, false);

        return collect($filas)->firstWhere(7, $ns);
    }

    public function test_el_embarque_ya_no_tiene_manifiesto_propio(): void {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('export.tarima'));
        $this->actingAs($this->op)->get(route('wms'))->assertOk()->assertDontSee('> Manifiesto</a>', false);
    }

    public function test_manifiesto_del_trailer_solo_lo_cargado_y_numerado_desde_1(): void {
        $this->postJson(route('tarimas.impresa', $this->embarque))->assertOk();
        $this->postJson(route('tarimas.guardar', $this->embarque))->assertOk();
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'ABC-123'])->assertSessionHasNoErrors();
        $trailer = Embarque::sole();
        foreach (['NS-3', 'NS-2'] as $ns) {
            $this->actingAs($this->op)->post(route('embarques.cargar', $trailer), ['codigo' => $ns])->assertSessionHasNoErrors();
        }

        $respuesta = $this->get(route('embarques.manifiesto', $trailer))
            ->assertDownload('MUD-0001_TRL-0001_Manifiesto_'.now()->format('Y-m-d').'.xlsx');
        $filas = $this->assertSoloLasOchoColumnas($this->libro($respuesta), 2);

        $this->assertSame([
            [1, 'REF-100', 'Refrigerador dúplex 25 pies', 1, 'PZ', 'A-01-04', 0, 'NS-2'],
            [2, 'EST-200', 'Estufa 6 quemadores', 1, 'PZ', 'B-02-01', 0, 'NS-3'],
        ], $filas);
    }

    public function test_el_excel_de_la_mudanza_conserva_su_formato_y_consecutivo_fijo(): void {
        $this->postJson(route('tarimas.impresa', $this->embarque))->assertOk();
        $this->postJson(route('tarimas.guardar', $this->embarque))->assertOk();

        $hoja = $this->libro($this->get(route('export.todas')))->getSheet(0);
        $this->assertSame(ExcelExportador::ENCABEZADOS, $hoja->rangeToArray('A1:J1', null, false, false)[0]);
    }
}
