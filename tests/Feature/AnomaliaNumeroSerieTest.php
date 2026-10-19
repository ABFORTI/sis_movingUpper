<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Incidencia;
use App\Models\NumeroSerie;
use App\Models\Tarima;
use App\Models\User;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** Marcar N/S dañadas o con anomalía antes de guardar la tarima. */
class AnomaliaNumeroSerieTest extends TestCase {
    use PreparaWms {
        setUp as prepararWms;
    }

    private User $op;

    private Tarima $tarima;

    protected function setUp(): void {
        $this->prepararWms();
        $this->op = $this->operador();
        $this->tarima = $this->tarima($this->op);
        $this->activarPadre($this->op, $this->tarima);
        foreach (['N-1', 'N-2', 'N-3'] as $ns) {
            $this->escanearNs($ns)->assertSessionHasNoErrors();
        }
    }

    private function ns(string $numero): NumeroSerie {
        return NumeroSerie::where('numero_serie', $numero)->sole();
    }

    private function marcar(array $datos, ?User $quien = null) {
        return $this->actingAs($quien ?? $this->op)->post(route('tarimas.anomalias', $this->tarima), $datos + ['accion' => 'marcar']);
    }

    private function guardar(): void {
        $this->actingAs($this->op)->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $this->tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $this->tarima))->assertOk();
    }

    public function test_guardar_sin_anomalias_no_crea_incidencias(): void {
        $this->guardar();

        $this->assertSame(0, Incidencia::count());
    }

    public function test_marcar_sin_descripcion_y_al_guardar_queda_la_incidencia(): void {
        $this->marcar(['ns' => [$this->ns('N-2')->id]])->assertSessionHasNoErrors()
            ->assertSessionHas('ok', "Daño marcado en N/S N-2. Se registrará como incidencia de producto dañado al guardar el {$this->tarima->nombre}.");
        $this->assertNotNull($this->ns('N-2')->anomalia_at);
        $this->assertSame($this->op->id, $this->ns('N-2')->anomalia_by);
        $this->assertNull($this->ns('N-2')->anomalia_nota);
        $this->assertSame(0, Incidencia::count(), 'Antes de guardar es solo una marca.');

        // La marca no invalida la etiqueta (no cambia el contenido de la tarima).
        $this->actingAs($this->op)->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $this->tarima))->assertOk();
        $this->actingAs($this->op)->get(route('wms'))->assertOk()->assertSee('✕ 1 dañada')->assertSee('data-anomalias="N-2"', false);
        $this->postJson(route('tarimas.guardar', $this->tarima))->assertOk()
            ->assertJsonPath('mensaje', 'Información Guardada. Dentro del historial puedes visualizarla. Se registró 1 incidencia de producto dañado');

        $incidencia = Incidencia::sole();
        $this->assertSame('danio', $incidencia->tipo);
        $this->assertSame("Producto dañado: N/S N-2 ({$this->tarima->fresh()->codigo})", $incidencia->titulo);
        $this->assertSame(['N-2', '2364F', $this->tarima->id], [$incidencia->numero_serie, $incidencia->sku, $incidencia->tarima_id]);
        $this->assertStringContainsString('sin descripción', $incidencia->descripcion);
        $this->assertSame($this->op->id, $incidencia->creada_by);
        $this->assertFalse($incidencia->bloquea_traslado);
        $this->assertSame(['N-2'], $incidencia->partidas->pluck('numero_serie')->all());
        $this->assertSame($this->tarima->id, $incidencia->partidas->sole()->tarima_id);
    }

    public function test_varias_series_con_descripcion_detallada(): void {
        $this->marcar([
            'ns' => [$this->ns('N-1')->id, $this->ns('N-3')->id],
            'nota' => 'Carcasa golpeada en la esquina; falta la tapa trasera.',
        ])->assertSessionHasNoErrors();
        $this->guardar();

        $incidencias = Incidencia::with('partidas')->orderBy('id')->get();
        $this->assertSame(['N-1', 'N-3'], $incidencias->map(fn ($i) => $i->partidas->sole()->numero_serie)->all());
        foreach ($incidencias as $i) {
            $this->assertStringStartsWith('Carcasa golpeada en la esquina; falta la tapa trasera.', $i->descripcion);
        }
    }

    public function test_marcar_escaneando_la_ns(): void {
        $this->marcar(['numero_serie' => 'N-3', 'nota' => 'Empaque mojado'])->assertSessionHasNoErrors();

        $this->assertSame('Empaque mojado', $this->ns('N-3')->anomalia_nota);
        $this->assertNull($this->ns('N-1')->anomalia_at);
    }

    public function test_una_ns_de_otra_tarima_no_se_marca(): void {
        $otra = $this->tarima($this->op);
        $this->actingAs($this->op)->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($this->op, $otra, 'OTRO-SKU');
        $this->escanearNs('Z-9')->assertSessionHasNoErrors();

        $this->marcar(['numero_serie' => 'Z-9'])->assertSessionHasErrors('anomalia');
        $this->assertStringContainsString("no es del {$this->tarima->nombre}: está en el {$otra->nombre}", session('errors')->first('anomalia'));
        $this->marcar(['ns' => [$this->ns('Z-9')->id]])->assertSessionHasErrors('anomalia');
        $this->marcar(['numero_serie' => 'NO-EXISTE'])->assertSessionHasErrors('anomalia');
        $this->marcar([])->assertSessionHasErrors('anomalia');

        $this->assertSame(0, NumeroSerie::whereNotNull('anomalia_at')->count());
    }

    public function test_marcar_de_nuevo_no_duplica_y_conserva_la_descripcion(): void {
        $id = $this->ns('N-1')->id;
        $this->marcar(['ns' => [$id], 'nota' => 'Golpe']);
        $primera = $this->ns('N-1')->anomalia_at;
        $this->travel(5)->minutes();
        $this->marcar(['ns' => [$id], 'nota' => 'También raspado']);
        $this->marcar(['ns' => [$id]]);
        $this->marcar(['ns' => [$id], 'nota' => 'Golpe']);

        $ns = $this->ns('N-1');
        $this->assertSame("Golpe\nTambién raspado", $ns->anomalia_nota);
        $this->assertEquals($primera, $ns->anomalia_at);

        $this->guardar();
        $this->assertSame(1, Incidencia::count());
    }

    public function test_reescanear_una_ns_marcada_no_toca_la_marca(): void {
        $this->marcar(['ns' => [$this->ns('N-2')->id], 'nota' => 'Golpe']);

        // En el mismo SKU es un reescaneo: no duplica ni toca la marca.
        $this->actingAs($this->op)->escanearNs('N-2')->assertSessionHasNoErrors()->assertSessionHas('warn');
        // Desde otro SKU es un error que avisa que está marcada.
        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($this->op, $this->tarima, 'OTRO-SKU');
        $this->escanearNs('N-2')->assertSessionHasErrors('numero_serie');
        $this->assertStringContainsString('marcada con anomalía', session('errors')->first('numero_serie'));
        // Otra N/S se sigue escaneando normal.
        $this->actingAs($this->op)->escanearNs('N-4')->assertSessionHasNoErrors();

        $this->assertSame('Golpe', $this->ns('N-2')->anomalia_nota);
        $this->assertSame(1, NumeroSerie::where('numero_serie', 'N-2')->count());
    }

    public function test_quitar_la_marca_antes_de_guardar(): void {
        $id = $this->ns('N-1')->id;
        $this->marcar(['ns' => [$id], 'nota' => 'Me equivoqué']);
        $this->actingAs($this->op)->post(route('tarimas.anomalias', $this->tarima), ['accion' => 'quitar', 'ns' => [$id]])->assertSessionHasNoErrors();

        $this->assertNull($this->ns('N-1')->anomalia_at);
        $this->assertNull($this->ns('N-1')->anomalia_nota);
        $this->assertSame(['anomalia.marcada', 'anomalia.quitada'], Bitacora::where('accion', 'like', 'anomalia.%')->orderBy('id')->pluck('accion')->all());

        $this->guardar();
        $this->assertSame(0, Incidencia::count());
    }

    public function test_la_incidencia_persiste_y_la_tarima_guardada_ya_no_admite_marcas(): void {
        $this->marcar(['ns' => [$this->ns('N-1')->id], 'nota' => 'Golpe']);
        $this->guardar();

        $this->marcar(['ns' => [$this->ns('N-2')->id]])->assertForbidden();
        $this->actingAs($this->admin())->post(route('tarimas.anomalias', $this->tarima), ['accion' => 'quitar', 'ns' => [$this->ns('N-1')->id]])->assertForbidden();

        // La incidencia sigue igual aunque la tarima avance en el traslado.
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'AAA-111']);
        $this->actingAs($this->op)->post(route('embarques.cargar', \App\Models\Embarque::sole()), ['codigo' => 'E-001'])->assertSessionHasNoErrors();
        $this->assertSame(1, Incidencia::count());
        $this->assertTrue(Incidencia::sole()->estaVigente());
        $this->assertSame(['N-1'], Incidencia::sole()->partidas->pluck('numero_serie')->all());
    }

    public function test_otro_operador_no_marca_en_tarima_ajena(): void {
        $this->marcar(['ns' => [$this->ns('N-1')->id]], $this->operador())->assertForbidden();

        $this->assertNull($this->ns('N-1')->anomalia_at);
    }

    public function test_reabrir_el_sku_permite_marcar_las_nuevas_ns(): void {
        $this->actingAs($this->op)->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($this->op, $this->tarima);
        $this->escanearNs('N-5')
            ->assertSessionHasNoErrors();
        $this->escanearNsJson('N-6')->assertOk()->assertJsonStructure(['numero_serie_id']);

        $this->marcar(['numero_serie' => 'N-6'])->assertSessionHasNoErrors();
        $this->assertNotNull($this->ns('N-6')->anomalia_at);
    }
}
