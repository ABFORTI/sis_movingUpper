<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\Bitacora;
use App\Models\InventarioCarga;
use App\Models\InventarioItem;
use App\Models\Registro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** Inventario por almacén: carga del Excel, selector de SKU y exportación. */
class InventarioTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    protected function setUp(): void {
        $this->prepararWms();
        Storage::fake('documentos');
    }

    /** Excel en disco con esas filas (la primera es el encabezado). */
    private function excel(array $filas, string $nombre = 'inventario.xlsx', bool $comoTexto = false): UploadedFile {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        foreach ($filas as $r => $fila) {
            foreach (array_values($fila) as $c => $valor) {
                if ($valor === null) {
                    continue;
                }
                $celda = $hoja->getCell([$c + 1, $r + 1]);
                $comoTexto && is_string($valor) ? $celda->setValueExplicit($valor) : $celda->setValue($valor);
            }
        }
        $ruta = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    private function cargar(UploadedFile $archivo, string $almacen = '58', bool $reemplazar = false, $quien = null): TestResponse {
        return $this->actingAs($quien ?? $this->auditor())->post(route('inventarios.store'), [
            'almacen' => $this->almacen($almacen)->id,
            'archivo' => $archivo,
            'reemplazar' => $reemplazar ? '1' : '0',
        ]);
    }

    private const ENCABEZADO = ['SKU', 'Modelo', 'Descripción', 'Número de serie del fabricante', 'Número de serie', 'Número de serie de lote'];

    public function test_carga_el_inventario_de_un_almacen_con_los_tres_numeros_de_serie(): void {
        // Encabezados en otro orden, con mayúsculas, sin acentos y espacios de más: se reconocen por nombre.
        $archivo = $this->excel([
            ['Reporte de inventario'],
            [],
            ['numero de serie de lote', '  DESCRIPCION ', 'Sku', 'Modelo', 'Número  de Serie', 'Numero de serie del fabricante'],
            ['L-01', 'Refrigerador 2 puertas', 'REF-100', 'RF-2P', 'NS-AAA', 'FAB-001'],
            ['L-01', 'Refrigerador 2 puertas', 'REF-100', 'RF-2P', 'NS-BBB', null],
            [null, 'Estufa', 'EST-200', null, null, '000123'],
        ]);

        $this->cargar($archivo)->assertRedirect(route('inventarios.index'))->assertSessionHas('ok');

        $carga = InventarioCarga::sole();
        $this->assertSame(['58', 3, 3, 0, true], [$carga->almacen->codigo, $carga->filas_leidas, $carga->filas_importadas, $carga->filas_omitidas, $carga->vigente]);
        $this->assertTrue(Storage::disk('documentos')->exists($carga->archivo), 'Se conserva el archivo original.');
        $this->assertSame(hash('sha256', Storage::disk('documentos')->get($carga->archivo)), $carga->sha256);

        $item = InventarioItem::where('sku', 'REF-100')->orderBy('id')->first();
        $this->assertSame(['RF-2P', 'Refrigerador 2 puertas', 'FAB-001', 'NS-AAA', 'L-01'], [$item->modelo, $item->descripcion, $item->ns_fabricante, $item->numero_serie, $item->ns_lote]);
        // El número de serie numérico conserva sus ceros a la izquierda cuando viene como texto…
        $this->assertSame('000123', InventarioItem::where('sku', 'EST-200')->value('ns_fabricante'));
        $this->assertSame(1, Bitacora::where('accion', 'inventario.cargado')->count());

        $this->get(route('inventarios.index'))->assertOk()->assertSee('Almacén 58')->assertSee('Con inventario')->assertSee('3 productos');
    }

    public function test_filas_con_errores_se_omiten_y_se_informan_sin_datos_incorrectos(): void {
        $archivo = $this->excel([
            self::ENCABEZADO,
            ['SKU-1', null, 'Bomba', 'F-1', 'S-1', null],
            [null, null, 'Sin SKU', 'F-2', null, null],              // sin SKU
            ['SKU-2', null, 'Sin serie', null, null, null],          // sin ningún N/S: se importa solo con su SKU
            ['SKU-1', null, 'Bomba', 'F-1', 'S-1', null],            // repetida exacta
            ['SKU-3', null, 'Motor', null, 'S-1', null],             // N/S ya usada en otra fila
            [str_repeat('X', 101), null, 'SKU largo', null, 'S-9', null],
        ]);

        $this->cargar($archivo)->assertSessionHas('warn');

        $carga = InventarioCarga::sole();
        $this->assertSame([6, 3, 3], [$carga->filas_leidas, $carga->filas_importadas, $carga->filas_omitidas]);
        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3'], InventarioItem::orderBy('id')->pluck('sku')->all());
        $this->assertSame(2, $carga->observaciones['total_errores']);
        $this->assertSame(3, $carga->observaciones['total_avisos']);
        $this->assertStringContainsString('Fila 3: no tiene SKU', implode(' ', $carga->observaciones['errores']));
        $this->assertStringContainsString('La fila 4 no tiene número de serie', implode(' ', $carga->observaciones['avisos']));
        $this->assertStringContainsString('repite la fila 2', implode(' ', $carga->observaciones['avisos']));
        $this->assertStringContainsString('S-1 también está en la fila 2', implode(' ', $carga->observaciones['avisos']));

        $this->get(route('inventarios.index'))->assertSee('filas no se importaron')->assertSee('Fila 3: no tiene SKU');
    }

    public function test_un_excel_sin_numeros_de_serie_se_importa_con_su_sku(): void {
        // Sin ninguna columna de N/S: basta el SKU. Filas iguales sin N/S son piezas distintas.
        $this->cargar($this->excel([
            ['SKU', 'Descripción'],
            ['COMP-1', 'Compresor'],
            ['COMP-1', 'Compresor'],
            ['MOT-2', 'Motor'],
        ]))->assertSessionHasNoErrors()->assertSessionHas('warn');

        $carga = InventarioCarga::sole();
        $this->assertSame([3, 3, 0], [$carga->filas_leidas, $carga->filas_importadas, $carga->filas_omitidas]);
        $this->assertSame(['COMP-1', 'COMP-1', 'MOT-2'], InventarioItem::orderBy('id')->pluck('sku')->all());
        $this->assertNull(InventarioItem::whereNotNull('numero_serie')->orWhereNotNull('ns_fabricante')->orWhereNotNull('ns_lote')->first());
        $this->assertStringContainsString('3 filas (p. ej. fila 2) no tienen número de serie', implode(' ', $carga->observaciones['avisos']));

        // El SKU se puede elegir al escanear; su N/S no coincide y queda pendiente de revisión.
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->actingAs($operador)->post(route('escaneo.padre'), ['sku' => 'COMP-1', 'tarima' => $tarima->uuid])->assertSessionHasNoErrors();
        $this->postJson(route('escaneo.ns'), ['numero_serie' => 'CP-1'])->assertOk()->assertJson(['estado' => 'registrada']);
        $this->assertTrue(\App\Models\NumeroSerie::sole()->pendienteRevision());
    }

    public function test_estructura_invalida_se_rechaza_completa(): void {
        $this->cargar($this->excel([['Producto', 'Texto'], ['A', 'B']]))->assertSessionHasErrors('archivo');
        $this->cargar($this->excel([['SKU', 'Número de serie', 'N/S'], ['A', '1', '2']]))->assertSessionHasErrors('archivo');
        $this->cargar(UploadedFile::fake()->create('inventario.pdf', 10, 'application/pdf'))->assertSessionHasErrors('archivo');
        $this->cargar(UploadedFile::fake()->createWithContent('inventario.xlsx', 'no es un excel'))->assertSessionHasErrors('archivo');

        $this->assertSame(0, InventarioCarga::count());
        $this->assertSame(0, InventarioItem::count());
    }

    public function test_un_inventario_no_sobrescribe_el_de_otro_almacen(): void {
        $this->cargar($this->excel([self::ENCABEZADO, ['SKU-58', null, 'Del 58', null, 'S-58', null]]), '58');
        $this->cargar($this->excel([self::ENCABEZADO, ['SKU-3', null, 'Del 3', null, 'S-3', null]]), '3');

        $this->assertSame(2, InventarioCarga::where('vigente', true)->count());
        $this->assertSame(['SKU-58'], InventarioItem::where('almacen_id', $this->almacen('58')->id)->pluck('sku')->all());
        $this->assertSame(['SKU-3'], InventarioItem::where('almacen_id', $this->almacen('3')->id)->pluck('sku')->all());

        // Un archivo con columna de almacén que no corresponde se rechaza completo.
        $this->cargar($this->excel([['Almacén', ...self::ENCABEZADO], ['3', 'SKU-X', null, null, null, 'S-X', null]]), '58', true)
            ->assertSessionHasErrors('archivo');
        $this->assertSame(['SKU-58'], InventarioItem::where('almacen_id', $this->almacen('58')->id)->whereHas('carga', fn ($q) => $q->where('vigente', true))->pluck('sku')->all());
    }

    public function test_reemplazar_exige_confirmacion_y_conserva_el_historico(): void {
        $this->cargar($this->excel([self::ENCABEZADO, ['SKU-A', null, 'Uno', null, 'S-1', null]]));

        $this->cargar($this->excel([self::ENCABEZADO, ['SKU-B', null, 'Dos', null, 'S-2', null]]))->assertSessionHasErrors('archivo');
        $this->assertSame(1, InventarioCarga::count());

        $this->cargar($this->excel([self::ENCABEZADO, ['SKU-B', null, 'Dos', null, 'S-2', null]]), reemplazar: true)->assertSessionHasNoErrors();
        $this->assertSame(2, InventarioCarga::count());
        $this->assertSame(1, InventarioCarga::where('vigente', true)->count());
        $this->assertNotNull(InventarioCarga::where('vigente', false)->sole()->reemplazada_at);
        $this->assertSame(2, InventarioItem::count(), 'Los renglones de la carga anterior se conservan.');
    }

    public function test_solo_supervision_carga_pero_todos_consultan_y_exportan(): void {
        $operador = $this->operador();
        $this->cargar($this->excel([self::ENCABEZADO, ['SKU-A', null, 'Uno', null, 'S-1', null]]), quien: $operador)->assertForbidden();
        $this->assertSame(0, InventarioCarga::count());

        $this->cargar($this->excel([self::ENCABEZADO, ['SKU-A', null, 'Uno', null, 'S-1', null]]), quien: $this->admin())->assertSessionHasNoErrors();
        $this->actingAs($operador)->get(route('inventarios.index'))->assertOk()->assertDontSee('Cargar inventario (Excel)')->assertSee('Excel del inventario');
        $this->actingAs($operador)->get(route('inventarios.exportar', $this->almacen('58')))->assertOk();
    }

    public function test_exporta_el_inventario_del_almacen_con_lo_ya_registrado(): void {
        $this->enInventario([
            ['sku' => 'SKU-A', 'modelo' => 'MA', 'descripcion' => 'Bomba', 'ns_fabricante' => 'F-1', 'numero_serie' => 'S-1', 'ns_lote' => 'L-1'],
            ['sku' => 'SKU-A', 'modelo' => 'MA', 'descripcion' => 'Bomba', 'numero_serie' => 'S-2'],
        ]);
        $this->enInventario([['sku' => 'OTRO-ALM', 'numero_serie' => 'Z-1']], '3');
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima, 'SKU-A');
        $this->escanearNs('F-1', agregarAlInventario: false)->assertSessionHasNoErrors();

        $respuesta = $this->get(route('inventarios.exportar', $this->almacen('58')))->assertOk()
            ->assertDownload('Inventario_Almacen-58_'.now()->format('Y-m-d').'.xlsx');
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($ruta, $respuesta->streamedContent());
        $libro = IOFactory::load($ruta);
        @unlink($ruta);

        $filas = $libro->getSheet(0)->toArray(null, false, false);
        $this->assertSame(\App\Services\Exportacion\InventarioExcel::ENCABEZADOS, $filas[0]);
        $this->assertSame(['SKU-A', 'MA', 'Bomba', 'F-1', 'S-1', 'L-1', 'Sí', 'F-1', 'MUD-0001 · E-001'], array_slice($filas[1], 0, 9));
        $this->assertSame(['SKU-A', 'S-2', 'No'], [$filas[2][0], $filas[2][4], $filas[2][6]]);
        $this->assertCount(3, $filas, 'Solo el inventario del almacén 58.');
        $this->assertSame('Almacén 58', $libro->getSheetByName('Almacén')->getCell('B1')->getValue());
        // El Excel de inventario no usa el formato de incidencias.
        $this->assertSame('none', $libro->getSheet(0)->getStyle('E2')->getFill()->getFillType());
    }

    // ---------- Selector de SKU ----------

    public function test_el_selector_muestra_solo_los_sku_del_almacen_de_la_tarima(): void {
        $this->enInventario([
            ['sku' => 'REF-100', 'modelo' => 'RF-2P', 'descripcion' => 'Refrigerador', 'numero_serie' => 'A1'],
            ['sku' => 'REF-100', 'modelo' => 'RF-2P', 'descripcion' => 'Refrigerador', 'numero_serie' => 'A2'],
            ['sku' => 'EST-200', 'descripcion' => 'Estufa industrial', 'numero_serie' => 'B1'],
        ]);
        $this->enInventario([['sku' => 'SOLO-EN-3', 'descripcion' => 'Otro almacén', 'numero_serie' => 'C1']], '3');
        $operador = $this->operador();
        $tarima = $this->tarima($operador);

        $this->actingAs($operador)->getJson(route('escaneo.skus', ['tarima' => $tarima->uuid]))->assertOk()
            ->assertJsonPath('inventario', true)
            ->assertJsonPath('almacen', 'Almacén 58')
            ->assertJsonPath('opciones', [
                ['sku' => 'EST-200', 'modelo' => null, 'descripcion' => 'Estufa industrial', 'piezas' => 1, 'alta' => false],
                ['sku' => 'REF-100', 'modelo' => 'RF-2P', 'descripcion' => 'Refrigerador', 'piezas' => 2, 'alta' => false],
            ]);
        // Por código, por modelo o por descripción.
        $this->getJson(route('escaneo.skus', ['tarima' => $tarima->uuid, 'q' => 'ref']))->assertJsonCount(1, 'opciones')->assertJsonPath('opciones.0.sku', 'REF-100');
        $this->getJson(route('escaneo.skus', ['tarima' => $tarima->uuid, 'q' => 'RF-2']))->assertJsonPath('opciones.0.sku', 'REF-100');
        $this->getJson(route('escaneo.skus', ['tarima' => $tarima->uuid, 'q' => 'industrial']))->assertJsonPath('opciones.0.sku', 'EST-200');
        $this->getJson(route('escaneo.skus', ['tarima' => $tarima->uuid, 'q' => 'SOLO-EN-3']))->assertJsonCount(0, 'opciones');
    }

    public function test_sin_inventario_se_avisa_y_no_se_puede_activar_un_sku(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);

        $this->actingAs($operador)->getJson(route('escaneo.skus', ['tarima' => $tarima->uuid]))->assertOk()
            ->assertJsonPath('inventario', false)
            ->assertJsonPath('opciones', [])
            ->assertJsonPath('mensaje', 'No hay inventario cargado para el Almacén 58. Pide a supervisión que lo cargue.');

        $this->post(route('escaneo.padre'), ['sku' => 'CUALQUIERA', 'tarima' => $tarima->uuid])->assertSessionHasErrors('sku');
        $this->assertStringContainsString('No hay inventario cargado para el Almacén 58', session('errors')->first('sku'));
        $this->assertSame(0, Registro::count());
    }

    public function test_el_servidor_valida_el_sku_y_toma_la_descripcion_del_inventario(): void {
        $this->enInventario([['sku' => 'REF-100', 'descripcion' => 'Refrigerador 2 puertas', 'numero_serie' => 'A1']]);
        $this->enInventario([['sku' => 'SOLO-EN-3', 'descripcion' => 'Otro', 'numero_serie' => 'C1']], '3');
        $operador = $this->operador();
        $tarima = $this->tarima($operador);

        // Un SKU que el selector no ofrece (de otro almacén o inventado) se rechaza.
        $this->actingAs($operador)->post(route('escaneo.padre'), ['sku' => 'SOLO-EN-3', 'tarima' => $tarima->uuid])->assertSessionHasErrors('sku');
        $this->post(route('escaneo.padre'), ['sku' => 'INVENTADO', 'tarima' => $tarima->uuid])->assertSessionHasErrors('sku');

        // La descripción que mande el navegador se ignora; se escribe el SKU con otro formato.
        $this->post(route('escaneo.padre'), ['sku' => ' ref-100 ', 'descripcion' => 'Manipulada', 'tarima' => $tarima->uuid])->assertSessionHasNoErrors();
        $registro = Registro::sole();
        $this->assertSame(['REF-100', 'Refrigerador 2 puertas'], [$registro->sku, $registro->descripcion]);
    }

    public function test_tarimas_de_otro_movimiento_usan_el_inventario_de_su_almacen(): void {
        $this->mudanza->movimientos()->create([
            'almacen_origen_id' => $this->almacen('5')->id,
            'almacen_destino_id' => $this->almacen('7')->id,
        ]);
        $this->enInventario([['sku' => 'DEL-58', 'numero_serie' => 'A']], '58');
        $this->enInventario([['sku' => 'DEL-5', 'numero_serie' => 'B']], '5');
        $operador = $this->operador();
        [$m58, $m5] = $this->mudanza->movimientos()->orderBy('id')->get()->all();
        $t58 = $this->tarima($operador, $m58->id);
        $t5 = $this->tarima($operador, $m5->id);

        $this->actingAs($operador)->getJson(route('escaneo.skus', ['tarima' => $t58->uuid]))->assertJsonPath('opciones.0.sku', 'DEL-58')->assertJsonCount(1, 'opciones');
        $this->getJson(route('escaneo.skus', ['tarima' => $t5->uuid]))->assertJsonPath('opciones.0.sku', 'DEL-5')->assertJsonCount(1, 'opciones');
        $this->post(route('escaneo.padre'), ['sku' => 'DEL-58', 'tarima' => $t5->uuid])->assertSessionHasErrors('sku');
        $this->post(route('escaneo.padre'), ['sku' => 'DEL-5', 'tarima' => $t5->uuid])->assertSessionHasNoErrors();
    }

    public function test_la_pantalla_de_captura_tiene_selector_y_descripcion_derivada(): void {
        $operador = $this->operador();
        $this->tarima($operador);

        $this->actingAs($operador)->get(route('wms'))->assertOk()
            ->assertSee('role="combobox"', false)
            ->assertSee('data-sku-descripcion', false)
            ->assertSee('Descripción')->assertSee('obtenido del inventario')
            ->assertDontSee('name="descripcion"', false)
            ->assertDontSee('name="destino_id"', false);
    }
}
