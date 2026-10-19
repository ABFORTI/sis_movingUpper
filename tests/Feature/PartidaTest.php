<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Mudanza;
use App\Models\MovimientoPartida;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use App\Services\MudanzaService;
use App\Services\TarimaService;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class PartidaTest extends TestCase {
    use PreparaWms;

    private ?User $capturista = null;

    /** El operador que captura en estas pruebas (uno por prueba). */
    private function op(): User {
        return $this->capturista ??= $this->operador();
    }

    /** Tarima del operador con un SKU y sus N/S (más SKUs sueltos históricos opcionales). */
    private function tarimaCon(array $series, string $sku = 'SKU-P', array $sueltos = [], bool $guardar = true): Tarima {
        $tarima = $this->tarima($this->op());
        if ($series !== []) {
            $this->activarPadre($this->op(), $tarima, $sku);
            foreach ($series as $ns) {
                $this->escanearNs($ns)->assertSessionHasNoErrors();
            }
            $this->post(route('escaneo.padre.cerrar'));
        }
        // SKU sueltos: registros históricos (el modo "Solo SKUs" se retiró), que siguen pudiéndose corregir.
        foreach ($sueltos as $suelto) {
            $this->registroHistoricoSinNs($this->op(), $tarima, $suelto);
        }

        return $guardar ? $this->guardar($tarima) : $tarima;
    }

    private function guardar(Tarima $tarima): Tarima {
        // Los registros históricos sin N/S no llevan etiqueta de pieza.
        if (NumeroSerie::whereHas('registro', fn ($q) => $q->where('tarima_id', $tarima->id))->exists()) {
            $this->actingAs($this->op())->postJson(route('tarimas.impresa', $tarima))->assertOk();
        }
        $this->actingAs($this->op())->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    private function ids(Tarima $tarima, array $series): array {
        return Partida::where('tarima_id', $tarima->id)->whereIn('numero_serie', $series)->pluck('id')->all();
    }

    /** @return list<list<mixed>> filas de datos del Excel */
    private function excel(TestResponse $respuesta): array {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($ruta, $respuesta->assertOk()->streamedContent());
        try {
            return array_slice(IOFactory::load($ruta)->getSheet(0)->toArray(null, false, false), 1);
        } finally {
            @unlink($ruta);
        }
    }

    // ---------- Al guardar ----------

    public function test_guardar_crea_una_partida_por_pieza_con_consecutivo_fijo(): void {
        $tarima = $this->tarimaCon(['NS-1', 'NS-2'], sueltos: ['CAJA-1']);

        $partidas = Partida::orderBy('consecutivo')->get();
        $this->assertSame([1, 2, 3], $partidas->pluck('consecutivo')->all());
        $this->assertSame(['ns', 'ns', 'sku'], $partidas->pluck('tipo_identificacion')->all());
        $this->assertSame(['NS-1', 'NS-2', '—'], $partidas->pluck('ns_mostrada')->all());
        $this->assertTrue($partidas->every(fn (Partida $p) => $p->tarima_id === $tarima->id && $p->mudanza_id === $this->mudanza->id));
        $this->assertSame(3, MovimientoPartida::where('tipo', MovimientoPartida::ALTA)->count());
    }

    public function test_el_consecutivo_empieza_en_1_en_cada_mudanza(): void {
        $this->tarimaCon(['NS-1', 'NS-2']);
        app(MudanzaService::class)->transicionar($this->mudanza, MudanzaService::INICIAR_CIERRE, $this->admin());
        app(MudanzaService::class)->transicionar($this->mudanza, MudanzaService::CERRAR, $this->admin());
        $otra = $this->crearMudanza();
        app(MudanzaService::class)->transicionar($otra, MudanzaService::ACTIVAR, $this->admin());

        $this->tarimaCon(['NS-3']);

        $this->assertSame(1, Partida::where('mudanza_id', $otra->id)->value('consecutivo'));
    }

    // ---------- Mover ----------

    public function test_mover_piezas_conserva_su_consecutivo_y_cambia_la_captura(): void {
        $origen = $this->tarimaCon(['NS-1', 'NS-2', 'NS-3']);
        $destino = $this->tarimaCon(['NS-9'], 'SKU-OTRO');
        $auditor = $this->auditor();

        $this->actingAs($auditor)->post(route('tarimas.partidas.mover', $origen), [
            'partidas' => $this->ids($origen, ['NS-2']),
            'destino' => $destino->uuid,
            'motivo' => 'Se paletizó en la tarima equivocada',
        ])->assertRedirect(route('tarimas.corregir', $origen))->assertSessionHasNoErrors();

        $movida = Partida::where('numero_serie', 'NS-2')->first();
        $this->assertSame(2, $movida->consecutivo, 'El consecutivo no cambia.');
        $this->assertSame($destino->id, $movida->tarima_id);

        // Capa de captura: la N/S ahora cuelga de un SKU padre en el destino.
        $this->assertSame($destino->id, NumeroSerie::where('numero_serie', 'NS-2')->first()->registro->tarima_id);
        $this->assertSame(['NS-1', 'NS-3'], array_column(app(TarimaService::class)->filas($origen->fresh())->all(), 'ns'));
        $this->assertSame(['NS-9', 'NS-2'], array_column(app(TarimaService::class)->filas($destino->fresh())->all(), 'ns'));

        // Cada pieza lleva su etiqueta: solo la movida (que trae el número del
        // embarque anterior) queda por reimprimir, y con ella su embarque destino.
        $this->assertFalse($origen->fresh()->requiereReimpresion());
        $this->assertTrue($destino->fresh()->requiereReimpresion());
        $this->assertSame(['NS-2'], NumeroSerie::whereNull('etiqueta_impresa_at')->pluck('numero_serie')->all());

        $mov = MovimientoPartida::where('tipo', MovimientoPartida::CAMBIO_TARIMA)->sole();
        $this->assertSame([$origen->id, $destino->id, $auditor->id], [$mov->tarima_origen_id, $mov->tarima_destino_id, $mov->user_id]);
        $this->assertSame('Se paletizó en la tarima equivocada', $mov->motivo);
        $this->assertSame([2], Bitacora::where('accion', 'partidas.movidas')->sole()->datos['consecutivos']);

        // Excel: la pieza aparece en su nueva tarima, con su número de siempre, en orden de consecutivo.
        $filas = $this->excel($this->get(route('export.todas')));
        $this->assertSame([1, 2, 3, 4], array_column($filas, 0));
        $this->assertSame([$origen->numero, $destino->numero, $origen->numero, $destino->numero], array_column($filas, 7));
        $this->assertSame([[2, 'NS-2'], [4, 'NS-9']], array_map(fn ($f) => [$f[0], $f[6]], $this->excel($this->get(route('export.todas', ['tarima' => $destino->codigo])))));
    }

    public function test_si_el_padre_se_queda_sin_piezas_sale_de_la_tarima(): void {
        $origen = $this->tarimaCon(['NS-1']);
        $destino = $this->tarimaCon(['NS-9'], 'SKU-OTRO');

        $this->actingAs($this->admin())->post(route('tarimas.partidas.mover', $origen), [
            'partidas' => $this->ids($origen, ['NS-1']), 'destino' => $destino->uuid, 'motivo' => 'Juntar tarimas',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Registro::where('tarima_id', $origen->id)->count());
        $this->assertSame(1, Registro::onlyTrashed()->where('tarima_id', $origen->id)->count());
    }

    public function test_mover_a_una_tarima_nueva_en_captura_y_luego_guardarla(): void {
        $origen = $this->tarimaCon(['NS-1', 'NS-2']);
        $nueva = $this->tarimaCon([], guardar: false);

        $this->actingAs($this->admin())->post(route('tarimas.partidas.mover', $origen), [
            'partidas' => $this->ids($origen, ['NS-2']), 'destino' => $nueva->uuid, 'motivo' => 'Dividir tarima por peso',
        ])->assertSessionHasNoErrors();

        // Aunque la nueva siga en captura, la pieza ya entregada no desaparece del Excel.
        $this->assertSame([1, 2], array_column($this->excel($this->get(route('export.todas'))), 0));
        $this->assertSame([2], array_column($this->excel($this->get(route('export.todas', ['tarima' => $nueva->codigo]))), 0));

        // No se puede vaciar: esas piezas ya son partidas entregadas.
        $this->actingAs($this->op())->delete(route('tarimas.vaciar', $nueva))->assertSessionHasErrors('tarima');

        // Al guardarla, solo las piezas nuevas reciben consecutivo; la movida conserva el suyo.
        $this->activarPadre($this->op(), $nueva, 'SKU-NUEVO');
        $this->escanearNs('NS-3');
        $this->post(route('escaneo.padre.cerrar'));
        $this->guardar($nueva);

        $this->assertSame([[2, 'NS-2'], [3, 'NS-3']], Partida::where('tarima_id', $nueva->id)->orderBy('consecutivo')->get()
            ->map(fn (Partida $p) => [$p->consecutivo, $p->numero_serie])->all());
        $this->assertSame(1, Partida::where('numero_serie', 'NS-2')->count());
    }

    public function test_un_sku_suelto_se_mueve_completo_y_no_se_junta_con_el_mismo_sku(): void {
        $origen = $this->tarimaCon([], sueltos: ['CAJA-1', 'CAJA-2']);
        $destino = $this->tarimaCon([], sueltos: ['CAJA-2']);
        $admin = $this->admin();
        $caja = fn (string $sku) => [Partida::where('tarima_id', $origen->id)->where('sku', $sku)->value('id')];

        $this->actingAs($admin)->post(route('tarimas.partidas.mover', $origen), [
            'partidas' => $caja('CAJA-2'), 'destino' => $destino->uuid, 'motivo' => 'Prueba de choque',
        ])->assertSessionHasErrors('destino');

        $this->actingAs($admin)->post(route('tarimas.partidas.mover', $origen), [
            'partidas' => $caja('CAJA-1'), 'destino' => $destino->uuid, 'motivo' => 'Va con la otra caja',
        ])->assertSessionHasNoErrors();

        $this->assertSame($destino->id, Registro::where('sku', 'CAJA-1')->value('tarima_id'));
    }

    // ---------- Retirar ----------

    public function test_retirar_saca_la_pieza_del_inventario_sin_borrarla_y_libera_la_ns(): void {
        $tarima = $this->tarimaCon(['NS-1', 'NS-2']);

        $this->actingAs($this->auditor())->post(route('tarimas.partidas.retirar', $tarima), [
            'partidas' => $this->ids($tarima, ['NS-1']), 'motivo' => 'Pieza de otro cliente',
        ])->assertSessionHasNoErrors();

        $retirada = Partida::where('numero_serie', 'NS-1')->first();
        $this->assertTrue($retirada->estaRetirada());
        $this->assertSame([2], array_column($this->excel($this->get(route('export.todas'))), 0));
        // Las etiquetas de las piezas que se quedan siguen siendo válidas.
        $this->assertFalse($tarima->fresh()->requiereReimpresion());

        // Si la pieza vuelve a aparecer, se escanea de nuevo y recibe otro consecutivo.
        $this->tarimaCon(['NS-1'], 'SKU-REGRESO');
        $this->assertSame([1, 3], Partida::where('numero_serie', 'NS-1')->orderBy('id')->pluck('consecutivo')->all());
    }

    public function test_reimprimir_la_pieza_movida_quita_el_aviso(): void {
        $origen = $this->tarimaCon(['NS-1', 'NS-2']);
        $destino = $this->tarimaCon(['NS-9'], 'SKU-OTRO');
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('tarimas.partidas.mover', $origen), [
            'partidas' => $this->ids($origen, ['NS-1']), 'destino' => $destino->uuid, 'motivo' => 'Iba en el otro',
        ]);
        $this->get(route('historial'))->assertSee('Reimprimir');
        // Por omisión se prepara solo la etiqueta que falta: la de la pieza movida.
        $this->get(route('etiquetas.show', $destino))->assertOk()->assertSee('Imprimir etiqueta')->assertSee('Sin imprimir (1)');

        $movida = NumeroSerie::where('numero_serie', 'NS-1')->value('id');
        $this->postJson(route('tarimas.impresa', ['tarima' => $destino, 'piezas' => $movida]))->assertOk()->assertJson(['pendientes' => 0]);

        $this->assertFalse($destino->fresh()->requiereReimpresion());
    }

    // ---------- Permisos y estados ----------

    public function test_solo_admin_y_auditor_corrigen_y_solo_tarimas_guardadas(): void {
        $guardada = $this->tarimaCon(['NS-1']);
        $enCaptura = $this->tarimaCon(['NS-2'], 'SKU-B', guardar: false);

        $this->actingAs($this->op())->get(route('tarimas.corregir', $guardada))->assertForbidden();
        $this->actingAs($this->op())->post(route('tarimas.partidas.retirar', $guardada), [
            'partidas' => $this->ids($guardada, ['NS-1']), 'motivo' => 'Quiero quitarla',
        ])->assertForbidden();
        $this->actingAs($this->auditor())->get(route('tarimas.corregir', $enCaptura))->assertForbidden();

        $this->actingAs($this->auditor())->get(route('tarimas.corregir', $guardada))
            ->assertOk()->assertSee('NS-1')->assertSee('Mover seleccionadas');
        $this->get(route('historial'))->assertSee(route('tarimas.corregir', $guardada));
    }

    public function test_motivo_y_piezas_son_obligatorios_y_deben_seguir_en_la_tarima(): void {
        $tarima = $this->tarimaCon(['NS-1']);
        $otra = $this->tarimaCon(['NS-2'], 'SKU-B');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('tarimas.partidas.retirar', $tarima), ['partidas' => $this->ids($tarima, ['NS-1'])])
            ->assertSessionHasErrors('motivo');
        $this->actingAs($admin)->post(route('tarimas.partidas.retirar', $tarima), ['motivo' => 'Sin piezas elegidas'])
            ->assertSessionHasErrors('partidas');
        // Una pieza de otra tarima no se puede corregir desde esta.
        $this->actingAs($admin)->post(route('tarimas.partidas.retirar', $tarima), [
            'partidas' => $this->ids($otra, ['NS-2']), 'motivo' => 'Pieza ajena',
        ])->assertSessionHasErrors('partidas');

        $this->assertSame(0, Partida::where('estado', Partida::RETIRADA)->count());
    }

    public function test_con_la_mudanza_suspendida_no_se_corrige(): void {
        $tarima = $this->tarimaCon(['NS-1']);
        app(MudanzaService::class)->transicionar($this->mudanza, MudanzaService::SUSPENDER, $this->admin(), 'Pausa del cliente');

        $this->actingAs($this->admin())->post(route('tarimas.partidas.retirar', $tarima), [
            'partidas' => $this->ids($tarima, ['NS-1']), 'motivo' => 'Durante la pausa',
        ])->assertSessionHasErrors('partidas');
        $this->assertSame(Mudanza::SUSPENDIDA, $this->mudanza->fresh()->estado);
    }

    public function test_el_historial_de_movimientos_no_se_modifica(): void {
        $this->tarimaCon(['NS-1']);

        $this->expectException(\LogicException::class);
        MovimientoPartida::first()->delete();
    }
}
