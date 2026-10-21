<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Entrega;
use App\Models\Incidencia;
use App\Models\MovimientoPartida;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Registro;
use App\Models\Role;
use App\Models\SkuAlta;
use App\Models\Tarima;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * Mejoras de LogicaNegocio.md: rol Auditor, "Editar números de serie" en el
 * escaneo, correcciones de N/S en el Historial con reimpresión individual,
 * traslado completo y Reporte Diario.
 */
class MejorasHandheldTest extends TestCase {
    use PreparaWms;

    private ?User $capturista = null;

    private function op(): User {
        return $this->capturista ??= $this->operador();
    }

    /** Embarque en captura con un modelo y sus N/S, sin imprimir. */
    private function enCaptura(array $series, string $sku = 'SKU-P'): Tarima {
        $tarima = $this->tarima($this->op());
        $this->activarPadre($this->op(), $tarima, $sku);
        foreach ($series as $ns) {
            $this->escanearNs($ns)->assertSessionHasNoErrors();
        }
        $this->post(route('escaneo.padre.cerrar'));

        return $tarima;
    }

    private function guardar(Tarima $tarima): Tarima {
        $this->actingAs($this->op())->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    private function ns(string $valor): NumeroSerie {
        return NumeroSerie::where('numero_serie', $valor)->sole();
    }

    // ---------- 1. Roles ----------

    public function test_cambiar_un_administrador_a_auditor_conserva_sus_datos_y_restringe_sus_permisos(): void {
        $jefe = $this->admin();
        $antiguo = $this->admin();
        $tarima = $this->guardar($this->enCaptura(['NS-1']));
        Tarima::whereKey($tarima->id)->update(['created_by' => $antiguo->id]);

        $this->actingAs($jefe)->put(route('usuarios.update', $antiguo), [
            'name' => $antiguo->name, 'email' => $antiguo->email, 'rol' => Role::AUDITOR, 'is_active' => '1',
        ])->assertRedirect(route('usuarios.index'))->assertSessionHasNoErrors();

        $antiguo->refresh();
        $this->assertTrue($antiguo->hasRole(Role::AUDITOR));
        $this->assertFalse($antiguo->isAdmin());
        $this->assertSame($antiguo->id, $tarima->fresh()->created_by, 'Sus registros siguen ligados a él.');

        $this->actingAs($antiguo);
        foreach (['wms', 'historial', 'incidencias.index', 'embarques.index', 'inventarios.index', 'entregas.index', 'manuales.index', 'mudanzas.index', 'mudanzas.create'] as $ruta) {
            $this->get(route($ruta))->assertOk();
        }
        $this->get(route('tarimas.corregir', $tarima))->assertOk();
        foreach (['usuarios.index', 'usuarios.create', 'impresoras.index', 'manuales.create'] as $ruta) {
            $this->get(route($ruta))->assertForbidden();
        }
        $this->put(route('usuarios.update', $jefe), ['name' => 'X', 'email' => $jefe->email, 'rol' => Role::OPERADOR, 'is_active' => '1'])->assertForbidden();
        $this->get(route('mudanzas.edit', $this->mudanza))->assertForbidden();

        // En la interfaz tampoco aparece la administración.
        $this->get(route('wms'))->assertDontSee(route('usuarios.index'))->assertDontSee(route('impresoras.index'))
            ->assertSee(route('entregas.index'))->assertSee('Reporte Diario');
    }

    public function test_siempre_queda_un_administrador_activo(): void {
        $unico = $this->admin();
        $otro = $this->admin();
        $otro->update(['is_active' => false]);

        // El único activo no puede bajarse a sí mismo (regla existente) y nadie más puede dejar el sistema sin admin.
        $this->actingAs($unico)->put(route('usuarios.update', $unico), [
            'name' => $unico->name, 'email' => $unico->email, 'rol' => Role::AUDITOR, 'is_active' => '1',
        ])->assertSessionHasErrors('rol');
        $this->assertTrue($unico->fresh()->isAdmin());
    }

    // ---------- 2. Escaneo: Editar números de serie ----------

    public function test_editar_numeros_de_serie_agrega_edita_marca_y_elimina_antes_de_imprimir(): void {
        $tarima = $this->enCaptura(['NS-1', 'NS-2']);
        $registro = Registro::where('tarima_id', $tarima->id)->sole();
        $this->skuEnInventario('SKU-P', ['NS-3']);
        $this->skuEnInventario('SKU-Q', ['NS-9']);

        $this->get(route('wms'))->assertOk()->assertSee('Editar números de serie');

        // Agregar al modelo sin activarlo.
        $this->post(route('tarimas.ns.agregar', [$tarima, $registro]), ['nueva_ns' => 'NS-3', 'registro_panel' => $registro->id])
            ->assertRedirect(route('wms'))->assertSessionHasNoErrors()->assertSessionHas('panel', $registro->id);
        $this->assertSame($registro->id, $this->ns('NS-3')->registro_id);

        // Editar N/S y SKU: pasa al modelo SKU-Q del mismo embarque y coincide con el inventario.
        $this->put(route('tarimas.ns.editar', [$tarima, $this->ns('NS-2')]), ['nuevo_sku' => 'SKU-Q', 'nueva_ns' => 'NS-9'])
            ->assertSessionHasNoErrors();
        $editada = NumeroSerie::with('registro')->where('numero_serie', 'NS-9')->sole();
        $this->assertSame('SKU-Q', $editada->registro->sku);
        $this->assertSame($tarima->id, $editada->registro->tarima_id);
        $this->assertNotNull($editada->inventario_item_id);
        $this->assertNull(NumeroSerie::where('numero_serie', 'NS-2')->first());
        $this->assertSame(['sku' => 'SKU-P', 'numero_serie' => 'NS-2'], Bitacora::where('accion', 'numero_serie.editada')->sole()->datos['antes']);

        // Una N/S que ya existe no se duplica.
        $this->put(route('tarimas.ns.editar', [$tarima, $this->ns('NS-3')]), ['nuevo_sku' => 'SKU-P', 'nueva_ns' => 'NS-1'])
            ->assertSessionHasErrors('anomalia');

        // Marcar dañado y quitar la marca.
        $id = $this->ns('NS-1')->id;
        $this->post(route('tarimas.anomalias', $tarima), ['accion' => 'marcar', 'ns' => [$id], 'nota' => 'Golpe'])->assertSessionHasNoErrors();
        $this->assertTrue($this->ns('NS-1')->tieneAnomalia());
        $this->post(route('tarimas.anomalias', $tarima), ['accion' => 'quitar', 'ns' => [$id]])->assertSessionHasNoErrors();
        $this->assertFalse($this->ns('NS-1')->tieneAnomalia());

        // Eliminar.
        $this->post(route('tarimas.anomalias', $tarima), ['accion' => 'eliminar', 'ns' => [$id]])->assertSessionHasNoErrors();
        $this->assertSoftDeleted('numeros_serie', ['id' => $id]);
    }

    public function test_con_etiquetas_impresas_solo_se_marcan_danos(): void {
        $tarima = $this->enCaptura(['NS-1', 'NS-2']);
        $registro = Registro::where('tarima_id', $tarima->id)->sole();
        $this->postJson(route('tarimas.impresa', $tarima), ['piezas' => (string) $this->ns('NS-1')->id])->assertOk();
        $this->skuEnInventario('SKU-P', ['NS-3']);

        $this->post(route('tarimas.ns.agregar', [$tarima, $registro]), ['nueva_ns' => 'NS-3'])->assertSessionHasErrors('anomalia');
        $this->put(route('tarimas.ns.editar', [$tarima, $this->ns('NS-2')]), ['nuevo_sku' => 'SKU-P', 'nueva_ns' => 'NS-X'])->assertSessionHasErrors('anomalia');
        $this->post(route('tarimas.anomalias', $tarima), ['accion' => 'eliminar', 'ns' => [$this->ns('NS-2')->id]])->assertSessionHasErrors('anomalia');
        $this->assertSame(2, NumeroSerie::count());
        $this->assertNull(NumeroSerie::where('numero_serie', 'NS-3')->first());

        $this->post(route('tarimas.anomalias', $tarima), ['accion' => 'marcar', 'ns' => [$this->ns('NS-2')->id]])->assertSessionHasNoErrors();
        $this->assertTrue($this->ns('NS-2')->tieneAnomalia());

        // En la pantalla el panel ya no ofrece agregar, editar ni eliminar.
        $this->get(route('wms'))->assertOk()->assertSee('Ya se imprimieron etiquetas de este embarque')
            ->assertDontSee(route('tarimas.ns.agregar', [$tarima, $registro]));

        // El escaneo continuo no cambia: el lector sigue agregando piezas.
        $this->activarPadre($this->op(), $tarima, 'SKU-P');
        $this->escanearNsJson('NS-4')->assertOk()->assertJson(['estado' => 'registrada']);
    }

    public function test_editar_una_ns_de_otro_embarque_se_rechaza(): void {
        $tarima = $this->enCaptura(['NS-1']);
        $otra = $this->enCaptura(['NS-2']);

        $this->put(route('tarimas.ns.editar', [$tarima, $this->ns('NS-2')]), ['nuevo_sku' => 'SKU-P', 'nueva_ns' => 'NS-5'])
            ->assertSessionHasErrors('anomalia');
        $this->assertSame($otra->id, $this->ns('NS-2')->registro->tarima_id);
    }

    // ---------- 3. Historial: Corregir ----------

    public function test_agregar_ns_en_el_historial_da_consecutivo_nuevo_y_reimprime_solo_esa_etiqueta(): void {
        $tarima = $this->guardar($this->enCaptura(['NS-1', 'NS-2']));
        $modelo = Registro::where('tarima_id', $tarima->id)->sole();
        $auditor = $this->auditor();

        $this->actingAs($auditor)->post(route('tarimas.partidas.agregar', $tarima), [
            'modelo' => $modelo->id, 'numero_serie' => 'NS-FALTA', 'motivo' => 'Pieza sin escanear',
        ])->assertRedirect(route('tarimas.corregir', $tarima))->assertSessionHasNoErrors();

        $serie = $this->ns('NS-FALTA');
        $partida = Partida::where('numero_serie_id', $serie->id)->sole();
        $this->assertSame(3, $partida->consecutivo);
        $this->assertTrue($tarima->fresh()->requiereReimpresion());
        $this->assertSame(MovimientoPartida::NS_AGREGADA, $partida->movimientos()->sole()->tipo);
        // Sin coincidencia en el inventario: su incidencia queda ligada a la pieza.
        $this->assertTrue($partida->incidencias()->where('tipo', Incidencia::NO_EN_INVENTARIO)->exists());

        $reimprimir = session('reimprimir');
        $this->assertSame(route('etiquetas.show', ['tarima' => $tarima, 'piezas' => $serie->id]), $reimprimir['url']);
        $this->get(route('tarimas.corregir', $tarima))->assertSee('Reimprimir')->assertSee($reimprimir['url'], false);

        // Solo sale esa etiqueta, y confirmarla deja las demás como estaban.
        $zpl = $this->get(route('etiquetas.zpl', ['tarima' => $tarima, 'piezas' => $serie->id]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($zpl, '^XA'));
        $this->assertStringContainsString('NS-FALTA', $zpl);
        $this->assertStringNotContainsString('NS-1', $zpl);
        $impresaAntes = $this->ns('NS-1')->etiqueta_impresa_at;
        $this->postJson(route('tarimas.impresa', $tarima), ['piezas' => (string) $serie->id])->assertOk()->assertJson(['pendientes' => 0]);
        $this->assertEquals($impresaAntes, $this->ns('NS-1')->etiqueta_impresa_at);
        $this->assertFalse($tarima->fresh()->requiereReimpresion());
    }

    public function test_editar_ns_en_el_historial_conserva_el_consecutivo_y_la_trazabilidad(): void {
        $tarima = $this->guardar($this->enCaptura(['NS-1', 'NS-MAL']));
        $this->skuEnInventario('SKU-Q', ['NS-BIEN']);
        $partida = Partida::where('numero_serie', 'NS-MAL')->sole();

        $this->actingAs($this->auditor())->put(route('tarimas.partidas.editar', $tarima), [
            'partida' => $partida->id, 'sku' => 'SKU-Q', 'numero_serie' => 'NS-BIEN', 'motivo' => 'Capturada a mano con error',
        ])->assertSessionHasNoErrors();

        $partida->refresh();
        $this->assertSame([2, 'SKU-Q', 'NS-BIEN'], [$partida->consecutivo, $partida->sku, $partida->numero_serie]);
        $this->assertSame('SKU-Q', $partida->numeroSerie->registro->sku);
        $this->assertNull($partida->numeroSerie->etiqueta_impresa_at, 'Su etiqueta debe reimprimirse.');
        $movimiento = $partida->movimientos()->where('tipo', MovimientoPartida::NS_EDITADA)->sole();
        $this->assertSame('SKU SKU-P · N/S NS-MAL → SKU SKU-Q · N/S NS-BIEN', $movimiento->detalle);
        $this->assertSame('Capturada a mano con error', $movimiento->motivo);
        $this->assertStringContainsString('piezas='.$partida->numero_serie_id, session('reimprimir')['url']);
        $this->get(route('tarimas.corregir', $tarima))->assertSee('N/S editada')->assertSee('NS-MAL → SKU SKU-Q');

        // La N/S anterior queda libre y la nueva no se puede repetir.
        $this->put(route('tarimas.partidas.editar', $tarima), [
            'partida' => Partida::where('numero_serie', 'NS-1')->value('id'), 'sku' => 'SKU-P', 'numero_serie' => 'NS-BIEN', 'motivo' => 'Prueba duplicado',
        ])->assertSessionHasErrors('numero_serie');
    }

    public function test_corregir_puede_dar_de_alta_un_sku_nuevo_al_agregar_o_editar(): void {
        $tarima = $this->guardar($this->enCaptura(['NS-1', 'NS-2']));
        $this->actingAs($this->auditor())->get(route('tarimas.corregir', $tarima))->assertOk()->assertSee('Es un SKU nuevo')->assertSee('Otro SKU');

        // Sin la casilla, un SKU que no está en el inventario se sigue rechazando.
        $editar = ['partida' => Partida::where('numero_serie', 'NS-2')->value('id'), 'sku' => 'NUEVO-9', 'numero_serie' => 'NS-2', 'motivo' => 'SKU equivocado'];
        $this->put(route('tarimas.partidas.editar', $tarima), $editar)->assertSessionHasErrors('sku');
        $this->assertSame(0, SkuAlta::count());

        // Con la casilla: se da de alta en el almacén de origen y la pieza pasa a ese SKU.
        $this->put(route('tarimas.partidas.editar', $tarima), $editar + ['alta_sku' => 1, 'descripcion' => 'Compresor'])->assertSessionHasNoErrors();
        $alta = SkuAlta::sole();
        $this->assertSame(['NUEVO-9', 'Compresor', $tarima->almacenOrigen()->id], [$alta->sku, $alta->descripcion, $alta->almacen_id]);
        $this->assertSame(['NUEVO-9', 2], [Partida::where('numero_serie', 'NS-2')->value('sku'), Partida::where('numero_serie', 'NS-2')->value('consecutivo')]);
        $this->assertTrue($this->ns('NS-2')->pendienteRevision());

        // Agregar con «Otro SKU»: nuevo modelo en el embarque; si la N/S se rechaza no queda nada.
        $agregar = ['modelo' => 'otro', 'sku' => 'NUEVO-10', 'alta_sku' => 1, 'motivo' => 'Faltó registrar'];
        $this->post(route('tarimas.partidas.agregar', $tarima), $agregar + ['numero_serie' => 'NS-1'])->assertSessionHasErrors('numero_serie');
        $this->assertSame(1, SkuAlta::count());
        $this->assertFalse(Registro::where('tarima_id', $tarima->id)->where('sku', 'NUEVO-10')->exists());

        $this->post(route('tarimas.partidas.agregar', $tarima), $agregar + ['numero_serie' => 'NS-NUEVA'])->assertSessionHasNoErrors();
        $partida = Partida::where('numero_serie', 'NS-NUEVA')->sole();
        $this->assertSame(['NUEVO-10', 3], [$partida->sku, $partida->consecutivo]);
        $this->assertSame('NUEVO-10', $this->ns('NS-NUEVA')->registro->sku);
        $this->assertSame(2, SkuAlta::count());
        $this->assertSame(2, Bitacora::where('accion', 'sku.alta')->count());

        // «Otro SKU» con un SKU que ya existe no necesita la casilla.
        $this->post(route('tarimas.partidas.agregar', $tarima), ['modelo' => 'otro', 'sku' => 'nuevo-9', 'numero_serie' => 'NS-OTRA', 'motivo' => 'Faltó registrar'])
            ->assertSessionHasNoErrors();
        $this->assertSame('NUEVO-9', Partida::where('numero_serie', 'NS-OTRA')->value('sku'));
    }

    public function test_eliminar_ns_en_el_historial_queda_registrada_y_libera_la_ns(): void {
        $tarima = $this->guardar($this->enCaptura(['NS-1', 'NS-DOBLE']));
        $partida = Partida::where('numero_serie', 'NS-DOBLE')->sole();

        $this->actingAs($this->auditor())->post(route('tarimas.partidas.eliminar', $tarima), [
            'partidas' => [$partida->id], 'motivo' => 'Lectura duplicada',
        ])->assertSessionHasNoErrors();

        $partida->refresh();
        $this->assertTrue($partida->estaRetirada());
        $this->assertSame([MovimientoPartida::ALTA, MovimientoPartida::NS_ELIMINADA], $partida->movimientos()->pluck('tipo')->all());
        $this->assertSoftDeleted('numeros_serie', ['id' => $partida->numero_serie_id]);
        $this->assertSame(1, $tarima->fresh()->partidas()->count());
        $this->get(route('tarimas.corregir', $tarima))->assertSee('N/S eliminada')->assertSee('Lectura duplicada');
    }

    public function test_si_todas_las_piezas_se_trasladan_se_indica_el_embarque_destino(): void {
        $origen = $this->guardar($this->enCaptura(['NS-1', 'NS-2']));
        $destino = $this->guardar($this->enCaptura(['NS-3']));
        $auditor = $this->auditor();

        $this->actingAs($auditor)->post(route('tarimas.partidas.mover', $origen), [
            'partidas' => Partida::where('tarima_id', $origen->id)->pluck('id')->all(),
            'destino' => $destino->uuid, 'motivo' => 'Van juntas en la caja',
        ])->assertSessionHas('ok', fn ($m) => str_starts_with($m, 'Las piezas se movieron al Embarque E-002'));

        $this->get(route('tarimas.corregir', $origen))->assertOk()
            ->assertSee('Las piezas se movieron al embarque E-002')->assertDontSee('El embarque no tiene piezas');
        $this->get(route('historial'))->assertSee('Las piezas se movieron al embarque E-002');
        $this->get(app(\App\Services\TarimaService::class)->urlDetalle($origen))
            ->assertSee('Las piezas se movieron al embarque E-002')->assertDontSee('Este embarque está vacío');
    }

    public function test_un_embarque_con_todas_sus_piezas_trasladadas_se_llama_adicional_del_destino(): void {
        $destino = $this->guardar($this->enCaptura(['NS-1']));
        $e2 = $this->guardar($this->enCaptura(['NS-2', 'NS-3']));
        $e3 = $this->guardar($this->enCaptura(['NS-4']));
        $this->actingAs($this->auditor());
        $mover = fn (Tarima $de, array $ns, Tarima $a) => $this->post(route('tarimas.partidas.mover', $de), [
            'partidas' => Partida::whereIn('numero_serie', $ns)->pluck('id')->all(), 'destino' => $a->uuid, 'motivo' => 'Van juntas en la caja',
        ])->assertSessionHasNoErrors();

        // Solo una parte: conserva su número.
        $mover($e2, ['NS-2'], $destino);
        $this->assertSame('E-002', $e2->fresh()->codigo);

        // Todas al E-001: se llama "E-001_adicional"; el segundo, "E-001_adicional_2".
        $mover($e2, ['NS-3'], $destino);
        $mover($e3, ['NS-4'], $destino);
        $this->assertSame(['Embarque E-001_adicional', 'E-002'], [$e2->fresh()->nombre, $e2->fresh()->codigo_original]);
        $this->assertSame('E-001_adicional_2', $e3->fresh()->codigo);
        $this->assertSame(2, Bitacora::where('accion', 'tarima.renombrada')->count());
        $this->get(route('historial'))->assertSee('Embarque E-001_adicional')->assertSee('(antes E-002)');
        $this->assertEqualsCanonicalizing([$destino->id, $e2->id, $e3->id], Tarima::filtrosHistorial(['tarima' => 'E-001'])->pluck('id')->all());

        // Si vuelve a tener piezas, recupera su número.
        $mover($destino, ['NS-4'], $e3);
        $this->assertSame(['E-003', 'E-001_adicional'], [$e3->fresh()->codigo, $e2->fresh()->codigo]);
    }

    // ---------- 4. Reporte Diario ----------

    public function test_el_reporte_diario_muestra_y_exporta_solo_las_piezas_del_dia(): void {
        Storage::fake('documentos');
        $ayer = now()->subDay()->setTime(11, 0);
        $this->travelTo($ayer);
        $this->guardar($this->enCaptura(['AYER-1', 'AYER-2']));
        $this->travelBack();
        $this->guardar($this->enCaptura(['HOY-1']));
        $auditor = $this->auditor();

        $this->actingAs($auditor)->get(route('entregas.index', ['fecha' => $ayer->format('Y-m-d')]))->assertOk()
            ->assertSee('Reporte Diario')->assertSee('2 piezas guardadas en 1 embarque');
        $this->get(route('entregas.index'))->assertOk()->assertSee('1 pieza guardadas en 1 embarque');

        $this->post(route('entregas.store'), ['mudanza' => $this->mudanza->id, 'fecha' => $ayer->format('Y-m-d')])->assertSessionHasNoErrors();
        $entrega = Entrega::sole();
        $this->assertSame($ayer->format('Y-m-d'), $entrega->fecha->format('Y-m-d'));
        $this->assertSame(2, $entrega->total_partidas);

        $ruta = tempnam(sys_get_temp_dir(), 'rd');
        file_put_contents($ruta, Storage::disk('documentos')->get($entrega->archivo));
        $filas = IOFactory::load($ruta)->getSheetByName('Inventario')->toArray(null, false, false);
        @unlink($ruta);
        $this->assertSame(['AYER-1', 'AYER-2'], array_column(array_slice($filas, 1), 6));

        // El reporte de ayer no aparece al consultar hoy, y un día sin piezas no genera Excel.
        $this->get(route('entregas.index'))->assertDontSee('v'.$entrega->version.'</b>', false);
        $this->post(route('entregas.store'), ['mudanza' => $this->mudanza->id, 'fecha' => now()->subDays(5)->format('Y-m-d')])->assertSessionHasErrors('entrega');
        $this->post(route('entregas.store'), ['mudanza' => $this->mudanza->id, 'fecha' => now()->addDay()->format('Y-m-d')])->assertSessionHasErrors('fecha');
        $this->assertSame(1, Entrega::count());
    }
}
