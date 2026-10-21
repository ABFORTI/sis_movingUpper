<?php

namespace Tests\Feature;

use App\Models\Embarque;
use App\Models\Incidencia;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use App\Services\Etiquetas\EtiquetaService;
use App\Services\Etiquetas\Lienzo;
use App\Services\TarimaService;
use App\Support\ConversionSku;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * Catálogo de conversiones (config/conversiones_sku.php): al escanear RB630 se
 * registra RVC-2P-10392 y se conserva RB630 como SKU original. El inventario
 * del almacén sigue trayendo RB630 con sus N/S.
 */
class ConversionSkuTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    private ?User $op = null;

    protected function setUp(): void {
        $this->prepararWms();
        config(['conversiones_sku' => ['RB630' => 'RVC-2P-10392']]);
        $this->op = $this->operador();
        $this->enInventario([
            ['sku' => 'RB630', 'modelo' => 'REFRIGERADOR RB630', 'descripcion' => 'Refrigerador', 'numero_serie' => 'SN-630-1'],
            ['sku' => 'RB630', 'modelo' => 'REFRIGERADOR RB630', 'descripcion' => 'Refrigerador', 'numero_serie' => 'SN-630-2'],
            ['sku' => 'OTRO-1', 'descripcion' => 'Otro producto', 'numero_serie' => 'SN-OTRO'],
        ]);
    }

    /** Activa el SKU (tal como se escanea) y registra sus N/S, que ya están en el inventario. */
    private function capturar(Tarima $tarima, string $sku, array $series): void {
        $this->actingAs($this->op)->post(route('escaneo.padre.cerrar'));
        $this->post(route('escaneo.padre'), ['sku' => $sku, 'tarima' => $tarima->uuid])->assertSessionHasNoErrors();
        foreach ($series as $ns) {
            $this->escanearNs($ns, agregarAlInventario: false)->assertSessionHasNoErrors();
        }
        $this->post(route('escaneo.padre.cerrar'));
    }

    private function guardar(Tarima $tarima): Tarima {
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    /** @return list<string> */
    private function textos(Lienzo $lienzo): array {
        return collect($lienzo->elementos())->where('tipo', 'texto')->pluck('texto')->values()->all();
    }

    public function test_al_escanear_rb630_se_registra_rvc_y_se_conserva_el_original(): void {
        $tarima = $this->tarima($this->op);
        $this->actingAs($this->op)->post(route('escaneo.padre'), ['sku' => 'RB630', 'tarima' => $tarima->uuid])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ok', "SKU RVC-2P-10392 activo en {$tarima->nombre}. Escanea o escribe las N/S. Conversión aplicada: RB630 → RVC-2P-10392.");

        $this->get(route('wms'))->assertOk()
            ->assertSee('RVC-2P-10392')
            ->assertSee('Conversión aplicada sobre este SKU');

        // La N/S se valida contra los renglones de RB630 del inventario: coincide, sin incidencia.
        $this->escanearNsJson('SN-630-1', agregarAlInventario: false)->assertOk()->assertJsonPath('estado', 'registrada');

        $registro = Registro::sole();
        $this->assertSame('RVC-2P-10392', $registro->sku);
        $this->assertSame('RB630', $registro->sku_original);
        $this->assertTrue($registro->fueConvertido());
        $serie = NumeroSerie::sole();
        $this->assertNotNull($serie->inventario_item_id, 'La N/S quedó ligada a su renglón del inventario (RB630).');
        $this->assertSame(0, Incidencia::count());
    }

    public function test_etiqueta_con_sku_convertido_sku_anterior_y_numero_de_serie(): void {
        $tarima = $this->tarima($this->op);
        $this->capturar($tarima, 'RB630', ['SN-630-1']);

        $pagina = app(EtiquetaService::class)->paginas($tarima)[0];
        $this->assertSame(
            ['MODELO', 'RVC-2P-10392', 'SKU anterior: RB630', 'EMBARQUE', 'MUD-0001 · E-001', 'SN-630-1'],
            $this->textos($pagina),
        );
        $this->assertSame('SN-630-1', collect($pagina->elementos())->firstWhere('tipo', 'barras')['dato']);

        // Mismo diseño: dentro del área y sin encimarse.
        $f = $pagina->formato;
        $cajas = collect($pagina->elementos())->map(fn ($e) => [$e['x'], $e['y'], $e['x'] + $e['ancho'], $e['y'] + $e['alto']])->values();
        foreach ($cajas as $i => $a) {
            $this->assertGreaterThanOrEqual($f->margenX, $a[0]);
            $this->assertGreaterThanOrEqual($f->margen, $a[1]);
            $this->assertLessThanOrEqual($f->ancho - $f->margenX, $a[2]);
            $this->assertLessThanOrEqual($f->alto - $f->margen, $a[3]);
            foreach ($cajas->slice($i + 1) as $b) {
                $this->assertFalse($a[0] < $b[2] && $b[0] < $a[2] && $a[1] < $b[3] && $b[1] < $a[3], 'Elementos encimados.');
            }
        }

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->assertOk()->getContent();
        $this->assertStringContainsString('RVC-2P-10392', $zpl);
        $this->assertStringContainsString('SKU anterior: RB630', $zpl);
        $this->get(route('etiquetas.show', $tarima))->assertOk()->assertSee('RVC-2P-10392');
    }

    public function test_sku_fuera_del_catalogo_no_cambia_ni_su_etiqueta(): void {
        $tarima = $this->tarima($this->op);
        $this->capturar($tarima, 'OTRO-1', ['SN-OTRO']);

        $registro = Registro::sole();
        $this->assertSame('OTRO-1', $registro->sku);
        $this->assertNull($registro->sku_original);
        $this->assertSame(['MODELO', 'OTRO-1', 'EMBARQUE', 'MUD-0001 · E-001', 'SN-OTRO'], $this->textos(app(EtiquetaService::class)->paginas($tarima)[0]));
        $this->get(route('wms'))->assertDontSee('Conversión aplicada');
    }

    public function test_no_se_convierte_dos_veces_ni_se_duplica_el_modelo(): void {
        $tarima = $this->tarima($this->op);
        $this->capturar($tarima, ' rb630 ', ['SN-630-1']);
        // Escanear el SKU ya convertido reabre el mismo modelo: no hay segunda conversión.
        $this->capturar($tarima, 'RVC-2P-10392', ['SN-630-2']);

        $registro = Registro::sole();
        $this->assertSame(['RVC-2P-10392', 'RB630'], [$registro->sku, $registro->sku_original]);
        $this->assertSame(['SN-630-1', 'SN-630-2'], $registro->numerosSerie->pluck('numero_serie')->all());
        $this->assertNull(ConversionSku::cambio('RVC-2P-10392'));
    }

    public function test_si_el_inventario_ya_trae_el_sku_nuevo_no_hay_conversion(): void {
        $this->enInventario([['sku' => 'RVC-2P-10392', 'descripcion' => 'Refrigerador nuevo', 'numero_serie' => 'SN-NUEVO']]);
        $tarima = $this->tarima($this->op);
        $this->capturar($tarima, 'RVC-2P-10392', ['SN-NUEVO']);

        $this->assertNull(Registro::sole()->sku_original);
    }

    public function test_guardado_historial_excel_y_vista_de_piezas_usan_el_sku_convertido(): void {
        $tarima = $this->tarima($this->op);
        $this->capturar($tarima, 'RB630', ['SN-630-1', 'SN-630-2']);
        $tarima = $this->guardar($tarima);

        $this->assertSame(['RVC-2P-10392'], Partida::pluck('sku')->unique()->values()->all());
        $this->assertSame(['RB630'], Partida::with('registro')->get()->pluck('registro.sku_original')->unique()->values()->all());

        $this->actingAs($this->admin())->get(route('historial'))->assertOk()
            ->assertSee('RVC-2P-10392')
            ->assertSee('Conversión aplicada sobre este SKU')
            ->assertSee('RB630');
        $this->get(app(TarimaService::class)->urlDetalle($tarima))->assertOk()
            ->assertSee('Conversión aplicada sobre este SKU');

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($ruta, $this->get(route('export.todas'))->assertOk()->streamedContent());
        $celdas = collect(\PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheet(0)->toArray(null, false, false))->flatten();
        @unlink($ruta);
        $this->assertTrue($celdas->contains('RVC-2P-10392'));
        $this->assertFalse($celdas->contains('RB630'), 'El Excel lleva el SKU convertido.');
    }

    public function test_en_el_anden_el_sku_original_encuentra_las_piezas_convertidas(): void {
        Storage::fake('documentos');
        $tarima = $this->tarima($this->op);
        $this->capturar($tarima, 'RB630', ['SN-630-1']);
        $this->guardar($tarima);

        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'abc-123-x'])->assertSessionHasNoErrors();
        $embarque = Embarque::latest('id')->first();

        $this->actingAs($this->op)->post(route('embarques.cargar', $embarque), ['codigo' => 'RB630'])->assertSessionHasNoErrors();
        $this->assertSame(1, $embarque->cargas()->count());
    }

    public function test_agregar_una_conversion_solo_requiere_una_entrada_en_el_catalogo(): void {
        config(['conversiones_sku' => ['RB630' => 'RVC-2P-10392', 'OTRO-1' => 'NUEVO-9']]);
        $tarima = $this->tarima($this->op);
        $this->capturar($tarima, 'OTRO-1', ['SN-OTRO']);

        $registro = Registro::sole();
        $this->assertSame(['NUEVO-9', 'OTRO-1'], [$registro->sku, $registro->sku_original]);
        $this->assertNotNull(NumeroSerie::sole()->inventario_item_id);
    }

    public function test_los_registros_historicos_no_se_alteran(): void {
        // Un RB630 capturado antes de la conversión sigue tal cual y no muestra leyenda.
        $tarima = $this->tarima($this->op);
        $historico = $tarima->registros()->create(['tipo' => \App\Enums\TipoRegistro::Padre, 'sku' => 'RB630', 'user_id' => $this->op->id]);
        $historico->numerosSerie()->create(['numero_serie' => 'SN-VIEJO', 'user_id' => $this->op->id]);

        $this->assertSame('RB630', $historico->fresh()->sku);
        $this->assertFalse($historico->fresh()->fueConvertido());
        $this->assertSame(['MODELO', 'REFRIGERADOR RB630', 'EMBARQUE', 'MUD-0001 · E-001', 'SN-VIEJO'], $this->textos(app(EtiquetaService::class)->paginas($tarima)[0]));
    }
}
