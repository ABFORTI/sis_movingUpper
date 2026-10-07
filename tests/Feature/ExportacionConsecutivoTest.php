<?php

namespace Tests\Feature;

use App\Models\Destino;
use App\Models\NumeroSerie;
use App\Models\Tarima;
use App\Models\User;
use App\Services\Exportacion\ExcelExportador;
use App\Services\TarimaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class ExportacionConsecutivoTest extends TestCase {
    use PreparaWms;

    /** Captura un SKU padre con sus N/S (lista vacía = padre sin N/S). */
    private function padre(User $usuario, Tarima $tarima, string $sku, array $series, string $descripcion = 'Bomba', ?Destino $destino = null): void {
        $this->actingAs($usuario)->post(route('escaneo.padre'), [
            'sku' => $sku,
            'descripcion' => $descripcion,
            'destino_id' => ($destino ?? $this->destino())->id,
            'tarima' => $tarima->uuid,
        ])->assertSessionHasNoErrors();

        foreach ($series as $ns) {
            $this->post(route('escaneo.ns'), ['numero_serie' => $ns])->assertSessionHasNoErrors();
        }

        $this->post(route('escaneo.padre.cerrar'));
    }

    private function simple(User $usuario, Tarima $tarima, string $sku, string $descripcion = 'Caja', ?Destino $destino = null): void {
        $this->actingAs($usuario)->post(route('escaneo.simple'), [
            'sku' => $sku,
            'descripcion' => $descripcion,
            'destino_id' => ($destino ?? $this->destino())->id,
            'tarima' => $tarima->uuid,
        ])->assertSessionHasNoErrors();
    }

    private function guardar(User $usuario, Tarima $tarima): Tarima {
        $this->actingAs($usuario)->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->actingAs($usuario)->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    /** Tarima guardada con $piezas N/S en un solo padre. */
    private function guardadaCon(User $usuario, int $piezas, string $prefijo, string $descripcion = 'Bomba', ?Destino $destino = null): Tarima {
        $tarima = $this->tarima($usuario);
        $series = array_map(fn (int $i) => "{$prefijo}-{$i}", range(1, $piezas));
        $this->padre($usuario, $tarima, "SKU-{$prefijo}", $series, $descripcion, $destino);

        return $this->guardar($usuario, $tarima);
    }

    private function libro(TestResponse $respuesta): Spreadsheet {
        $respuesta->assertOk();
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($ruta, $respuesta->streamedContent());

        try {
            return IOFactory::load($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    /** @return list<list<mixed>> filas de datos (sin encabezado) */
    private function datos(Spreadsheet $libro): array {
        return array_slice($libro->getSheet(0)->toArray(null, false, false), 1);
    }

    /** @return list<int|null> */
    private function consecutivos(TestResponse $respuesta): array {
        return array_column($this->datos($this->libro($respuesta)), 0);
    }

    // ---------- A, B, G: asignación del consecutivo ----------

    public function test_a_nueva_tarima_recibe_consecutivo_al_guardarse(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->simple($operador, $tarima, 'SKU-1');

        $this->assertNull($tarima->fresh()->consecutivo_inicial);
        $this->actingAs($operador)->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->assertNull($tarima->fresh()->consecutivo_inicial, 'Imprimir no asigna consecutivo.');

        $this->assertSame(1, $this->guardar($operador, $tarima)->consecutivo_inicial);
    }

    public function test_b_segunda_tarima_recibe_el_rango_siguiente(): void {
        $operador = $this->operador();
        $primera = $this->guardadaCon($operador, 4, 'A');
        $segunda = $this->guardadaCon($operador, 2, 'B');

        $this->assertSame(1, $primera->consecutivo_inicial);
        $this->assertSame(5, $segunda->consecutivo_inicial);
        $this->assertSame(6, (int) DB::table('contadores')->where('clave', TarimaService::CONTADOR_CONSECUTIVO)->value('valor'));
    }

    public function test_g_guardados_simultaneos_no_superponen_rangos(): void {
        $operador = $this->operador();
        $servicio = app(TarimaService::class);

        $a = $this->tarima($operador);
        $b = $this->tarima($operador);
        $this->padre($operador, $a, 'SKU-A', ['A-1', 'A-2', 'A-3']);
        $this->padre($operador, $b, 'SKU-B', ['B-1', 'B-2']);
        $this->actingAs($operador)->postJson(route('tarimas.impresa', $a))->assertOk();
        $this->actingAs($operador)->postJson(route('tarimas.impresa', $b))->assertOk();

        // Dos solicitudes que leyeron la misma tarima antes de que se guardara
        // (doble clic): solo una la guarda y reserva rango.
        $copia1 = Tarima::find($a->id);
        $copia2 = Tarima::find($a->id);
        $this->assertTrue($servicio->guardar($copia1, $operador));
        $this->assertFalse($servicio->guardar($copia2, $operador));
        $this->assertSame($copia1->consecutivo_inicial, $copia2->consecutivo_inicial);

        // Otra tarima guardada "al mismo tiempo" recibe un rango disjunto.
        $servicio->guardar(Tarima::find($b->id), $operador);

        $this->assertSame([1, 4], Tarima::orderBy('id')->pluck('consecutivo_inicial')->all());
        $this->assertSame(5, (int) DB::table('contadores')->where('clave', TarimaService::CONTADOR_CONSECUTIVO)->value('valor'));
        $this->assertRangosSinTraslape();
    }

    public function test_g_no_se_agregan_piezas_despues_de_reservar_el_rango(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->actingAs($operador)->post(route('escaneo.padre'), [
            'sku' => 'SKU-A', 'descripcion' => 'Bomba', 'destino_id' => $this->destino()->id, 'tarima' => $tarima->uuid,
        ]);
        $this->post(route('escaneo.ns'), ['numero_serie' => 'A-1']);
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        app(TarimaService::class)->guardar($tarima->fresh(), $operador);

        // El SKU padre quedó abierto en la sesión: la N/S debe rechazarse.
        $this->post(route('escaneo.ns'), ['numero_serie' => 'A-2'])->assertSessionHasErrors();

        $this->assertSame(1, NumeroSerie::count());
        $this->assertCount(1, app(TarimaService::class)->filas($tarima->fresh()));
    }

    public function test_un_cambio_entre_imprimir_y_guardar_no_reserva_rango(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->simple($operador, $tarima, 'SKU-1');
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $vieja = Tarima::find($tarima->id);
        $this->simple($operador, $tarima, 'SKU-2');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            app(TarimaService::class)->guardar($vieja, $operador);
        } finally {
            $this->assertNull($tarima->fresh()->consecutivo_inicial);
            $this->assertSame(0, (int) DB::table('contadores')->where('clave', TarimaService::CONTADOR_CONSECUTIVO)->value('valor'));
        }
    }

    // ---------- C, D, E: consecutivos en el Excel ----------

    public function test_c_tarima_con_varias_series_usa_inicial_mas_posicion(): void {
        $operador = $this->operador();
        $this->guardadaCon($operador, 3, 'PREV');

        $tarima = $this->tarima($operador);
        $this->padre($operador, $tarima, 'SKU-P', ['NS-1', 'NS-2', 'NS-3']);
        $this->simple($operador, $tarima, 'SKU-S');
        $this->padre($operador, $tarima, 'SKU-V', []);
        $tarima = $this->guardar($operador, $tarima);

        $this->assertSame(4, $tarima->consecutivo_inicial);

        $datos = $this->datos($this->libro($this->get(route('export.tarima', $tarima))));

        $this->assertSame([4, 5, 6, 7, 8], array_column($datos, 0));
        $this->assertSame(['NS-1', 'NS-2', 'NS-3', '—', '(sin N/S)'], array_column($datos, 7));
        $this->assertSame(['SKU-P', 'SKU-P', 'SKU-P', 'SKU-S', 'SKU-V'], array_column($datos, 1));
        $this->assertSame(8, (int) DB::table('contadores')->where('clave', TarimaService::CONTADOR_CONSECUTIVO)->value('valor'));
    }

    public function test_d_descargar_la_misma_tarima_dos_veces_da_los_mismos_consecutivos(): void {
        $operador = $this->operador();
        $this->guardadaCon($operador, 2, 'A');
        $tarima = $this->guardadaCon($operador, 3, 'B');

        $primera = $this->consecutivos($this->get(route('export.tarima', $tarima)));
        $segunda = $this->consecutivos($this->get(route('export.tarima', $tarima)));

        $this->assertSame([3, 4, 5], $primera);
        $this->assertSame($primera, $segunda);
        $this->assertSame(3, $tarima->fresh()->consecutivo_inicial);
    }

    public function test_e_descargar_el_historial_dos_veces_da_los_mismos_consecutivos(): void {
        $operador = $this->operador();
        $this->guardadaCon($operador, 2, 'A');
        $this->guardadaCon($operador, 3, 'B');
        $this->guardadaCon($operador, 1, 'C');

        $primera = $this->consecutivos($this->get(route('export.todas')));
        $segunda = $this->consecutivos($this->get(route('export.todas')));

        $this->assertSame([1, 2, 3, 4, 5, 6], $primera);
        $this->assertSame($primera, $segunda);
    }

    public function test_el_historial_y_la_tarima_individual_coinciden(): void {
        $operador = $this->operador();
        $this->guardadaCon($operador, 2, 'A');
        $b = $this->guardadaCon($operador, 3, 'B');

        $todas = $this->datos($this->libro($this->get(route('export.todas'))));
        $individual = $this->datos($this->libro($this->get(route('export.tarima', $b))));

        $this->assertSame($individual, array_values(array_filter($todas, fn (array $f) => $f[1] === 'SKU-B')));
    }

    // ---------- F: filtros ----------

    public function test_f_filtrar_el_historial_no_renumera(): void {
        $operador = $this->operador();
        [$prodA, $prodB] = Destino::orderBy('id')->take(2)->get()->all();
        $this->guardadaCon($operador, 2, 'A', 'Bomba', $prodA);
        $this->guardadaCon($operador, 2, 'B', 'Motor', $prodB);
        $this->guardadaCon($operador, 1, 'C', 'Bomba', $prodA);

        $porDescripcion = $this->datos($this->libro($this->get(route('export.todas', ['descripcion' => 'Motor']))));
        $this->assertSame([3, 4], array_column($porDescripcion, 0));
        $this->assertSame(['SKU-B', 'SKU-B'], array_column($porDescripcion, 1));

        $porCategoria = $this->consecutivos($this->get(route('export.todas', ['destino' => $prodA->codigo])));
        $this->assertSame([1, 2, 5], $porCategoria);

        $tarimaC = Tarima::where('consecutivo_inicial', 5)->first();
        $this->assertSame([5], $this->consecutivos($this->get(route('export.todas', ['tarima' => (string) $tarimaC->id]))));

        $this->get(route('export.todas', ['descripcion' => 'No existe']))
            ->assertRedirect(route('historial', ['descripcion' => 'No existe']))
            ->assertSessionHasErrors('tarima');
    }

    public function test_el_boton_del_historial_envia_los_filtros(): void {
        $operador = $this->operador();
        $this->guardadaCon($operador, 1, 'A', 'Motor');

        $this->get(route('historial', ['descripcion' => 'Motor']))
            ->assertOk()
            ->assertSee(route('export.todas', ['descripcion' => 'Motor']), false);
    }

    public function test_operador_solo_exporta_sus_tarimas_con_sus_consecutivos(): void {
        $otro = $this->operador();
        $this->guardadaCon($otro, 2, 'OTRO');
        $operador = $this->operador();
        $this->guardadaCon($operador, 2, 'MIA');

        $this->actingAs($operador);
        $this->assertSame([3, 4], $this->consecutivos($this->get(route('export.todas'))));
    }

    // ---------- H: temporales ----------

    public function test_h_las_tarimas_temporales_no_aparecen_en_el_historial_exportado(): void {
        $operador = $this->operador();
        $this->guardadaCon($operador, 2, 'GUARDADA');

        $temporal = $this->tarima($operador);
        $this->padre($operador, $temporal, 'SKU-TEMP', ['T-1']);
        $impresa = $this->tarima($operador);
        $this->simple($operador, $impresa, 'SKU-IMPRESA');
        $this->postJson(route('tarimas.impresa', $impresa))->assertOk();

        $skus = array_column($this->datos($this->libro($this->get(route('export.todas')))), 1);

        $this->assertSame(['SKU-GUARDADA', 'SKU-GUARDADA'], $skus);
    }

    public function test_tarima_temporal_exportada_sola_no_inventa_consecutivo(): void {
        $operador = $this->operador();
        $temporal = $this->tarima($operador);
        $this->padre($operador, $temporal, 'SKU-TEMP', ['T-1', 'T-2']);

        $this->assertSame([null, null], $this->consecutivos($this->get(route('export.tarima', $temporal))));
        $this->assertNull($temporal->fresh()->consecutivo_inicial);
        $this->assertSame(0, (int) DB::table('contadores')->where('clave', TarimaService::CONTADOR_CONSECUTIVO)->value('valor'));
    }

    // ---------- I, J, K: formato ----------

    public function test_i_j_k_formato_del_excel(): void {
        $operador = $this->operador();
        [$prodA, $prodB] = Destino::orderBy('id')->take(2)->get()->all();
        $tarima = $this->tarima($operador);
        $this->padre($operador, $tarima, 'SKU-1', ['S-1', 'S-2'], 'Bomba hidráulica', $prodA);
        $this->simple($operador, $tarima, 'SKU-2', 'Caja', $prodB);
        $tarima = $this->guardar($operador, $tarima);
        $this->guardadaCon($operador, 1, 'OTRA');

        foreach ([route('export.tarima', $tarima), route('export.todas')] as $url) {
            $libro = $this->libro($this->get($url));

            $this->assertSame(1, $libro->getSheetCount());
            $this->assertNull($libro->getSheetByName('Resumen'));
            $hoja = $libro->getSheet(0);
            $this->assertSame('H', $hoja->getHighestColumn());
            $this->assertSame(ExcelExportador::ENCABEZADOS, $hoja->rangeToArray('A1:H1', null, false, false)[0]);
            $this->assertSame([
                'Consecutivo', 'Número de parte (SKU)', 'Descripción', 'Cantidad',
                'Unidad de medida', 'Categoría', 'Daño', 'Número de serie',
            ], ExcelExportador::ENCABEZADOS);

            $datos = $this->datos($libro);
            foreach ($datos as $fila) {
                $this->assertCount(8, $fila);
                $this->assertNotContains('TOTAL', $fila);
                $this->assertNotContains('GRAN TOTAL', $fila);
                $this->assertSame(1, $fila[3]);
                $this->assertSame('PZ', $fila[4]);
                $this->assertSame(0, $fila[6]);
            }

            $this->assertSame(
                [1, 'SKU-1', 'Bomba hidráulica', 1, 'PZ', $prodA->codigo, 0, 'S-1'],
                $datos[0]
            );
            $this->assertSame([3, 'SKU-2', 'Caja', 1, 'PZ', $prodB->codigo, 0, '—'], $datos[2]);
        }
    }

    public function test_k_producto_destino_sigue_igual_fuera_del_excel(): void {
        $operador = $this->operador();
        $this->guardadaCon($operador, 1, 'A');

        $this->get(route('historial'))->assertOk()->assertSee('Producto destino')->assertDontSee('Categoría');
        $this->get(route('wms'))->assertOk()->assertSee('Producto destino');
    }

    // ---------- Integridad y backfill ----------

    public function test_backfill_de_tarimas_historicas(): void {
        $operador = $this->operador();
        $temporal = $this->tarima($operador);
        $this->simple($operador, $temporal, 'SKU-TEMP');

        $a = $this->tarima($operador);
        $this->padre($operador, $a, 'SKU-A', ['A-1', 'A-2', 'A-BORRADA']);
        $this->simple($operador, $a, 'SKU-A2');
        $this->padre($operador, $a, 'SKU-A3', []);
        NumeroSerie::where('numero_serie', 'A-BORRADA')->delete(); // borrado lógico: no cuenta
        $a = $this->guardar($operador, $a);
        $b = $this->guardadaCon($operador, 2, 'B');

        // Estado previo a la migración: guardadas sin consecutivo, B guardada antes que A.
        Tarima::whereKey($b->id)->update(['guardada_at' => now()->subDay()]);
        DB::table('tarimas')->update(['consecutivo_inicial' => null]);
        DB::table('contadores')->delete();

        $migracion = require database_path('migrations/2026_10_06_000001_add_consecutivo_inicial_to_tarimas_table.php');
        $migracion->asignarHistoricas();
        $migracion->asignarHistoricas(); // idempotente

        $this->assertSame(1, $b->fresh()->consecutivo_inicial);
        $this->assertSame(3, $a->fresh()->consecutivo_inicial);
        $this->assertNull($temporal->fresh()->consecutivo_inicial);
        $this->assertCount(4, app(TarimaService::class)->filas($a->fresh()));
        $this->assertSame(6, (int) DB::table('contadores')->where('clave', TarimaService::CONTADOR_CONSECUTIVO)->value('valor'));

        // Las nuevas continúan después del backfill.
        $this->postJson(route('tarimas.impresa', $temporal))->assertOk();
        $this->assertSame(7, $this->guardar($operador, $temporal)->consecutivo_inicial);
        $this->assertRangosSinTraslape();
    }

    public function test_consecutivo_inicial_es_unico_en_bd(): void {
        $operador = $this->operador();
        $a = $this->guardadaCon($operador, 1, 'A');
        $b = $this->guardadaCon($operador, 1, 'B');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Tarima::whereKey($b->id)->update(['consecutivo_inicial' => $a->consecutivo_inicial]);
    }

    public function test_muchas_tarimas_tienen_rangos_contiguos_sin_traslape(): void {
        $operador = $this->operador();
        foreach ([3, 1, 5, 2, 4] as $i => $piezas) {
            $this->guardadaCon($operador, $piezas, "T{$i}");
        }

        $this->assertRangosSinTraslape();
        $this->assertSame(range(1, 15), $this->consecutivos($this->get(route('export.todas'))));
    }

    private function assertRangosSinTraslape(): void {
        $servicio = app(TarimaService::class);
        $rangos = Tarima::guardadas()->orderBy('consecutivo_inicial')->get()
            ->map(fn (Tarima $t) => [$t->consecutivo_inicial, $t->consecutivo_inicial + $servicio->filas($t)->count() - 1]);

        $this->assertSame($rangos->count(), $rangos->pluck(0)->unique()->count(), 'consecutivo_inicial duplicado');
        $this->assertFalse($rangos->contains(fn (array $r) => $r[0] === null), 'Tarima guardada sin consecutivo');
        foreach ($rangos->sliding(2) as $par) {
            [$anterior, $siguiente] = $par->values()->all();
            $this->assertLessThan($siguiente[0], $anterior[1], 'Rangos superpuestos');
        }
    }
}
