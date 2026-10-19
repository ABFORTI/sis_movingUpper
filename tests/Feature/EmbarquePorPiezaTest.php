<?php

namespace Tests\Feature;

use App\Models\Embarque;
use App\Models\EmbarquePartida;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use App\Services\TarimaService;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * Flujo por pieza (cambiosNuevos.md §3): el embarque (modelo Tarima) agrupa las
 * piezas que van en la caja de un tráiler; cada pieza se identifica por su N/S
 * y lleva su propia etiqueta. Se puede imprimir pieza por pieza mientras se
 * sigue escaneando, y el embarque se guarda cuando todas tienen etiqueta.
 */
class EmbarquePorPiezaTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    private User $op;

    protected function setUp(): void {
        $this->prepararWms();
        $this->op = $this->operador();
    }

    private function idDe(string $ns): int {
        return NumeroSerie::where('numero_serie', $ns)->value('id');
    }

    public function test_flujo_completo_escanear_etiquetar_cada_pieza_y_seguir_escaneando(): void {
        // 1. Crear el embarque.
        $this->actingAs($this->op)->post(route('tarimas.store'))->assertSessionHas('ok', 'Embarque E-001 creado (58 → 3).');
        $embarque = Tarima::sole();

        // 2. Registrar la primera pieza por su N/S.
        $this->activarPadre($this->op, $embarque, 'REF-100');
        $this->escanearNsJson('SN-1')->assertOk()->assertJson(['estado' => 'registrada', 'piezas_tarima' => 1, 'etiquetas_pendientes' => 1]);

        // 3 y 4. Etiqueta individual de esa pieza: se imprime y se confirma.
        $this->get(route('etiquetas.show', $embarque))->assertOk()->assertSee('Imprimir etiqueta')->assertSee('Sin imprimir (1)');
        $zpl = $this->get(route('etiquetas.zpl', ['tarima' => $embarque, 'piezas' => $this->idDe('SN-1')]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($zpl, '^XA'));
        $this->assertStringContainsString('^FDSN-1^FS', $zpl);
        $this->postJson(route('tarimas.impresa', ['tarima' => $embarque, 'piezas' => $this->idDe('SN-1')]))->assertOk()->assertJson(['pendientes' => 0]);

        // 5. Se sigue escaneando en el mismo embarque: solo la pieza nueva queda sin etiqueta.
        $this->escanearNsJson('SN-2')->assertOk()->assertJson(['piezas_tarima' => 2, 'etiquetas_pendientes' => 1]);
        $this->postJson(route('tarimas.guardar', $embarque))->assertStatus(422)
            ->assertJsonPath('errors.tarima.0', 'Primero imprime las etiquetas de las piezas del Embarque E-001.');
        $this->get(route('wms'))->assertSee('1 sin imprimir');

        // Por omisión se prepara solo la que falta (no se duplican etiquetas ya pegadas).
        $this->get(route('etiquetas.show', $embarque))->assertOk()->assertSee('Imprimir etiqueta')
            ->assertSee(e(route('tarimas.impresa', ['tarima' => $embarque, 'piezas' => $this->idDe('SN-2')])), false);
        $this->postJson(route('tarimas.impresa', ['tarima' => $embarque, 'piezas' => $this->idDe('SN-2')]))->assertOk()->assertJson(['pendientes' => 0]);
        $this->get(route('wms'))->assertDontSee('1 sin imprimir')->assertDontSee('title="Primero imprime las etiquetas', false);

        // 6. Al guardar, cada pieza queda ligada al embarque con su N/S.
        $this->postJson(route('tarimas.guardar', $embarque))->assertOk()->assertJson(['guardada' => true]);
        $this->assertSame(
            [['SN-1', $embarque->id], ['SN-2', $embarque->id]],
            Partida::orderBy('consecutivo')->get()->map(fn (Partida $p) => [$p->numero_serie, $p->tarima_id])->all(),
        );

        // 7. Consultar las piezas del embarque y cuáles ya se incorporaron al tráiler.
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'ABC-123'])->assertSessionHasNoErrors();
        $trailer = Embarque::sole();
        $this->assertSame('TRL-0001', $trailer->folio);
        $this->actingAs($this->op)->post(route('embarques.cargar', $trailer), ['codigo' => 'SN-1'])
            ->assertSessionHas('ok', 'N/S SN-1: 1 pieza cargada.');

        $consulta = $this->get(app(TarimaService::class)->urlDetalle($embarque))->assertOk()->getContent();
        $this->assertStringContainsString('Cargada (sin salir) · tráiler TRL-0001', $consulta);
        $this->assertStringContainsString('Sin cargar', $consulta);
        $this->get(route('historial'))->assertSee('Embarque E-001')->assertSee('Ver piezas');
    }

    public function test_varias_piezas_imprimen_todas_sus_etiquetas_de_una_vez(): void {
        $embarque = $this->tarima($this->op);
        $this->activarPadre($this->op, $embarque, 'REF-100');
        foreach (['A-1', 'A-2', 'A-3'] as $ns) {
            $this->escanearNs($ns)->assertSessionHasNoErrors();
        }

        $this->get(route('etiquetas.show', $embarque))->assertOk()->assertSee('Imprimir 3 etiquetas');
        $zpl = $this->get(route('etiquetas.zpl', $embarque))->getContent();
        $this->assertSame(3, substr_count($zpl, '^XA'));
        $this->postJson(route('tarimas.impresa', $embarque))->assertOk()->assertJson(['pendientes' => 0]);
        $this->postJson(route('tarimas.guardar', $embarque))->assertOk();
        $this->assertSame(3, Partida::where('tarima_id', $embarque->id)->count());
    }

    public function test_no_se_duplican_piezas_ni_se_asignan_a_otro_embarque(): void {
        $primero = $this->tarima($this->op);
        $this->activarPadre($this->op, $primero, 'REF-100');
        $this->escanearNsJson('DUP-1')->assertOk()->assertJson(['estado' => 'registrada']);

        // La misma N/S otra vez en el mismo embarque: se informa como repetida y no se duplica.
        $this->escanearNsJson('DUP-1')->assertOk()->assertJson(['estado' => 'repetida', 'piezas_tarima' => 1]);
        $this->assertSame(1, NumeroSerie::where('numero_serie', 'DUP-1')->count());

        // En otro embarque se rechaza y dice dónde está.
        $this->post(route('escaneo.padre.cerrar'));
        $segundo = $this->tarima($this->op);
        $this->activarPadre($this->op, $segundo, 'REF-100');
        $this->escanearNsJson('DUP-1')->assertStatus(422)
            ->assertJsonPath('errors.numero_serie.0', 'La N/S DUP-1 ya está registrada (SKU REF-100 · Embarque E-001).');
        $this->assertSame($primero->id, NumeroSerie::where('numero_serie', 'DUP-1')->sole()->registro->tarima_id);

        // Y en el tráiler, una pieza solo va en un viaje.
        $this->postJson(route('tarimas.impresa', $primero))->assertOk();
        $this->postJson(route('tarimas.guardar', $primero))->assertOk();
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'UNO-111']);
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'DOS-222']);
        [$t1, $t2] = Embarque::orderBy('id')->get()->all();
        $this->actingAs($this->op)->post(route('embarques.cargar', $t1), ['codigo' => 'DUP-1'])->assertSessionHasNoErrors();
        $this->actingAs($this->op)->post(route('embarques.cargar', $t2), ['codigo' => 'DUP-1'])->assertSessionHasErrors('codigo');
        $this->assertStringContainsString('ya va en el tráiler TRL-0001', session('errors')->first('codigo'));
        $this->assertSame(1, EmbarquePartida::count());
    }

    public function test_un_sku_activado_sin_piezas_no_se_vuelve_pieza(): void {
        $embarque = $this->tarima($this->op);
        $this->activarPadre($this->op, $embarque, 'REF-100');
        $this->escanearNs('P-1');
        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($this->op, $embarque, 'VACIO');
        $this->post(route('escaneo.padre.cerrar'));

        $this->postJson(route('tarimas.impresa', $embarque))->assertOk();
        $this->postJson(route('tarimas.guardar', $embarque))->assertOk();

        $this->assertSame(['P-1'], Partida::where('tarima_id', $embarque->id)->pluck('numero_serie')->all());
        $this->assertSoftDeleted('registros', ['sku' => 'VACIO']);
    }

    public function test_un_embarque_sin_piezas_no_se_guarda(): void {
        $embarque = $this->tarima($this->op);
        $this->activarPadre($this->op, $embarque, 'REF-100');

        $this->postJson(route('tarimas.impresa', $embarque))->assertStatus(422);
        $this->postJson(route('tarimas.guardar', $embarque))->assertStatus(422)
            ->assertJsonPath('errors.tarima.0', 'El Embarque E-001 no tiene piezas.');
    }

    public function test_registros_historicos_sin_ns_se_guardan_sin_etiqueta_de_pieza(): void {
        $embarque = $this->tarima($this->op);
        $this->registroHistoricoSinNs($this->op, $embarque, 'CAJA-1', 'Caja');

        $this->get(route('etiquetas.zpl', $embarque))->assertStatus(422);
        $this->postJson(route('tarimas.guardar', $embarque))->assertOk();
        $this->assertSame(1, Partida::where('tarima_id', $embarque->id)->count());
    }

    public function test_el_anden_acepta_el_numero_de_embarque_y_las_etiquetas_viejas(): void {
        $embarque = $this->tarima($this->op);
        $this->activarPadre($this->op, $embarque, 'REF-100');
        $this->escanearNs('X-1');
        $this->escanearNs('X-2');
        $this->postJson(route('tarimas.impresa', $embarque))->assertOk();
        $this->postJson(route('tarimas.guardar', $embarque))->assertOk();
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'ABC-123']);
        $trailer = Embarque::sole();

        $this->actingAs($this->op)->post(route('embarques.cargar', $trailer), ['codigo' => 'MUD-0001 · E-001'])
            ->assertSessionHas('ok', 'E-001: 2 piezas cargadas.');
        // "T-001" de las etiquetas de tarima ya pegadas sigue identificando el mismo embarque.
        $this->actingAs($this->op)->post(route('embarques.cargar', $trailer), ['codigo' => 'T-001'])
            ->assertSessionHas('warn', 'E-001: ya estaba en este tráiler. No se duplicó.');
    }

    public function test_el_historial_filtra_por_numero_de_embarque(): void {
        foreach (['F-1', 'F-2'] as $i => $ns) {
            $t = $this->tarima($this->op);
            $this->post(route('escaneo.padre.cerrar'));
            $this->activarPadre($this->op, $t, 'SKU-'.$i);
            $this->escanearNs($ns);
            $this->postJson(route('tarimas.impresa', $t))->assertOk();
            $this->postJson(route('tarimas.guardar', $t))->assertOk();
        }

        foreach (['E-002', 'Embarque 2', 'MUD-0001 · E-002', '2', 'T-002'] as $filtro) {
            $this->get(route('historial', ['tarima' => $filtro]))->assertOk()->assertSee('SKU-1')->assertDontSee('SKU-0');
        }
    }

    public function test_al_vaciar_el_embarque_no_quedan_piezas_por_etiquetar(): void {
        $embarque = $this->tarima($this->op);
        $this->activarPadre($this->op, $embarque, 'REF-100');
        $this->escanearNs('V-1');

        $this->delete(route('tarimas.vaciar', $embarque))->assertSessionHas('warn', 'Contenido del Embarque E-001 enviado a la papelera.');
        $this->get(route('etiquetas.zpl', $embarque))->assertStatus(422);
        $this->assertSame(0, Registro::where('tarima_id', $embarque->id)->count());
    }
}
