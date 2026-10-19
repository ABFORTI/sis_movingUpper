<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Incidencia;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Tarima;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * Caso E-009: un embarque marcado como guardado por fuera de TarimaService::guardar
 * (editando la base de datos) tiene sus N/S pero ninguna partida. Corregir y el
 * Excel lo veían vacío mientras Ver piezas y la validación de N/S sí veían sus piezas.
 */
class GuardadoSinPartidasTest extends TestCase {
    use PreparaWms;

    private ?User $capturista = null;

    private function op(): User {
        return $this->capturista ??= $this->operador();
    }

    /** Embarque guardado normalmente (E-001) y otro "guardado" a mano, como E-009 (E-002). */
    private function preparar(bool $etiquetasConfirmadas): Tarima {
        $normal = $this->tarima($this->op());
        $this->capturarPieza($this->op(), $normal, 'SKU-A', 'NS-A1');
        $this->postJson(route('tarimas.impresa', $normal))->assertOk();
        $this->postJson(route('tarimas.guardar', $normal))->assertOk();

        $roto = $this->tarima($this->op());
        $this->activarPadre($this->op(), $roto, 'SKU-P');
        foreach (['NS-1', 'NS-2', 'NS-3'] as $ns) {
            $this->escanearNs($ns)->assertSessionHasNoErrors();
        }
        $this->escanearNs('NO-INV', agregarAlInventario: false)->assertSessionHasNoErrors();
        $this->post(route('escaneo.padre.cerrar'));
        if ($etiquetasConfirmadas) {
            $this->postJson(route('tarimas.impresa', $roto))->assertOk();
        }
        // Lo que se hizo en la base de datos: guardado sin pasar por guardar().
        DB::table('tarimas')->where('id', $roto->id)->update(['guardada_at' => now(), 'guardada_by' => $this->op()->id, 'impresa_at' => now()]);

        return $roto->fresh();
    }

    private function excel(): array {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($ruta, $this->get(route('export.todas'))->assertOk()->streamedContent());
        try {
            return array_slice(IOFactory::load($ruta)->getSheet(0)->toArray(null, false, false), 1);
        } finally {
            @unlink($ruta);
        }
    }

    public function test_se_detecta_el_embarque_guardado_sin_partidas(): void {
        $roto = $this->preparar(etiquetasConfirmadas: true);
        $auditor = $this->auditor();

        $this->actingAs($auditor)->get(route('tarimas.corregir', $roto))->assertOk()
            ->assertSee('4 piezas registradas no tienen consecutivo')->assertSee('Completar piezas')
            ->assertDontSee('Se retiraron o eliminaron todas');
        $this->get(route('historial'))->assertSee('4 sin consecutivo');
    }

    public function test_completar_numera_sus_piezas_sin_tocar_ns_ni_etiquetas_y_aparece_en_el_excel(): void {
        $roto = $this->preparar(etiquetasConfirmadas: true);
        $impresas = NumeroSerie::orderBy('id')->pluck('etiqueta_impresa_at', 'id');
        $this->assertSame(['NS-A1'], array_column($this->actingAs($this->auditor())->excel(), 6));

        $this->post(route('tarimas.partidas.completar', $roto))->assertRedirect(route('tarimas.corregir', $roto))
            ->assertSessionHas('ok', fn ($m) => str_starts_with($m, 'Se completaron 4 piezas del Embarque E-002'));

        $partidas = Partida::where('tarima_id', $roto->id)->orderBy('consecutivo')->get();
        $this->assertSame([2, 3, 4, 5], $partidas->pluck('consecutivo')->all(), 'Siguen a los del E-001; ninguno se reutiliza.');
        $this->assertSame(['NS-1', 'NS-2', 'NS-3', 'NO-INV'], $partidas->pluck('numero_serie')->all());
        $this->assertSame(2, $roto->fresh()->consecutivo_inicial);
        $this->assertEquals($impresas, NumeroSerie::orderBy('id')->pluck('etiqueta_impresa_at', 'id'), 'N/S y etiquetas intactas.');
        $this->assertFalse($roto->fresh()->requiereReimpresion());
        // La incidencia de "no encontrado" queda ligada a su pieza, como en un guardado normal.
        $this->assertTrue($partidas->last()->incidencias()->where('tipo', Incidencia::NO_EN_INVENTARIO)->exists());
        $this->assertSame(1, Bitacora::where('accion', 'tarima.partidas_completadas')->count());

        $this->get(route('tarimas.corregir', $roto))->assertSee('NS-2')->assertDontSee('no tienen consecutivo');
        $filas = $this->excel();
        $this->assertSame([1, 2, 3, 4, 5], array_column($filas, 0));
        $this->assertSame([1, 2, 2, 2, 2], array_column($filas, 7));

        // Ya se puede gestionar: editar una N/S existente sin falsos conflictos, y sin duplicar.
        $this->skuEnInventario('SKU-P', ['NS-4']);
        $this->put(route('tarimas.partidas.editar', $roto), ['partida' => $partidas[1]->id, 'sku' => 'SKU-P', 'numero_serie' => 'NS-4', 'motivo' => 'Corrección de prueba'])
            ->assertSessionHasNoErrors();
        $this->post(route('tarimas.partidas.agregar', $roto), ['modelo' => $partidas[0]->registro_id, 'numero_serie' => 'NS-1', 'motivo' => 'Duplicado'])
            ->assertSessionHasErrors('numero_serie');

        // Repetirlo no crea nada más.
        $this->post(route('tarimas.partidas.completar', $roto))->assertSessionHas('ok', 'El Embarque E-002 ya tenía todas sus piezas con consecutivo.');
        $this->assertSame(5, Partida::count());
    }

    public function test_sin_etiquetas_confirmadas_queda_con_reimpresion_pendiente(): void {
        $roto = $this->preparar(etiquetasConfirmadas: false);

        $this->actingAs($this->auditor())->post(route('tarimas.partidas.completar', $roto))
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Confirma la impresión de sus etiquetas'));

        $this->assertSame(4, Partida::where('tarima_id', $roto->id)->count());
        $this->assertTrue($roto->fresh()->requiereReimpresion(), 'No se carga al tráiler hasta confirmar las etiquetas.');
        $this->postJson(route('tarimas.impresa', $roto))->assertOk()->assertJson(['pendientes' => 0]);
        $this->assertFalse($roto->fresh()->requiereReimpresion());
    }

    public function test_el_comando_detecta_y_repara(): void {
        $roto = $this->preparar(etiquetasConfirmadas: true);
        $admin = $this->admin();

        $this->artisan('tarimas:completar-partidas')->expectsOutputToContain('MUD-0001 · E-002')->assertSuccessful();
        $this->assertSame(0, Partida::where('tarima_id', $roto->id)->count(), 'Sin --reparar solo informa.');

        $this->artisan('tarimas:completar-partidas', ['--reparar' => true, '--usuario' => $admin->email])->assertSuccessful();
        $this->assertSame(4, Partida::where('tarima_id', $roto->id)->count());
        $this->artisan('tarimas:completar-partidas')->expectsOutput('Todos los embarques guardados tienen sus piezas con consecutivo.');
    }

    public function test_solo_supervision_completa_y_no_aplica_a_embarques_en_captura(): void {
        $roto = $this->preparar(etiquetasConfirmadas: true);
        $this->actingAs($this->op())->post(route('tarimas.partidas.completar', $roto))->assertForbidden();

        $captura = $this->tarima($this->op());
        $this->actingAs($this->auditor())->post(route('tarimas.partidas.completar', $captura))->assertForbidden();
        $this->assertSame(1, Partida::count());
    }
}
