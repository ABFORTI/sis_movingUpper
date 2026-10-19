<?php

namespace Tests\Feature;

use App\Models\NumeroSerie;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** Escaneo sin recarga (escaneo.js) y lo necesario para instalar la app (PWA). */
class HandheldTest extends TestCase {
    use PreparaWms;

    public function test_la_ns_por_json_responde_lo_que_la_pantalla_actualiza(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNsJson('SN-1')->assertOk();

        $this->escanearNsJson('SN-2')
            ->assertOk()
            ->assertJson([
                'numero_serie' => 'SN-2',
                'tarima' => $tarima->uuid,
                'total_registro' => 2,
                'piezas_tarima' => 2,
                'estado' => 'registrada',
                'coincidencia' => 'Número de serie',
                'incidencia' => null,
                'mensaje' => 'N/S SN-2 encontrada en el inventario (Número de serie). 2 en total.',
            ]);
    }

    public function test_la_ns_duplicada_por_json_regresa_422_con_el_motivo(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNsJson('SN-1');
        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($operador, $tarima, 'OTRO-SKU');

        $this->escanearNsJson('SN-1')
            ->assertStatus(422)
            ->assertJsonPath('errors.numero_serie.0', fn (string $m) => str_contains($m, 'ya está registrada'));
        $this->assertSame(1, NumeroSerie::count());
    }

    public function test_sin_padre_activo_por_json_regresa_422(): void {
        $this->actingAs($this->operador())
            ->escanearNsJson('SN-1')
            ->assertStatus(422)
            ->assertJsonPath('errors.numero_serie.0', 'No hay SKU activo (pudo haberse vaciado el embarque).');
    }

    public function test_sin_javascript_sigue_redirigiendo(): void {
        $this->actingAs($this->operador())
            ->escanearNs('SN-1')
            ->assertRedirect()
            ->assertSessionHasErrors('numero_serie');
    }

    public function test_la_pantalla_de_captura_trae_lo_que_usa_el_handheld(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);

        $this->get(route('wms'))->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('data-sw=', false)
            ->assertSee('data-escaneo-ns', false)
            ->assertSee('data-teclado', false)
            ->assertSee('js/escaneo.js', false)
            ->assertSee('js/inventario.js', false)
            ->assertSee('id="dlgSerie"', false)
            ->assertSee('id="dlgMotivo"', false);
    }

    public function test_solo_se_ven_las_ultimas_ns_y_el_resto_en_editar_numeros_de_serie(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        foreach (range(1, 10) as $i) {
            $this->escanearNs("SN-{$i}");
        }

        $html = $this->get(route('wms'))->assertOk()
            ->assertSee('Editar números de serie')
            ->assertDontSee('Revisar N/S y marcar productos dañados')
            ->getContent();
        // Plegado: las 10 están en el panel, pero como chip solo las 3 últimas.
        preg_match('/<div class="chips chips-recientes"[^>]*>(.*?)<\/div>/s', $html, $recientes);
        $this->assertSame(3, substr_count($recientes[1], 'class="chip'));
        $this->assertStringContainsString('SN-10', $recientes[1]);
        $this->assertStringNotContainsString('data-ns="SN-7"', $recientes[1]);
        $this->assertSame(10, substr_count($html, 'data-ns-fila>') - 1, 'Una fila por N/S, más la plantilla.');
        $this->assertStringNotContainsString('<details class="ns-editor" data-ns-editor open', $html);
    }

    public function test_el_manifiesto_y_el_service_worker_existen_y_son_validos(): void {
        $manifiesto = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifiesto['display']);
        $this->assertSame('/', $manifiesto['scope']);
        $tamanos = array_column($manifiesto['icons'], 'sizes');
        $this->assertContains('192x192', $tamanos);
        $this->assertContains('512x512', $tamanos);
        foreach ($manifiesto['icons'] as $icono) {
            $this->assertFileExists(public_path(ltrim($icono['src'], '/')));
        }

        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("request.method !== 'GET'", $sw, 'El service worker no debe tocar los POST de escaneo.');
        $this->assertFileExists(public_path('offline.html'));
    }
}
