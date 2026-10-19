<?php

namespace Tests\Feature;

use App\Models\Impresion;
use App\Models\NumeroSerie;
use App\Services\TarimaService;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class EtiquetaYExportacionTest extends TestCase {
    use PreparaWms;

    public function test_cada_pieza_genera_su_etiqueta_con_la_ns_en_el_codigo_de_barras(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');
        $this->escanearNs('SN-2');

        $zpl = $this->get(route('etiquetas.zpl', $tarima))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        $this->assertStringStartsWith('^XA', $zpl);
        $this->assertSame(2, substr_count($zpl, '^XA'), 'Una etiqueta por pieza, ninguna del embarque completo.');
        $this->assertStringContainsString('^BCN,136,N,N,N,A^FH\\^FDSN-1^FS', $zpl);
        $this->assertStringContainsString('^BCN,136,N,N,N,A^FH\\^FDSN-2^FS', $zpl);
        $this->assertSame(2, substr_count($zpl, 'MUD-0001 · E-001'));
        $this->assertSame(2, substr_count($zpl, '2364F'), 'Modelo (sin modelo en el inventario: el SKU).');
        $this->assertStringNotContainsString('^BQN', $zpl);
    }

    public function test_se_imprimen_solo_las_piezas_elegidas(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');
        $this->escanearNs('SN-2');
        $segunda = NumeroSerie::where('numero_serie', 'SN-2')->value('id');

        $zpl = $this->get(route('etiquetas.zpl', ['tarima' => $tarima, 'piezas' => $segunda]))->assertOk()->getContent();

        $this->assertSame(1, substr_count($zpl, '^XA'));
        $this->assertStringContainsString('^FDSN-2^FS', $zpl);
        $this->assertStringNotContainsString('SN-1', $zpl);
    }

    public function test_sin_piezas_con_ns_no_hay_etiqueta(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);

        $this->get(route('etiquetas.zpl', $tarima))->assertStatus(422);
        $this->get(route('etiquetas.show', $tarima))->assertRedirect(route('wms'))->assertSessionHasErrors('tarima');
    }

    public function test_piezas_de_otro_embarque_no_se_cuelan(): void {
        $operador = $this->operador();
        $propio = $this->tarima($operador);
        $this->activarPadre($operador, $propio);
        $this->escanearNs('SN-1');
        $this->post(route('escaneo.padre.cerrar'));
        $otro = $this->tarima($operador);
        $this->activarPadre($operador, $otro, 'OTRO');
        $this->escanearNs('AJENA');
        $ajena = NumeroSerie::where('numero_serie', 'AJENA')->value('id');

        $this->get(route('etiquetas.zpl', ['tarima' => $propio, 'piezas' => $ajena]))->assertStatus(422);
        $this->postJson(route('tarimas.impresa', ['tarima' => $propio, 'piezas' => $ajena]))->assertStatus(422);
        $this->assertNull(NumeroSerie::find($ajena)->etiqueta_impresa_at);
    }

    public function test_vista_previa_y_seleccion_de_etiquetas(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');
        $this->escanearNs('SN-2');

        $this->get(route('etiquetas.show', $tarima))->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('Imprimir 2 etiquetas')
            ->assertSee('Sin imprimir (2)')
            ->assertSee('MUD-0001 · E-001')
            ->assertDontSeeText('tarima')
            ->assertDontSeeText('Tarima');

        // Imprimir y confirmar la primera: quedan las demás como pendientes.
        $primera = NumeroSerie::where('numero_serie', 'SN-1')->value('id');
        $this->postJson(route('tarimas.impresa', ['tarima' => $tarima, 'piezas' => $primera]))->assertOk()
            ->assertJson(['impresa' => true, 'pendientes' => 1]);
        $this->get(route('etiquetas.show', $tarima))->assertOk()->assertSee('Imprimir etiqueta')->assertSee('Sin imprimir (1)');

        // Reimprimir una sola pieza.
        $this->get(route('etiquetas.show', ['tarima' => $tarima, 'piezas' => $primera]))->assertOk()
            ->assertSee('Es una reimpresión')
            ->assertSee(route('etiquetas.zpl', ['tarima' => $tarima, 'piezas' => $primera]), false);
    }

    public function test_detalle_publico_requiere_firma_valida(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-PUBLICA');
        auth()->logout();

        $url = app(TarimaService::class)->urlDetalle($tarima);

        $this->get($url)->assertOk()->assertSee('SN-PUBLICA')->assertSee('Piezas del embarque')->assertSee('En captura');
        $this->get(route('tarimas.publico', $tarima))->assertForbidden();
        $this->get($url.'x')->assertForbidden();
    }

    public function test_exportar_excel(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');

        // El embarque ya no tiene Manifiesto propio (el del tráiler sigue en Tráileres).
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('export.tarima'));

        // /excel es el historial: un embarque temporal no se exporta ahí.
        $this->get(route('export.todas'))->assertSessionHasErrors('tarima');
    }

    public function test_exportar_sin_productos_regresa_con_error(): void {
        $this->actingAs($this->operador())->get(route('export.todas'))->assertSessionHasErrors('tarima');
    }

    public function test_cada_confirmacion_registra_cuantas_etiquetas_y_si_es_reimpresion(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-1');
        $this->escanearNs('SN-2');

        $this->postJson(route('tarimas.impresa', $tarima))->assertOk()->assertJson(['pendientes' => 0]);
        $this->postJson(route('tarimas.impresa', ['tarima' => $tarima, 'piezas' => NumeroSerie::where('numero_serie', 'SN-2')->value('id')]))->assertOk();

        $this->assertSame([[2, false], [1, true]], Impresion::orderBy('id')->get()->map(fn ($i) => [$i->etiquetas, $i->es_reimpresion])->all());
    }
}
