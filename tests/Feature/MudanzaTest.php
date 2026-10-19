<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\Bitacora;
use App\Models\Mudanza;
use App\Models\MudanzaMovimiento;
use App\Models\Tarima;
use App\Services\MudanzaService;
use App\Services\TarimaService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class MudanzaTest extends TestCase {
    use PreparaWms;

    private function transicionar(Mudanza $mudanza, string $accion, ?string $motivo = null): void {
        app(MudanzaService::class)->transicionar($mudanza, $accion, $this->admin(), $motivo);
    }

    private function guardar(Tarima $tarima): void {
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();
    }

    // ---------- Numeración de tarimas ----------

    public function test_la_numeracion_es_una_sola_para_todos_los_usuarios(): void {
        [$a, $b, $c] = [$this->operador(), $this->operador(), $this->operador()];

        $numeros = collect([$a, $b, $c, $a])->map(fn ($u) => $this->tarima($u))->map(fn (Tarima $t) => $t->codigo);

        $this->assertSame(['E-001', 'E-002', 'E-003', 'E-004'], $numeros->all());
        $this->assertSame(4, (int) DB::table('contadores')->where('clave', TarimaService::claveNumeracion($this->mudanza))->value('valor'));
    }

    public function test_crear_tarima_por_http_usa_el_siguiente_numero(): void {
        $operador = $this->operador();

        $this->actingAs($operador)->post(route('tarimas.store'))->assertRedirect(route('wms'))->assertSessionHas('ok', 'Embarque E-001 creado (58 → 3).');
        $this->actingAs($this->operador())->post(route('tarimas.store'))->assertSessionHas('ok', 'Embarque E-002 creado (58 → 3).');
    }

    public function test_solo_se_reutiliza_el_numero_del_ultimo_embarque_eliminado(): void {
        $operador = $this->operador();
        $primera = $this->tarima($operador);
        $segunda = $this->tarima($operador);

        // E-001 ya no es el último: al anularlo se elimina y su número queda como hueco.
        $this->actingAs($operador)->post(route('tarimas.anular', $primera), ['motivo' => 'Creada por error'])
            ->assertSessionHas('warn', 'Embarque E-001 eliminado. El número E-001 no se reutiliza: ya hay embarques con números posteriores.');
        $tercera = $this->tarima($operador);
        $this->assertSame('E-003', $tercera->codigo);

        // E-003 es el último asignado: su número se vuelve a dar.
        $this->post(route('tarimas.anular', $tercera), ['motivo' => 'Creada por error']);
        $this->assertSame('E-003', $this->tarima($operador)->codigo);

        $this->assertSame([2, 3], Tarima::orderBy('numero')->pluck('numero')->all(), 'Nadie se renumera ni se duplica.');
        $this->assertSame(2, $segunda->fresh()->numero);
    }

    public function test_el_numero_es_unico_por_mudanza_en_la_base_de_datos(): void {
        $this->tarima();

        $this->expectException(UniqueConstraintViolationException::class);
        Tarima::create(['mudanza_id' => $this->mudanza->id, 'numero' => 1]);
    }

    public function test_cada_mudanza_tiene_su_propia_numeracion(): void {
        $admin = $this->admin();
        foreach ([$this->tarima(), $this->tarima()] as $vacia) {
            app(TarimaService::class)->anular($vacia, $admin, 'Vacía al cierre');
        }
        $this->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE);
        $this->transicionar($this->mudanza, MudanzaService::CERRAR);

        $segunda = $this->crearMudanza(Mudanza::BORRADOR, 'Otro cliente');
        $this->transicionar($segunda, MudanzaService::ACTIVAR);

        $tarima = $this->tarima();
        $this->assertSame($segunda->id, $tarima->mudanza_id);
        $this->assertSame('E-001', $tarima->codigo);
        $this->assertSame('MUD-0002 · E-001', $tarima->identificador);
    }

    // ---------- Una sola mudanza en curso ----------

    public function test_solo_puede_haber_una_mudanza_en_curso(): void {
        $otra = $this->crearMudanza();

        try {
            $this->transicionar($otra, MudanzaService::ACTIVAR);
            $this->fail('Se activaron dos mudanzas.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('MUD-0001', $e->errors()['accion'][0]);
        }
        $this->assertSame(Mudanza::BORRADOR, $otra->fresh()->estado);

        // La base de datos lo impide aunque se salte el servicio.
        $this->expectException(UniqueConstraintViolationException::class);
        $otra->update(['estado' => Mudanza::SUSPENDIDA]);
    }

    public function test_activar_exige_cliente_y_al_menos_un_movimiento(): void {
        $this->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE);
        $this->transicionar($this->mudanza, MudanzaService::CERRAR);
        $incompleta = Mudanza::create(['folio' => 'MUD-0009', 'cliente' => 'Euroquip', 'estado' => Mudanza::BORRADOR]);

        $this->expectException(ValidationException::class);
        $this->transicionar($incompleta, MudanzaService::ACTIVAR);
    }

    // ---------- Qué permite cada estado ----------

    public function test_sin_mudanza_activa_no_se_crean_tarimas(): void {
        $this->transicionar($this->mudanza, MudanzaService::CANCELAR, 'Servicio pospuesto');

        $this->actingAs($this->operador())->post(route('tarimas.store'))->assertSessionHasErrors('tarima');
        $this->assertSame(0, Tarima::count());
        $this->get(route('wms'))->assertOk()->assertSee('No hay una mudanza activa');
    }

    public function test_suspendida_no_admite_capturas_ni_cambios(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->transicionar($this->mudanza, MudanzaService::SUSPENDER, 'Falta autorización del cliente');

        $this->skuEnInventario('SKU-1');
        $this->actingAs($operador)->post(route('escaneo.padre'), ['sku' => 'SKU-1', 'tarima' => $tarima->uuid])->assertSessionHasErrors('sku');
        $this->post(route('tarimas.store'))->assertSessionHasErrors('tarima');
        $this->assertSame(1, Tarima::count());
        $this->get(route('wms'))->assertOk()->assertSee('está Suspendida');
    }

    public function test_en_cierre_se_guardan_las_tarimas_pero_no_se_escanea(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->capturarPieza($operador, $tarima, 'SKU-1');
        $this->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE);

        $this->skuEnInventario('SKU-2');
        $this->actingAs($operador)->post(route('escaneo.padre'), ['sku' => 'SKU-2', 'tarima' => $tarima->uuid])->assertSessionHasErrors('sku');
        $this->guardar($tarima);

        $this->assertTrue($tarima->fresh()->estaGuardada());
    }

    public function test_cerrar_exige_que_no_queden_tarimas_en_captura(): void {
        $operador = $this->operador();
        $abierta = $this->tarima($operador);
        $this->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE);

        try {
            $this->transicionar($this->mudanza, MudanzaService::CERRAR);
            $this->fail('Se cerró con tarimas en captura.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('E-001', $e->errors()['accion'][0]);
        }

        $this->actingAs($operador)->post(route('tarimas.anular', $abierta), ['motivo' => 'Sobró']);
        $this->transicionar($this->mudanza, MudanzaService::CERRAR);
        $this->assertSame(Mudanza::CERRADA, $this->mudanza->fresh()->estado);
    }

    public function test_no_se_cancela_una_mudanza_con_tarimas_guardadas(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->capturarPieza($operador, $tarima, 'SKU-1');
        $this->guardar($tarima);

        $this->expectException(ValidationException::class);
        $this->transicionar($this->mudanza, MudanzaService::CANCELAR, 'Ya no va');
    }

    public function test_reabrir_y_suspender_exigen_motivo_y_quedan_en_bitacora(): void {
        $this->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE);
        $this->transicionar($this->mudanza, MudanzaService::CERRAR);

        try {
            $this->transicionar($this->mudanza, MudanzaService::REABRIR);
            $this->fail('Se reabrió sin motivo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('motivo', $e->errors());
        }

        $this->transicionar($this->mudanza, MudanzaService::REABRIR, 'Apareció mercancía en el almacén');
        $this->assertSame(Mudanza::EN_CIERRE, $this->mudanza->fresh()->estado);

        $entrada = Bitacora::where('accion', 'mudanza.reabrir')->sole();
        $this->assertSame($this->mudanza->id, $entrada->mudanza_id);
        $this->assertSame(['de' => Mudanza::CERRADA, 'a' => Mudanza::EN_CIERRE], $entrada->datos);
        $this->assertSame('Apareció mercancía en el almacén', $entrada->motivo);
    }

    public function test_una_transicion_invalida_se_rechaza(): void {
        $this->expectException(ValidationException::class);
        $this->transicionar($this->mudanza, MudanzaService::CERRAR);
    }

    // ---------- Historial por mudanza ----------

    public function test_el_historial_y_el_excel_muestran_solo_la_mudanza_elegida(): void {
        $operador = $this->operador();
        $vieja = $this->tarima($operador);
        $this->capturarPieza($operador, $vieja, 'SKU-VIEJA');
        $this->guardar($vieja);
        $this->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE);
        $this->transicionar($this->mudanza, MudanzaService::CERRAR);

        $nueva = $this->crearMudanza();
        $this->transicionar($nueva, MudanzaService::ACTIVAR);
        $tarima = $this->tarima($operador);
        $this->capturarPieza($operador, $tarima, 'SKU-NUEVA');
        $this->guardar($tarima);

        $this->get(route('historial'))->assertOk()->assertSee('SKU-NUEVA')->assertDontSee('SKU-VIEJA');
        $this->get(route('historial', ['mudanza' => $this->mudanza->id]))->assertOk()->assertSee('SKU-VIEJA')->assertDontSee('SKU-NUEVA');

        $fecha = now()->format('Y-m-d');
        $this->get(route('export.todas'))->assertDownload("MUD-0002_Todas_Tarimas_{$fecha}.xlsx");
        $this->get(route('export.todas', ['mudanza' => $this->mudanza->id]))->assertDownload("MUD-0001_Todas_Tarimas_{$fecha}.xlsx");
    }

    // ---------- Administración ----------

    public function test_admin_crea_mudanza_en_borrador_con_folio_consecutivo(): void {
        $admin = $this->admin();
        $this->assertSame(1, (int) DB::table('contadores')->where('clave', Mudanza::CONTADOR_FOLIO)->value('valor'));

        $this->actingAs($admin)->post(route('mudanzas.store'), [
            'cliente' => 'Euroquip', 'movimientos' => [['origen' => 'A', 'destino' => 'a']],
        ])->assertSessionHasErrors('movimientos.0.destino');
        $this->actingAs($admin)->post(route('mudanzas.store'), [
            'cliente' => 'Euroquip', 'movimientos' => [['origen' => '', 'destino' => '']],
        ])->assertSessionHasErrors('movimientos');

        $this->actingAs($admin)->post(route('mudanzas.store'), [
            'cliente' => 'Euroquip', 'movimientos' => [['origen' => 'Bodega A', 'destino' => 'Bodega B'], ['origen' => '', 'destino' => '']],
        ])->assertRedirect(route('mudanzas.index'));

        $creada = Mudanza::latest('id')->first();
        $this->assertSame('MUD-0002', $creada->folio);
        $this->assertSame(Mudanza::BORRADOR, $creada->estado);
        $this->assertSame($admin->id, $creada->created_by);
        $this->assertSame('Bodega A → Bodega B', $creada->rutas);

        $this->get(route('mudanzas.index'))->assertOk()->assertSee('MUD-0002')->assertSee('Activar');
    }

    // ---------- Varios movimientos entre almacenes ----------

    public function test_una_mudanza_incluye_varios_movimientos_y_cada_tarima_es_de_uno(): void {
        $admin = $this->admin();
        $this->transicionar($this->mudanza, MudanzaService::CANCELAR, 'Se rehace con dos movimientos');

        $this->actingAs($admin)->post(route('mudanzas.store'), [
            'cliente' => 'Euroquip',
            'movimientos' => [['origen' => '58', 'destino' => '3'], ['origen' => '5', 'destino' => '7'], ['origen' => '58', 'destino' => '3']],
        ])->assertSessionHasNoErrors();
        $mudanza = Mudanza::latest('id')->first();
        $this->assertSame('58 → 3 · 5 → 7', $mudanza->rutas, 'Los repetidos no se duplican.');
        $this->assertSame(1, Almacen::where('codigo', '58')->count(), 'El almacén 58 se reutiliza.');
        $this->transicionar($mudanza, MudanzaService::ACTIVAR);

        // Con dos movimientos hay que decir de cuál es la tarima.
        $operador = $this->operador();
        $this->actingAs($operador)->post(route('tarimas.store'))->assertSessionHasErrors('movimiento');
        $segundo = $mudanza->movimientos()->get()->firstWhere(fn ($m) => $m->origen->codigo === '5');
        $this->actingAs($operador)->post(route('tarimas.store'), ['movimiento' => $segundo->id])->assertSessionHas('ok', 'Embarque E-001 creado (5 → 7).');
        $this->assertSame($segundo->id, Tarima::sole()->mudanza_movimiento_id);

        // Un movimiento de otra mudanza no se acepta.
        $ajeno = MudanzaMovimiento::where('mudanza_id', $this->mudanza->id)->first();
        $this->actingAs($operador)->post(route('tarimas.store'), ['movimiento' => $ajeno->id])->assertSessionHasErrors('movimiento');

        // Un movimiento con tarimas no se puede quitar al editar.
        $this->actingAs($admin)->put(route('mudanzas.update', $mudanza), [
            'cliente' => 'Euroquip', 'movimientos' => [['origen' => '58', 'destino' => '3']],
        ])->assertSessionHasErrors('movimientos');
        $this->assertSame(2, $mudanza->movimientos()->count());
    }

    // ---------- Permisos del auditor ----------

    public function test_auditor_crea_y_activa_mudanzas(): void {
        $auditor = $this->auditor();
        $this->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE);
        $this->transicionar($this->mudanza, MudanzaService::CERRAR);

        $this->actingAs($auditor)->get(route('mudanzas.index'))->assertOk()->assertSee('Nueva mudanza')->assertDontSee('Editar');
        $this->actingAs($auditor)->get(route('mudanzas.create'))->assertOk();
        $this->actingAs($auditor)->post(route('mudanzas.store'), [
            'cliente' => 'Cliente del auditor', 'movimientos' => [['origen' => '10', 'destino' => '20']],
        ])->assertRedirect(route('mudanzas.index'));
        $creada = Mudanza::latest('id')->first();
        $this->assertSame($auditor->id, $creada->created_by);

        $this->actingAs($auditor)->get(route('mudanzas.index'))->assertSee('name="accion" value="activar"', false)
            ->assertDontSee('name="accion" value="cancelar"', false)->assertDontSee('name="accion" value="suspender"', false);
        $this->actingAs($auditor)->post(route('mudanzas.estado', $creada), ['accion' => MudanzaService::ACTIVAR])
            ->assertRedirect(route('mudanzas.index'));
        $this->assertSame(Mudanza::ACTIVA, $creada->fresh()->estado);
        $this->assertSame($auditor->id, $creada->fresh()->activada_by);

        // Y luego puede operar: crear tarimas en ella.
        $this->actingAs($auditor)->post(route('tarimas.store'))->assertSessionHas('ok', 'Embarque E-001 creado (10 → 20).');
    }

    public function test_auditor_no_edita_ni_hace_otras_transiciones(): void {
        $auditor = $this->auditor();

        $this->actingAs($auditor)->get(route('mudanzas.edit', $this->mudanza))->assertForbidden();
        $this->actingAs($auditor)->put(route('mudanzas.update', $this->mudanza), [
            'cliente' => 'Otro', 'movimientos' => [['origen' => '1', 'destino' => '2']],
        ])->assertForbidden();
        foreach ([MudanzaService::SUSPENDER, MudanzaService::INICIAR_CIERRE, MudanzaService::CANCELAR] as $accion) {
            $this->actingAs($auditor)->post(route('mudanzas.estado', $this->mudanza), ['accion' => $accion, 'motivo' => 'Prueba del auditor'])->assertForbidden();
        }
        $this->assertSame(Mudanza::ACTIVA, $this->mudanza->fresh()->estado);
        $this->assertSame('Euroquip', $this->mudanza->fresh()->cliente);
    }

    public function test_cambio_de_estado_por_http(): void {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('mudanzas.estado', $this->mudanza), ['accion' => MudanzaService::SUSPENDER])
            ->assertSessionHasErrors('motivo');
        $this->actingAs($admin)->post(route('mudanzas.estado', $this->mudanza), ['accion' => MudanzaService::SUSPENDER, 'motivo' => 'Inventario del cliente'])
            ->assertRedirect(route('mudanzas.index'));

        $this->assertSame(Mudanza::SUSPENDIDA, $this->mudanza->fresh()->estado);
    }

    public function test_el_operador_no_administra_mudanzas(): void {
        $operador = $this->operador();

        $this->actingAs($operador)->get(route('mudanzas.index'))->assertForbidden();
        $this->actingAs($operador)->get(route('mudanzas.create'))->assertForbidden();
        $this->actingAs($operador)->post(route('mudanzas.store'), ['cliente' => 'X', 'movimientos' => [['origen' => '1', 'destino' => '2']]])->assertForbidden();
        $this->actingAs($operador)->post(route('mudanzas.estado', $this->mudanza), ['accion' => MudanzaService::ACTIVAR])->assertForbidden();
        $this->actingAs($operador)->post(route('mudanzas.estado', $this->mudanza), ['accion' => MudanzaService::SUSPENDER, 'motivo' => 'Quiero parar'])->assertForbidden();
        $this->assertSame(Mudanza::ACTIVA, $this->mudanza->fresh()->estado);
    }

    public function test_la_mudanza_en_curso_se_ve_en_todas_las_pantallas(): void {
        $this->actingAs($this->operador())->get(route('historial'))->assertOk()->assertSee('MUD-0001 · Euroquip · Activa');
    }
}
