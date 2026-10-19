<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\NumeroSerie;
use App\Models\Registro;
use App\Models\Tarima;
use App\Services\BitacoraService;
use LogicException;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class BorradoTarimaTest extends TestCase {
    use PreparaWms;

    private int $piezas = 0;

    private function guardada(): Tarima {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        // Cada embarque con su propia pieza: una N/S no se registra dos veces.
        $this->escanearNs('SN-'.++$this->piezas)->assertSessionHasNoErrors();
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        return $tarima->fresh();
    }

    public function test_operador_vacia_con_borrado_logico_y_libera_las_ns(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');

        $this->delete(route('tarimas.vaciar', $tarima))->assertRedirect(route('wms'));

        $this->assertSame(0, Registro::count());
        $this->assertSame(1, Registro::onlyTrashed()->count());
        $this->assertSame($operador->id, Registro::onlyTrashed()->first()->deleted_by);
        $this->assertSame(1, NumeroSerie::onlyTrashed()->count());

        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1')->assertSessionHasNoErrors();
    }

    public function test_admin_tambien_vacia_con_borrado_logico(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');

        $admin = $this->admin();
        $this->actingAs($admin)->delete(route('tarimas.vaciar', $tarima))->assertRedirect(route('wms'));

        $this->assertSame(1, Registro::onlyTrashed()->count());
        $this->assertSame(1, NumeroSerie::onlyTrashed()->count());
        $this->assertSame($admin->id, Registro::onlyTrashed()->first()->deleted_by);

        $entrada = Bitacora::where('accion', BitacoraService::TARIMA_VACIADA)->sole();
        $this->assertSame($admin->id, $entrada->user_id);
        $this->assertSame(['tarima' => $tarima->id, 'registros' => 1, 'numeros_serie' => 1], $entrada->datos);
    }

    public function test_ya_no_existe_el_borrado_fisico_de_tarimas(): void {
        $tarima = $this->tarima();

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('tarimas.destroy'));
        $this->actingAs($this->admin())->delete('/tarimas/'.$tarima->uuid)->assertNotFound();
        $this->assertModelExists($tarima);
    }

    public function test_admin_anula_una_tarima_guardada_con_motivo_sin_borrar_nada(): void {
        $tarima = $this->guardada();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('tarimas.anular', $tarima), ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->assertFalse($tarima->fresh()->estaAnulada());

        $this->actingAs($admin)->from(route('historial'))
            ->post(route('tarimas.anular', $tarima), ['motivo' => 'Etiqueta duplicada por error'])
            ->assertRedirect(route('historial'));

        $tarima->refresh();
        $this->assertTrue($tarima->estaAnulada());
        $this->assertSame($admin->id, $tarima->anulada_by);
        $this->assertSame('Etiqueta duplicada por error', $tarima->motivo_anulacion);
        $this->assertSame(1, $tarima->consecutivo_inicial, 'Conserva su rango de consecutivos.');
        $this->assertSame(1, Registro::count());
        $this->assertSame(1, NumeroSerie::count());

        $entrada = Bitacora::where('accion', BitacoraService::TARIMA_ANULADA)->sole();
        $this->assertSame($tarima->id, $entrada->entidad_id);
        $this->assertSame('Tarima', $entrada->entidad_type);
        $this->assertSame('Etiqueta duplicada por error', $entrada->motivo);
        $this->assertTrue($entrada->datos['estaba_guardada']);
        $this->assertSame(1, $entrada->datos['piezas']);

        // Anular dos veces no se permite ni duplica la bitácora.
        $this->actingAs($admin)->post(route('tarimas.anular', $tarima), ['motivo' => 'Otra vez'])->assertForbidden();
        $this->assertSame(1, Bitacora::where('accion', BitacoraService::TARIMA_ANULADA)->count());
    }

    public function test_operador_solo_anula_su_tarima_vacia_en_captura(): void {
        $operador = $this->operador();
        $vacia = $this->tarima($operador);
        $conContenido = $this->tarima($operador);
        $this->activarPadre($operador, $conContenido);
        $this->post(route('escaneo.padre.cerrar'));
        $ajena = $this->tarima($this->operador());

        $this->actingAs($operador)->post(route('tarimas.anular', $conContenido), ['motivo' => 'No la quiero'])->assertForbidden();
        $this->actingAs($operador)->post(route('tarimas.anular', $ajena), ['motivo' => 'No es mía'])->assertForbidden();
        $this->actingAs($operador)->post(route('tarimas.anular', $this->guardada()), ['motivo' => 'Ya guardada'])->assertForbidden();

        // En captura, anular elimina el embarque.
        $this->actingAs($operador)->post(route('tarimas.anular', $vacia), ['motivo' => 'Creada por error'])->assertRedirect(route('wms'));
        $this->assertNull(Tarima::find($vacia->id));
    }

    public function test_anular_en_captura_elimina_el_embarque_y_deja_constancia(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');
        $this->post(route('escaneo.padre.cerrar'));

        $this->actingAs($this->admin())->post(route('tarimas.anular', $tarima), ['motivo' => 'Mercancía de otro cliente'])
            ->assertRedirect(route('wms'))
            ->assertSessionHas('warn', 'Embarque E-001 eliminado. El número E-001 se asignará al siguiente embarque.');

        $this->assertNull(Tarima::find($tarima->id));
        $this->assertSame(0, Registro::withTrashed()->count());
        $this->assertSame(0, NumeroSerie::withTrashed()->count());
        $bitacora = Bitacora::where('accion', BitacoraService::TARIMA_ELIMINADA)->sole();
        $this->assertSame(['SN-1'], $bitacora->datos['numeros_serie']);
        $this->assertSame('Mercancía de otro cliente', $bitacora->motivo);

        $this->actingAs($operador)->get(route('wms'))->assertOk()->assertDontSee($tarima->uuid);
        // La N/S quedó libre para escanearse en otro embarque.
        $otra = $this->tarima($operador);
        $this->assertSame('E-001', $otra->codigo);
        $this->activarPadre($operador, $otra);
        $this->escanearNs('SN-1')->assertSessionHasNoErrors();
    }

    public function test_un_embarque_guardado_se_anula_sin_borrarse(): void {
        $tarima = $this->guardada();
        $this->actingAs($this->admin())->post(route('tarimas.anular', $tarima), ['motivo' => 'Mercancía de otro cliente']);

        $this->assertTrue($tarima->fresh()->estaAnulada());
        $this->assertSame(1, Registro::where('tarima_id', $tarima->id)->count(), 'El contenido se conserva.');
        $this->postJson(route('tarimas.guardar', $tarima))->assertForbidden();
        $this->get(route('etiquetas.zpl', $tarima))->assertStatus(422);
    }

    public function test_el_historial_excluye_del_excel_las_tarimas_anuladas_y_las_marca(): void {
        $anulada = $this->guardada();
        $vigente = $this->guardada();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('tarimas.anular', $anulada), ['motivo' => 'Contenido duplicado']);

        $this->actingAs($admin)->get(route('historial'))->assertOk()->assertSee('Anulado')->assertSee('Contenido duplicado');

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($ruta, $this->get(route('export.todas'))->assertOk()->streamedContent());
        $filas = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getSheet(0)->toArray(null, false, false);
        @unlink($ruta);

        $this->assertSame([$vigente->id], array_column(array_slice($filas, 1), 7));
    }

    public function test_el_qr_de_una_tarima_anulada_avisa_que_no_es_valida(): void {
        $tarima = $this->guardada();
        $this->actingAs($this->admin())->post(route('tarimas.anular', $tarima), ['motivo' => 'Etiqueta dañada']);
        auth()->logout();

        $this->get(app(\App\Services\TarimaService::class)->urlDetalle($tarima))->assertOk()->assertSee('EMBARQUE ANULADO');
    }

    public function test_la_bitacora_no_se_modifica_ni_se_borra(): void {
        $entrada = app(BitacoraService::class)->registrar('prueba', $this->admin());

        try {
            $entrada->update(['accion' => 'otra']);
            $this->fail('Se pudo modificar la bitácora.');
        } catch (LogicException) {
        }

        try {
            $entrada->delete();
            $this->fail('Se pudo borrar la bitácora.');
        } catch (LogicException) {
        }

        $this->assertSame('prueba', $entrada->fresh()->accion);
    }
}
