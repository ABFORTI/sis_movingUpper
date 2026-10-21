<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Embarque;
use App\Models\Incidencia;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Tarima;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** tarimas:eliminar-anulada y trailers:renumerar: limpieza de datos con simulación previa. */
class ComandosLimpiezaTest extends TestCase {
    use PreparaWms;

    private function guardada(array $series): Tarima {
        $op = $this->operador();
        $tarima = $this->tarima($op);
        $this->activarPadre($op, $tarima, 'SKU-P');
        foreach ($series as $ns) {
            $this->escanearNs($ns, agregarAlInventario: false)->assertSessionHasNoErrors();
        }
        $this->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    private function trailer(): Embarque {
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'ABC-123'])->assertSessionHasNoErrors();

        return Embarque::latest('id')->first();
    }

    public function test_elimina_un_embarque_anulado_solo_tras_simular_y_confirmar(): void {
        $anulado = $this->guardada(['NS-1', 'NS-2']);
        $otro = $this->guardada(['NS-3']);
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('tarimas.anular', $anulado), ['motivo' => 'Duplicado por error'])->assertSessionHasNoErrors();
        $this->assertSame(3, Incidencia::count(), 'Cada N/S sin inventario abrió su incidencia.');

        $this->artisan('tarimas:eliminar-anulada', ['embarque' => 'E-002', '--simular' => true])->expectsOutputToContain('no está anulado')->assertFailed();
        $this->artisan('tarimas:eliminar-anulada', ['embarque' => 'E-001', '--simular' => true])->expectsOutputToContain('Simulación')->assertSuccessful();
        $this->assertTrue(Tarima::whereKey($anulado->id)->exists());

        $this->artisan('tarimas:eliminar-anulada', ['embarque' => 'E-001', '--usuario' => $admin->email])
            ->expectsConfirmation('¿Eliminar MUD-0001 · E-001 por completo? No se puede deshacer.', 'yes')->assertSuccessful();

        $this->assertFalse(Tarima::whereKey($anulado->id)->exists());
        $this->assertSame(['NS-3'], NumeroSerie::withTrashed()->pluck('numero_serie')->all());
        $this->assertSame(1, Partida::count());
        $this->assertSame(1, Incidencia::count());
        $this->assertTrue($otro->fresh()->partidas()->exists());
        $this->assertSame(1, Bitacora::where('accion', 'tarima.eliminada')->count());
    }

    public function test_no_elimina_un_anulado_con_piezas_en_un_trailer(): void {
        $tarima = $this->guardada(['NS-1']);
        $trailer = $this->trailer();
        $this->post(route('embarques.cargar', $trailer), ['codigo' => $tarima->identificador])->assertSessionHasNoErrors();
        $tarima->forceFill(['anulada_at' => now()])->save();

        $this->artisan('tarimas:eliminar-anulada', ['embarque' => 'E-001', '--simular' => true])->expectsOutputToContain('cargadas en tráiler')->assertFailed();
    }

    public function test_renumera_los_trailers_tras_eliminar_uno_vacio(): void {
        $tarima = $this->guardada(['NS-1']);
        [$vacio, $t2, $t3] = [$this->trailer(), $this->trailer(), $this->trailer()];
        $this->post(route('embarques.cargar', $t2), ['codigo' => $tarima->identificador])->assertSessionHasNoErrors();
        $admin = $this->admin();

        $this->artisan('trailers:renumerar', ['--eliminar' => ['TRL-0002']])->expectsOutputToContain('tiene 1 piezas cargadas')->assertFailed();
        $this->artisan('trailers:renumerar', ['--eliminar' => ['TRL-0001'], '--simular' => true])->expectsOutputToContain('El siguiente tráiler será TRL-0003.')->assertSuccessful();
        $this->assertSame(3, Embarque::count());

        $this->artisan('trailers:renumerar', ['--eliminar' => ['trl-0001'], '--usuario' => $admin->email])
            ->expectsConfirmation('¿Aplicar estos cambios? No se puede deshacer.', 'yes')->assertSuccessful();

        $this->assertFalse(Embarque::whereKey($vacio->id)->exists());
        $this->assertSame(['TRL-0001', 'TRL-0002'], [$t2->fresh()->folio, $t3->fresh()->folio]);
        $this->assertSame(1, $t2->fresh()->cargas()->count(), 'Las piezas siguen en su tráiler.');
        $this->assertSame(['TRL-0002' => 'TRL-0001', 'TRL-0003' => 'TRL-0002'], Bitacora::where('accion', 'embarque.renumerado')->sole()->datos['cambios']);
        $this->assertSame('TRL-0003', $this->trailer()->folio, 'El siguiente sigue la secuencia.');
    }
}
