<?php

namespace Tests\Feature;

use App\Models\Incidencia;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Tarima;
use App\Models\User;
use App\Services\TarimaService;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * La N/S capturada (escaneada o escrita a mano) se busca en los tres números de
 * serie del SKU elegido, en el inventario del almacén de la tarima.
 */
class ValidacionSerieTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    private User $op;

    private Tarima $tarima;

    protected function setUp(): void {
        $this->prepararWms();
        $this->enInventario([
            ['sku' => 'REF-100', 'descripcion' => 'Refrigerador', 'ns_fabricante' => 'FAB-001', 'numero_serie' => 'NS-AAA', 'ns_lote' => 'LOTE-9'],
            ['sku' => 'REF-100', 'descripcion' => 'Refrigerador', 'ns_fabricante' => 'FAB-002', 'numero_serie' => 'NS-BBB'],
            ['sku' => 'REF-100', 'descripcion' => 'Refrigerador', 'numero_serie' => '00123'],
            ['sku' => 'EST-200', 'descripcion' => 'Estufa', 'numero_serie' => 'DE-OTRO-SKU'],
        ]);
        $this->enInventario([['sku' => 'REF-100', 'numero_serie' => 'DE-OTRO-ALMACEN']], '3');

        $this->op = $this->operador();
        $this->tarima = $this->tarima($this->op);
        $this->activarPadre($this->op, $this->tarima, 'REF-100');
    }

    private function capturar(string $ns) {
        return $this->actingAs($this->op)->escanearNsJson($ns, agregarAlInventario: false);
    }

    public function test_coincide_en_el_numero_de_serie_del_fabricante(): void {
        $this->capturar('FAB-001')->assertOk()
            ->assertJsonPath('estado', 'registrada')
            ->assertJsonPath('coincidencia', 'Número de serie del fabricante')
            ->assertJsonPath('incidencia', null);

        $serie = NumeroSerie::sole();
        $this->assertSame(['FAB-001', 'ns_fabricante'], [$serie->numero_serie, $serie->coincidencia]);
        $this->assertNotNull($serie->inventario_item_id);
    }

    public function test_coincide_en_el_numero_de_serie(): void {
        $this->capturar('NS-BBB')->assertOk()->assertJsonPath('estado', 'registrada')->assertJsonPath('coincidencia', 'Número de serie');
    }

    public function test_coincide_en_el_numero_de_serie_de_lote(): void {
        $this->capturar('LOTE-9')->assertOk()->assertJsonPath('estado', 'registrada')->assertJsonPath('coincidencia', 'Número de serie de lote');
    }

    public function test_normaliza_espacios_y_mayusculas_sin_alterar_la_identidad(): void {
        $this->capturar("  fab-002 \t")->assertOk()->assertJsonPath('estado', 'registrada')->assertJsonPath('numero_serie', 'FAB-002');
        // No se convierte a número: "123" no es "00123", ni "NSAAA" es "NS-AAA" (se registran sin coincidencia).
        $this->capturar('123')->assertJsonPath('coincidencia', null)->assertJsonPath('incidencia.folio', 'INC-0001');
        $this->capturar('NSAAA')->assertJsonPath('coincidencia', null)->assertJsonPath('incidencia.folio', 'INC-0002');
        $this->capturar('00123')->assertJsonPath('estado', 'registrada')->assertJsonPath('coincidencia', 'Número de serie')->assertJsonPath('incidencia', null);
    }

    public function test_una_ns_de_otro_sku_o_de_otro_almacen_no_es_valida(): void {
        // No coinciden: se registran como pendientes de revisión y la pista queda en su incidencia.
        $this->capturar('DE-OTRO-SKU')->assertOk()
            ->assertJsonPath('coincidencia', null)
            ->assertJsonPath('pista', 'En este almacén esa N/S pertenece al SKU EST-200, no al REF-100.');
        $this->capturar('DE-OTRO-ALMACEN')->assertOk()
            ->assertJsonPath('coincidencia', null)
            ->assertJsonPath('pista', 'Esa N/S está en el inventario del Almacén 3, no en el de este embarque.');

        $this->assertSame(2, Incidencia::where('tipo', Incidencia::NO_EN_INVENTARIO)->count());
        $this->assertStringContainsString('pertenece al SKU EST-200', Incidencia::where('numero_serie', 'DE-OTRO-SKU')->value('descripcion'));
        $this->assertSame([null, null], NumeroSerie::pluck('inventario_item_id')->all());
    }

    public function test_con_coincidencia_no_se_abre_incidencia(): void {
        $this->capturar('NS-AAA')->assertOk()->assertJsonPath('estado', 'registrada')->assertJsonPath('incidencia', null);
        $this->assertSame(0, Incidencia::count());
        $this->assertFalse(NumeroSerie::sole()->pendienteRevision());
    }

    public function test_sin_coincidencia_se_registra_y_abre_su_incidencia_automaticamente(): void {
        // Una sola solicitud, sin confirmación: la pieza queda registrada con su incidencia.
        $this->capturar('no-existe ')->assertOk()->assertJson([
            'estado' => 'registrada',
            'mensaje' => 'N/S NO-EXISTE no está en el inventario: se registró y quedó pendiente de revisión (incidencia INC-0001). 1 en total.',
            'numero_serie' => 'NO-EXISTE',
            'coincidencia' => null,
            'incidencia' => ['folio' => 'INC-0001'],
            'piezas_tarima' => 1,
        ]);

        $incidencia = Incidencia::sole();
        $serie = NumeroSerie::sole();
        $this->assertSame(Incidencia::NO_EN_INVENTARIO, $incidencia->tipo);
        $this->assertSame(Incidencia::ABIERTA, $incidencia->estado);
        $this->assertSame(
            ['NO-EXISTE', 'REF-100', $serie->id, $this->tarima->id, $this->almacen('58')->id, $this->mudanza->id, $this->op->id],
            [$incidencia->numero_serie, $incidencia->sku, $incidencia->numero_serie_id, $incidencia->tarima_id, $incidencia->almacen_id, $incidencia->mudanza_id, $incidencia->creada_by],
        );
        $this->assertFalse($incidencia->bloquea_traslado);
        $this->assertNull($serie->inventario_item_id);
        $this->assertTrue($serie->pendienteRevision());

        // Escaneos repetidos (doble lectura, reintento por mala señal) no duplican la N/S ni la incidencia.
        foreach (['NO-EXISTE', ' no-existe', 'NO-EXISTE'] as $repetida) {
            $this->capturar($repetida)->assertOk()->assertJsonPath('estado', 'repetida');
        }
        $this->assertSame(1, Incidencia::count());
        $this->assertSame(1, NumeroSerie::count());

        // Aparece en Incidencias, abierta y con sus datos.
        $this->actingAs($this->auditor())->get(route('incidencias.index'))->assertOk()
            ->assertSee('INC-0001')->assertSee('No encontrado en inventario')->assertSee('NO-EXISTE');
    }

    public function test_sin_javascript_tambien_se_registra_sin_preguntar(): void {
        $this->actingAs($this->op)->escanearNs('NO-EXISTE', agregarAlInventario: false)
            ->assertRedirect(route('wms'))->assertSessionHasNoErrors()->assertSessionHas('warn');
        $this->assertSame(1, Incidencia::count());

        $html = $this->get(route('wms'))->assertOk()->getContent();
        $this->assertStringNotContainsString('¿Deseas marcarla como incidencia?', $html);
        $this->assertStringNotContainsString('confirmar_incidencia', $html);
        $this->assertStringNotContainsString('showModal', $html);
        $this->assertStringContainsString('chip-revision', $html, 'La pieza se señala para revisión.');
    }

    public function test_un_parametro_viejo_de_confirmacion_no_cambia_nada(): void {
        // Una pantalla abierta antes del cambio puede mandar confirmar_incidencia: se ignora.
        $this->actingAs($this->op)->escanearNsJson('NO-EXISTE', agregarAlInventario: false, extra: ['confirmar_incidencia' => '0'])
            ->assertOk()->assertJsonPath('incidencia.folio', 'INC-0001');
    }

    public function test_un_mismo_producto_no_se_registra_dos_veces_por_otro_de_sus_numeros(): void {
        $this->capturar('FAB-001')->assertJsonPath('estado', 'registrada');

        $this->capturar('NS-AAA')->assertStatus(422)
            ->assertJsonPath('errors.numero_serie.0', fn (string $m) => str_contains($m, 'ya se registró con la N/S FAB-001'));
        $this->assertSame(1, NumeroSerie::count());
    }

    public function test_la_captura_manual_y_la_foto_del_ns_estan_disponibles(): void {
        // Escrita a mano (con el teclado) llega igual que escaneada y se valida igual.
        $this->actingAs($this->op)->post(route('escaneo.ns'), ['numero_serie' => 'ns-bbb'])->assertSessionHasNoErrors()
            ->assertSessionHas('ok', 'N/S NS-BBB encontrada en el inventario (Número de serie). 1 en total.');

        $html = $this->get(route('wms'))->assertOk()->getContent();
        $this->assertStringContainsString('data-captura-manual', $html);
        $this->assertStringContainsString('Escribir a mano', $html);
        // Foto del N/S: habilitada, con cámara trasera y su ruta de lectura (OcrTest cubre el backend).
        $this->assertStringNotContainsString('is-pendiente', $html);
        $this->assertStringNotContainsString('Próximamente · no disponible', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-captura-foto(?![^>]*disabled)[^>]*>/s', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString(route('escaneo.ocr'), $html);
    }

    public function test_las_ns_pendientes_se_senalan_en_todas_las_vistas_y_desaparecen_al_resolver(): void {
        $this->capturar('FAB-001');
        $this->capturar('NO-EXISTE');

        // Captura: ⚠ + texto (no solo color).
        $this->get(route('wms'))->assertOk()
            ->assertSee('⚠ NO-EXISTE', false)->assertSee('chip-revision', false)->assertSee('revisar')
            ->assertSee('1 N/S sin coincidencia en el inventario');

        $this->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $this->tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $this->tarima))->assertOk();

        $incidencia = Incidencia::sole();
        $partida = Partida::where('numero_serie', 'NO-EXISTE')->sole();
        $this->assertTrue($partida->pendienteRevision());
        $this->assertSame([$partida->id], $incidencia->partidas()->pluck('partidas.id')->all(), 'Al guardar se liga a su pieza.');
        $this->assertFalse(Partida::where('numero_serie', 'FAB-001')->sole()->pendienteRevision());

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('historial'))->assertSee('badge-revision', false)->assertSee('NO-EXISTE');
        $this->get(route('tarimas.corregir', $this->tarima))->assertSee('is-revision', false)->assertSee('Revisar');
        $this->get(route('incidencias.index'))->assertSee('NO-EXISTE')->assertSee('No encontrado en inventario')->assertSee('badge-revision', false);
        $this->get(route('incidencias.show', $incidencia))->assertSee('Pendiente de revisión')->assertSee('Almacén 58');
        $this->get(app(TarimaService::class)->urlDetalle($this->tarima))->assertSee('Pendiente de revisión');

        $this->post(route('incidencias.resolver', $incidencia), ['tipo_resolucion' => 'corregido_upper', 'resolucion' => 'El cliente la dio de alta'])->assertSessionHasNoErrors();

        $this->assertFalse($partida->fresh()->pendienteRevision());
        $this->get(route('historial'))->assertDontSee('badge-revision', false);
        $this->get(route('tarimas.corregir', $this->tarima))->assertDontSee('is-revision', false);
        $this->get(app(TarimaService::class)->urlDetalle($this->tarima))->assertDontSee('Pendiente de revisión');
    }

    public function test_dos_capturas_distintas_si_generan_incidencias_distintas(): void {
        $this->capturar('NO-1');
        $this->capturar('NO-2');

        $this->assertSame(['NO-1', 'NO-2'], Incidencia::orderBy('id')->pluck('numero_serie')->all());
    }
}
