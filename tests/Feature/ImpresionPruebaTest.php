<?php

namespace Tests\Feature;

use App\Services\Etiquetas\EtiquetaPrueba;
use App\Services\Etiquetas\FormatoEtiqueta;
use App\Models\Tarima;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * Formato ZPL autosuficiente (no depende de lo guardado en la impresora),
 * etiqueta de prueba y calibración de la ZQ620.
 */
class ImpresionPruebaTest extends TestCase {
    use PreparaWms;

    private function zplDeTarima(): string {
        $op = $this->operador();
        $tarima = $this->tarima($op);
        $this->activarPadre($op, $tarima);
        $this->escanearNs('NS-1');

        return $this->get(route('etiquetas.zpl', $tarima))->assertOk()->getContent();
    }

    public function test_cada_etiqueta_reinicia_origen_y_tipo_de_papel(): void {
        $zpl = $this->zplDeTarima();
        $encabezado = substr($zpl, 0, strpos($zpl, '^FO'));

        foreach (['^XA', '^CI28', '^MTD', '^MNY', '^MMT', '^PW576', '^LL400', '^LH0,0', '^LT0', '^LS0', '^PON'] as $comando) {
            $this->assertStringContainsString($comando, $encabezado, "Falta {$comando} antes del primer campo");
        }
        $this->assertStringNotContainsString('^JUS', $zpl, 'Una etiqueta normal no debe guardar ajustes en la impresora');
        $this->assertStringNotContainsString('~JC', $zpl);
    }

    public function test_ajuste_superior_configurable_y_acotado(): void {
        config(['etiquetas.ajuste_superior_mm' => -2]);
        $this->assertStringContainsString('^LT-16', $this->zplDeTarima());

        // ZPL solo acepta ^LT entre -120 y 120 dots (±15 mm a 203 dpi).
        $this->assertSame(120, (new FormatoEtiqueta(203, 75, 50, 2, 'gap', 72, 0, 99))->ajusteSuperior);
        $this->assertSame(-120, (new FormatoEtiqueta(203, 75, 50, 2, 'gap', 72, 0, -99))->ajusteSuperior);
        $this->assertSame(-16, FormatoEtiqueta::desdeConfig()->ajusteSuperior);
    }

    public function test_etiqueta_de_prueba_marca_los_cuatro_bordes_del_area_imprimible(): void {
        $zpl = app(EtiquetaPrueba::class)->zpl();

        $this->assertSame(1, substr_count($zpl, '^XA'));
        $this->assertStringContainsString('^FO0,0^GB576,4,4^FS', $zpl);     // arriba
        $this->assertStringContainsString('^FO0,396^GB576,4,4^FS', $zpl);   // abajo
        $this->assertStringContainsString('^FO0,0^GB4,400,400^FS', $zpl);   // izquierda
        $this->assertStringContainsString('^FO572,0^GB4,400,400^FS', $zpl); // derecha
        $this->assertStringContainsString('ARRIBA', $zpl);
        $this->assertStringContainsString('ABAJO', $zpl);
        $this->assertStringContainsString('^BCN', $zpl);
        $this->assertStringContainsString('^BQN', $zpl);

        // Nada se sale del ancho del cabezal ni del largo de la etiqueta.
        preg_match_all('/\^FO(-?\d+),(-?\d+)/', $zpl, $m, PREG_SET_ORDER);
        foreach ($m as [, $x, $y]) {
            $this->assertGreaterThanOrEqual(0, (int) $x);
            $this->assertLessThan(576, (int) $x);
            $this->assertGreaterThanOrEqual(0, (int) $y);
            $this->assertLessThan(400, (int) $y);
        }
    }

    public function test_configuracion_guarda_el_formato_y_calibra(): void {
        $this->actingAs($this->operador());
        $zpl = $this->get(route('impresion.configuracion.zpl'))->assertOk()->assertHeader('Cache-Control')->getContent();

        $this->assertSame("^XA\n^MTD\n^MNY\n^PW576\n^LL400\n^LH0,0\n^LT0\n^LS0\n^JUS\n^XZ\n~JC\n", $zpl);
    }

    public function test_pagina_de_prueba_para_cualquier_usuario_y_requiere_sesion(): void {
        $this->get(route('impresion.prueba'))->assertRedirect(route('login'));
        $this->get(route('impresion.prueba.zpl'))->assertRedirect(route('login'));

        $this->actingAs($this->operador())->get(route('impresion.prueba'))->assertOk()
            ->assertSee('Prueba de impresión')
            ->assertSee('id="btnElegir"', false)
            ->assertSee('id="btnCancelar"', false)
            ->assertSee('id="btnCalibrar"', false)
            ->assertSee('data-configuracion="'.route('impresion.configuracion.zpl').'"', false)
            ->assertDontSee('data-impresa=', false)
            ->assertSee('75 × 50 mm')
            ->assertSee('class="etiqueta-svg"', false);

        $this->actingAs($this->operador())->get(route('impresion.prueba.zpl'))->assertOk()->assertSee('^XA', false);
    }

    public function test_pagina_de_la_etiqueta_usa_el_mismo_panel(): void {
        $this->zplDeTarima();
        $tarima = Tarima::sole();
        $pieza = \App\Models\NumeroSerie::sole()->id;

        // Las URL del ZPL y de la confirmación llevan las piezas elegidas: se marca impreso exactamente lo que salió.
        $this->get(route('etiquetas.show', $tarima))->assertOk()
            ->assertSee('data-impresa="'.e(route('tarimas.impresa', ['tarima' => $tarima, 'piezas' => $pieza])).'"', false)
            ->assertSee('data-zpl="'.e(route('etiquetas.zpl', ['tarima' => $tarima, 'piezas' => $pieza])).'"', false)
            ->assertSee('data-uuid="'.$tarima->uuid.'"', false)
            ->assertSee('id="btnElegir"', false)
            ->assertSee('id="btnConfirmar"', false)
            ->assertSee(route('impresion.prueba'), false)
            ->assertDontSee('id="btnCalibrar"', false);
    }
}
