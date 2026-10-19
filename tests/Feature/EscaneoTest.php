<?php

namespace Tests\Feature;

use App\Enums\TipoRegistro;
use App\Models\NumeroSerie;
use App\Models\Registro;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class EscaneoTest extends TestCase {
    use PreparaWms;

    public function test_flujo_sku_con_numeros_de_serie(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);

        $this->activarPadre($operador, $tarima);
        $this->escanearNs('SN-001')->assertSessionHasNoErrors();
        $this->escanearNs('SN-002')->assertSessionHasNoErrors();

        $registro = Registro::firstOrFail();
        $this->assertSame(TipoRegistro::Padre, $registro->tipo);
        $this->assertSame(['SN-001', 'SN-002'], $registro->numerosSerie->pluck('numero_serie')->all());
        $this->assertSame($operador->id, $registro->user_id);
        $this->assertNull($registro->destino_id, 'La categoría ya no se captura.');
        $this->assertSame('Bomba hidráulica', $registro->descripcion, 'La descripción sale del inventario.');

        $this->get(route('wms'))->assertOk()->assertSee('SN-002')->assertSee('SKU activo')
            ->assertDontSee('Categoría')->assertDontSee('Solo SKUs');
    }

    public function test_no_se_puede_abrir_otro_sku_sin_cerrar_el_activo(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->skuEnInventario('OTRO');

        $this->post(route('escaneo.padre'), ['sku' => 'OTRO', 'tarima' => $tarima->uuid])->assertSessionHasErrors('sku');

        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($operador, $tarima, 'OTRO');
        $this->assertSame(2, Registro::count());
    }

    public function test_numero_de_serie_es_unico_en_todo_el_sistema(): void {
        $operador = $this->operador();
        $this->activarPadre($operador, $this->tarima($operador), 'SKU-A');
        $this->escanearNs('SN-999');
        $this->post(route('escaneo.padre.cerrar'));

        $this->activarPadre($operador, $this->tarima($operador), 'SKU-B');
        $this->escanearNs('SN-999')->assertSessionHasErrors('numero_serie');

        $this->assertSame(1, NumeroSerie::count());
    }

    public function test_volver_a_escanear_la_misma_ns_en_el_mismo_sku_no_duplica(): void {
        $operador = $this->operador();
        $this->activarPadre($operador, $this->tarima($operador));
        $this->escanearNsJson('SN-1')->assertOk()->assertJsonPath('estado', 'registrada');
        $this->escanearNsJson('SN-1')->assertOk()->assertJsonPath('estado', 'repetida');

        $this->assertSame(1, NumeroSerie::count());
    }

    public function test_reabrir_el_mismo_sku_en_la_tarima_no_lo_duplica(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima, 'SKU-10023');
        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($operador, $tarima, 'SKU-10023');

        $this->assertSame(1, Registro::count());
    }

    public function test_agregar_ns_sin_sku_activo_falla(): void {
        $this->actingAs($this->operador())
            ->escanearNs('SN-1')
            ->assertSessionHasErrors('numero_serie');
    }

    public function test_el_modo_solo_skus_ya_no_existe(): void {
        $this->actingAs($this->operador());

        $this->post('/escaneo/simple', ['sku' => 'X'])->assertNotFound();
        $this->post('/modo', ['modo' => 2])->assertNotFound();
    }

    public function test_la_vista_escapa_el_contenido_escaneado(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima, '<script>alert(1)</script>');

        $this->get(route('wms'))->assertDontSee('<script>alert(1)</script>', false);
    }
}
