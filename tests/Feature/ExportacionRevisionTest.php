<?php

namespace Tests\Feature;

use App\Models\Embarque;
use App\Models\Entrega;
use App\Models\Incidencia;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * En los Excel de entrega y de embarque, la celda del número de serie con una
 * incidencia abierta de "no encontrado en inventario" va con relleno amarillo,
 * sin texto ni símbolos extra. Nada más cambia.
 */
class ExportacionRevisionTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    private User $op;

    private User $sup;

    protected function setUp(): void {
        $this->prepararWms();
        Storage::fake('documentos');
        $this->op = $this->operador();
        $this->sup = $this->auditor();

        $this->enInventario([['sku' => 'REF-100', 'descripcion' => 'Refrigerador', 'numero_serie' => 'OK-1']]);
        $tarima = $this->tarima($this->op);
        $this->activarPadre($this->op, $tarima, 'REF-100');
        $this->escanearNs('OK-1', agregarAlInventario: false)->assertSessionHasNoErrors();
        $this->escanearNs('NO-INV', agregarAlInventario: false)->assertSessionHasNoErrors();
        $this->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();
    }

    private function libroDe(TestResponse|string $origen): Spreadsheet {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($ruta, is_string($origen) ? $origen : $origen->assertOk()->streamedContent());
        try {
            return IOFactory::load($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    private function amarilla(Worksheet $hoja, string $celda): bool {
        $relleno = $hoja->getStyle($celda)->getFill();

        return $relleno->getFillType() === Fill::FILL_SOLID && $relleno->getStartColor()->getRGB() === 'FFFF00';
    }

    /** Fila (1 = encabezado) donde está la N/S, en la columna dada. */
    private function fila(Worksheet $hoja, string $columna, string $ns): int {
        foreach ($hoja->getRowIterator(2) as $fila) {
            if ($hoja->getCell($columna.$fila->getRowIndex())->getValue() === $ns) {
                return $fila->getRowIndex();
            }
        }
        $this->fail("No está la N/S {$ns}.");
    }

    /** Solo la celda de N/S de la pieza pendiente va en amarillo, con su valor original. */
    private function assertSoloCeldaNsAmarilla(Worksheet $hoja, string $columnaNs): void {
        $pendiente = $this->fila($hoja, $columnaNs, 'NO-INV');
        $normal = $this->fila($hoja, $columnaNs, 'OK-1');

        $this->assertTrue($this->amarilla($hoja, $columnaNs.$pendiente), "{$columnaNs}{$pendiente} debería ir en amarillo.");
        $this->assertSame('NO-INV', $hoja->getCell($columnaNs.$pendiente)->getValue(), 'Sin texto ni símbolos agregados.');
        $this->assertFalse($this->amarilla($hoja, $columnaNs.$normal), 'Una N/S sin incidencia conserva su presentación.');
        foreach (range('A', $hoja->getHighestColumn()) as $columna) {
            if ($columna !== $columnaNs) {
                $this->assertFalse($this->amarilla($hoja, $columna.$pendiente), "No se colorea toda la fila ({$columna}{$pendiente}).");
            }
        }
        $this->assertFalse($this->amarilla($hoja, $columnaNs.'1'));
    }

    public function test_la_entrega_marca_en_amarillo_solo_la_celda_de_la_ns_pendiente(): void {
        $this->actingAs($this->sup)->post(route('entregas.store'), ['mudanza' => $this->mudanza->id])->assertSessionHasNoErrors();
        $entrega = Entrega::sole();
        $hoja = $this->libroDe(Storage::disk('documentos')->get($entrega->archivo))->getSheetByName('Inventario');

        $this->assertSame('Número de serie', $hoja->getCell('G1')->getValue());
        $this->assertSoloCeldaNsAmarilla($hoja, 'G');
        // La estructura no cambia: misma cantidad de columnas que el encabezado.
        $this->assertSame('K', $hoja->getHighestColumn());
    }

    public function test_el_manifiesto_y_el_reporte_final_del_embarque_tambien(): void {
        $this->actingAs($this->sup)->post(route('embarques.store'), ['placas' => 'ABC-123'])->assertSessionHasNoErrors();
        $embarque = Embarque::sole();
        $this->actingAs($this->op)->post(route('embarques.cargar', $embarque), ['codigo' => 'E-001'])->assertSessionHasNoErrors();

        $hoja = $this->libroDe($this->actingAs($this->sup)->get(route('embarques.reporte')))->getSheetByName('Piezas');
        $this->assertSame('Número de serie', $hoja->getCell('D1')->getValue());
        $this->assertSoloCeldaNsAmarilla($hoja, 'D');

        // Manifiesto del tráiler: la N/S va en la columna H.
        $hoja = $this->libroDe($this->actingAs($this->sup)->get(route('embarques.manifiesto', $embarque)))->getSheet(0);
        $this->assertSame('Número de serie', $hoja->getCell('H1')->getValue());
        $this->assertSoloCeldaNsAmarilla($hoja, 'H');
    }

    public function test_el_excel_de_la_mudanza_tambien_y_al_resolver_desaparece(): void {
        $hoja = $this->libroDe($this->actingAs($this->sup)->get(route('export.todas')))->getSheet(0);
        $this->assertSoloCeldaNsAmarilla($hoja, 'G');

        $this->actingAs($this->sup)->post(route('incidencias.resolver', Incidencia::sole()), [
            'tipo_resolucion' => 'localizado', 'resolucion' => 'Está en el inventario con otra clave',
        ])->assertSessionHasNoErrors();

        $hoja = $this->libroDe($this->actingAs($this->sup)->get(route('export.todas')))->getSheet(0);
        $this->assertFalse($this->amarilla($hoja, 'G'.$this->fila($hoja, 'G', 'NO-INV')));
    }

    public function test_un_producto_danado_no_va_en_amarillo(): void {
        $this->actingAs($this->op)->post(route('incidencias.store'), [
            'tipo' => Incidencia::DANIO, 'titulo' => 'Golpe', 'piezas' => 'OK-1',
        ])->assertSessionHasNoErrors();

        $hoja = $this->libroDe($this->actingAs($this->sup)->get(route('export.todas')))->getSheet(0);
        $this->assertFalse($this->amarilla($hoja, 'G'.$this->fila($hoja, 'G', 'OK-1')));
    }
}
