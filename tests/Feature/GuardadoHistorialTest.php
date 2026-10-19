<?php

namespace Tests\Feature;

use App\Models\InventarioItem;
use App\Models\Role;
use App\Models\Tarima;
use App\Models\User;
use App\Services\TarimaService;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class GuardadoHistorialTest extends TestCase {
    use PreparaWms;

    private function tarimaDe(User $usuario): Tarima {
        return $this->tarima($usuario);
    }

    /** Captura una pieza (SKU del inventario con esa descripción + la N/S "NS-{sku}"). */
    private function capturar(User $usuario, Tarima $tarima, string $sku, string $descripcion = 'Caja'): void {
        if (! InventarioItem::where('sku', $sku)->exists()) {
            $this->enInventario([['sku' => $sku, 'descripcion' => $descripcion, 'ns_lote' => "LOTE-{$sku}"]]);
        }
        $this->actingAs($usuario)->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($usuario, $tarima, $sku);
        $this->escanearNs("NS-{$sku}")->assertSessionHasNoErrors();
        $this->post(route('escaneo.padre.cerrar'));
    }

    /** Tarima creada por $usuario, impresa y guardada por el flujo HTTP real. */
    private function tarimaGuardada(User $usuario, string $sku = 'SKU-1', string $descripcion = 'Caja'): Tarima {
        $tarima = $this->tarimaDe($usuario);
        $this->capturar($usuario, $tarima, $sku, $descripcion);
        $this->actingAs($usuario)->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->actingAs($usuario)->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    // ---------- Guardar ----------

    public function test_guardar_esta_deshabilitado_hasta_imprimir(): void {
        $operador = $this->operador();
        $tarima = $this->tarimaDe($operador);
        $this->capturar($operador, $tarima, 'SKU-1');

        $this->get(route('wms'))->assertOk()->assertSee('Primero imprime las etiquetas de las piezas de este embarque')->assertSee('1 sin imprimir');

        $this->postJson(route('tarimas.guardar', $tarima))
            ->assertStatus(422)
            ->assertJsonPath('errors.tarima.0', "Primero imprime las etiquetas de las piezas del {$tarima->nombre}.");
        $this->assertNull($tarima->fresh()->guardada_at);
    }

    public function test_imprimir_habilita_solo_esa_tarima_y_guardar_la_quita_de_la_vista(): void {
        $operador = $this->operador();
        $impresa = $this->tarimaDe($operador);
        $pendiente = $this->tarimaDe($operador);
        $this->capturar($operador, $impresa, 'SKU-A');
        $this->capturar($operador, $pendiente, 'SKU-B');

        $this->postJson(route('tarimas.impresa', $impresa))->assertOk()->assertJson(['impresa' => true]);

        $this->assertNotNull($impresa->fresh()->impresa_at);
        $this->assertNull($pendiente->fresh()->impresa_at);

        $this->postJson(route('tarimas.guardar', $impresa))
            ->assertOk()
            ->assertJson([
                'guardada' => true,
                'nueva' => true,
                'mensaje' => 'Información Guardada. Dentro del historial puedes visualizarla',
            ]);

        $tarima = $impresa->fresh();
        $this->assertNotNull($tarima->guardada_at);
        $this->assertSame($operador->id, $tarima->guardada_by);

        $this->get(route('wms'))->assertOk()->assertDontSee($impresa->uuid)->assertSee($pendiente->uuid);
        $this->get(route('historial'))->assertOk()->assertSee('SKU-A')->assertDontSee('SKU-B');
    }

    public function test_guardar_dos_veces_no_duplica_ni_falla(): void {
        $operador = $this->operador();
        $tarima = $this->tarimaGuardada($operador);
        $primera = $tarima->guardada_at;

        $this->actingAs($operador)->postJson(route('tarimas.guardar', $tarima))
            ->assertOk()
            ->assertJson(['guardada' => true, 'nueva' => false]);

        $this->assertEquals($primera, $tarima->fresh()->guardada_at);
        $this->assertSame(1, Tarima::guardadas()->count());
    }

    public function test_cambiar_el_contenido_despues_de_imprimir_obliga_a_reimprimir(): void {
        $operador = $this->operador();
        $tarima = $this->tarimaDe($operador);
        $this->capturar($operador, $tarima, 'SKU-1');
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();

        $this->capturar($operador, $tarima, 'SKU-2');

        $this->assertNull($tarima->fresh()->impresa_at);
        $this->postJson(route('tarimas.guardar', $tarima))->assertStatus(422);
    }

    public function test_no_se_puede_imprimir_una_tarima_vacia(): void {
        $operador = $this->operador();
        $this->actingAs($operador)->postJson(route('tarimas.impresa', $this->tarimaDe($operador)))->assertStatus(422);
    }

    public function test_tarima_guardada_no_admite_capturas_ni_vaciado(): void {
        $operador = $this->operador();
        $tarima = $this->tarimaGuardada($operador);

        $this->skuEnInventario('OTRO');
        $this->post(route('escaneo.padre'), ['sku' => 'OTRO', 'tarima' => $tarima->uuid])->assertSessionHasErrors('tarima');

        $this->delete(route('tarimas.vaciar', $tarima))->assertForbidden();
        $this->assertSame(1, $tarima->registros()->count());
    }

    public function test_guardar_cierra_el_sku_padre_activo_de_esa_tarima(): void {
        $operador = $this->operador();
        $tarima = $this->tarimaDe($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');
        $this->postJson(route('tarimas.impresa', $tarima));

        $this->postJson(route('tarimas.guardar', $tarima))->assertOk()->assertJson(['recargar' => true]);

        $this->escanearNs('SN-2')->assertSessionHasErrors('numero_serie');
    }

    public function test_la_reimpresion_de_un_embarque_guardado_da_las_mismas_etiquetas(): void {
        $operador = $this->operador();
        $this->travelTo(now()->setDate(2026, 1, 15));
        $tarima = $this->tarimaGuardada($operador);
        $antes = $this->actingAs($operador)->get(route('etiquetas.zpl', $tarima))->getContent();
        $this->travelBack();

        // Sin fechas ni datos que cambien: la etiqueta reimpresa es idéntica a la original.
        $this->assertSame($antes, $this->actingAs($operador)->get(route('etiquetas.zpl', $tarima))->getContent());
        $this->assertStringNotContainsString('TAR-', $antes);
    }

    // ---------- Permisos por rol (IDOR) ----------

    public function test_operador_ve_todas_las_tarimas_guardadas_de_la_mudanza(): void {
        $op1 = $this->operador();
        $op2 = $this->operador();
        $deOp1 = $this->tarimaGuardada($op1, 'SKU-OP1');
        $deOp2 = $this->tarimaGuardada($op2, 'SKU-OP2');

        // Todos trabajan la misma mudanza: el historial muestra todo, con quién lo creó.
        $this->actingAs($op1)->get(route('historial'))->assertOk()
            ->assertSee('SKU-OP1')->assertSee('SKU-OP2')->assertSee('Creado por');

        // Ver en lectura sí; operar la de otro, no.
        $this->actingAs($op1)->get(route('etiquetas.show', $deOp2))->assertOk();
        $this->actingAs($op1)->postJson(route('tarimas.impresa', $deOp2))->assertForbidden();
        $this->actingAs($op1)->postJson(route('tarimas.guardar', $deOp2))->assertForbidden();
        $this->actingAs($op1)->get(route('etiquetas.show', $deOp1))->assertOk();
    }

    public function test_admin_ve_todas_las_tarimas_guardadas(): void {
        $this->tarimaGuardada($this->operador(), 'SKU-OP1');
        $deOp2 = $this->tarimaGuardada($this->operador(), 'SKU-OP2');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('historial'))->assertOk()->assertSee('SKU-OP1')->assertSee('SKU-OP2');
        $this->actingAs($admin)->get(route('etiquetas.show', $deOp2))->assertOk();
    }

    public function test_auditor_ve_todas_las_tarimas_guardadas(): void {
        $auditor = $this->auditor();
        $deOp = $this->tarimaGuardada($this->operador(), 'SKU-OP1');

        $this->actingAs($auditor)->get(route('historial'))->assertOk()->assertSee('SKU-OP1')->assertSee('Creado por');
        $this->actingAs($auditor)->get(route('etiquetas.show', $deOp))->assertOk();
        $this->actingAs($auditor)->get(route('etiquetas.zpl', $deOp))->assertOk();
    }

    public function test_operador_ve_pero_no_trabaja_tarimas_temporales_de_otros(): void {
        $op1 = $this->operador();
        $op2 = $this->operador();
        $ajena = $this->tarimaDe($op2);
        $this->capturar($op2, $ajena, 'SKU-AJENO');
        $propia = $this->tarimaDe($op1);
        $this->flushSession();

        // La ajena aparece solo en la lista de lectura (sin su uuid para capturar ni su contenido).
        $this->actingAs($op1)->get(route('wms'))->assertOk()
            ->assertSee($propia->uuid)->assertDontSee($ajena->uuid)->assertDontSee('SKU-AJENO')
            ->assertSee('En captura por otros')->assertSee($ajena->codigo.' ('.$op2->name.')');

        $this->skuEnInventario('INTRUSO');
        $this->actingAs($op1)->post(route('escaneo.padre'), [
            'sku' => 'INTRUSO', 'tarima' => $ajena->uuid,
        ])->assertForbidden();
        $this->actingAs($op1)->delete(route('tarimas.vaciar', $ajena))->assertForbidden();
        $this->actingAs($op1)->postJson(route('tarimas.impresa', $ajena))->assertForbidden();
        $this->actingAs($op1)->post(route('tarimas.anular', $ajena), ['motivo' => 'No es mía'])->assertForbidden();

        $this->assertSame(['SKU-AJENO'], $ajena->registros()->pluck('sku')->all());
    }

    public function test_auditor_ve_y_trabaja_tarimas_temporales_de_otros_sin_permisos_de_admin(): void {
        $auditor = $this->auditor();
        $ajena = $this->tarimaDe($this->operador());

        $this->actingAs($auditor)->get(route('wms'))->assertOk()->assertSee($ajena->uuid);
        $this->capturar($auditor, $ajena, 'SKU-AUD');

        // Como el operador, no anula una tarima con contenido (eso es del admin).
        $this->actingAs($auditor)->post(route('tarimas.anular', $ajena), ['motivo' => 'Prueba de auditor'])->assertForbidden();

        // Vacía con borrado lógico, como el operador.
        $this->actingAs($auditor)->delete(route('tarimas.vaciar', $ajena))->assertRedirect(route('wms'));
        $this->assertSame(1, $ajena->registros()->onlyTrashed()->count());

        $this->actingAs($auditor)->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_exportar_todas_del_operador_excluye_temporales_de_otros(): void {
        $this->capturar($op2 = $this->operador(), $this->tarimaDe($op2), 'SKU-AJENO');

        $this->actingAs($this->operador())->get(route('export.todas'))->assertSessionHasErrors('tarima');
    }

    public function test_exportar_todas_incluye_las_guardadas_de_otros_operadores(): void {
        $this->tarimaGuardada($this->operador());
        $op = $this->operador();

        $this->actingAs($op)->get(route('export.todas'))->assertOk()
            ->assertDownload('MUD-0001_Todas_Tarimas_'.now()->format('Y-m-d').'.xlsx');
    }

    // ---------- Historial: filtros, paginación y QR ----------

    public function test_filtros_individuales_y_combinados(): void {
        $admin = $this->admin();
        $t1 = $this->tarimaGuardada($admin, 'SKU-BOMBA', 'Bomba hidráulica');
        $this->tarimaGuardada($admin, 'SKU-CABLE', 'Cable eléctrico');

        $this->get(route('historial', ['descripcion' => 'bomba']))->assertSee('SKU-BOMBA')->assertDontSee('SKU-CABLE');
        $this->get(route('historial', ['numero_serie' => 'NS-SKU-CABLE']))->assertSee('SKU-CABLE')->assertDontSee('SKU-BOMBA');
        $this->get(route('historial', ['tarima' => "Tarima {$t1->id}"]))->assertSee('SKU-BOMBA')->assertDontSee('SKU-CABLE');
        // El campo de filtro conserva lo escrito ("NS-SKU-CABLE"): se revisa que no haya filas.
        $this->get(route('historial', ['descripcion' => 'bomba', 'numero_serie' => 'NS-SKU-CABLE']))
            ->assertDontSee('SKU-BOMBA')->assertDontSee('<span class="chip">SKU-CABLE</span>', false)->assertSee('Sin resultados');
    }

    public function test_paginacion_conserva_los_filtros(): void {
        $admin = $this->admin();
        foreach (range(1, 17) as $i) {
            $this->tarimaGuardada($admin, "SKU-{$i}", 'Filtro común');
        }

        $this->get(route('historial', ['descripcion' => 'común']))
            ->assertOk()
            ->assertSee('descripcion=com%C3%BAn&amp;page=2', false);
        $this->get(route('historial', ['descripcion' => 'común', 'page' => 2]))->assertOk()->assertSee('de <b>17</b>', false);
    }

    public function test_ver_piezas_usa_la_url_firmada_y_la_etiqueta_ya_no_lleva_qr(): void {
        $operador = $this->operador();
        $tarima = $this->tarimaDe($operador);
        $this->activarPadre($operador, $tarima, 'SKU-QR');
        $this->escanearNs('SN-QR');
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();
        $url = app(TarimaService::class)->urlDetalle($tarima);

        $this->actingAs($operador)->get(route('historial'))->assertSee(e($url), false);

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->getContent();
        $this->assertStringNotContainsString($url, $zpl);
        $this->assertStringNotContainsString('^BQN', $zpl);

        auth()->logout();
        $this->get($url)->assertOk()->assertSee('SKU-QR')->assertSee('Sin cargar');
    }

    public function test_historial_requiere_sesion(): void {
        $this->get(route('historial'))->assertRedirect(route('login'));
    }
}
