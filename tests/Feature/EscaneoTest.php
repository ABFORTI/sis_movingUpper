<?php

namespace Tests\Feature;

use App\Enums\TipoRegistro;
use App\Models\NumeroSerie;
use App\Models\Registro;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class EscaneoTest extends TestCase {
    use PreparaWms;

    public function test_flujo_modo_1_sku_padre_con_numeros_de_serie(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);

        $this->activarPadre($operador, $tarima);
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-001'])->assertSessionHasNoErrors();
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-002'])->assertSessionHasNoErrors();

        $registro = Registro::firstOrFail();
        $this->assertSame(TipoRegistro::Padre, $registro->tipo);
        $this->assertSame(['SN-001', 'SN-002'], $registro->numerosSerie->pluck('numero_serie')->all());
        $this->assertSame($operador->id, $registro->user_id);

        $this->get(route('wms'))->assertOk()->assertSee('SN-002')->assertSee('SKU activo');
    }

    public function test_no_se_puede_abrir_otro_padre_sin_cerrar_el_activo(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);

        $this->post(route('escaneo.padre'), [
            'sku' => 'OTRO', 'destino_id' => $this->destino()->id, 'tarima' => $tarima->uuid,
        ])->assertSessionHasErrors('sku');

        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($operador, $tarima, 'OTRO');
        $this->assertSame(2, Registro::count());
    }

    public function test_numero_de_serie_es_unico_en_todo_el_sistema(): void {
        $operador = $this->operador();
        $this->activarPadre($operador, $this->tarima($operador), 'SKU-A');
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-999']);
        $this->post(route('escaneo.padre.cerrar'));

        $this->activarPadre($operador, $this->tarima($operador), 'SKU-B');
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-999'])->assertSessionHasErrors('numero_serie');

        $this->assertSame(1, NumeroSerie::count());
    }

    public function test_sku_no_se_repite_en_la_misma_tarima(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $datos = ['sku' => 'SKU-10023', 'destino_id' => $this->destino()->id, 'tarima' => $tarima->uuid];

        $this->actingAs($operador)->post(route('escaneo.simple'), $datos)->assertSessionHasNoErrors();
        $this->post(route('escaneo.simple'), $datos)->assertSessionHasErrors('sku');

        $this->post(route('escaneo.simple'), ['tarima' => $this->tarima($operador)->uuid] + $datos)->assertSessionHasNoErrors();
    }

    public function test_agregar_ns_sin_padre_activo_falla(): void {
        $this->actingAs($this->operador())
            ->post(route('escaneo.ns'), ['numero_serie' => 'SN-1'])
            ->assertSessionHasErrors('numero_serie');
    }

    public function test_cambiar_de_modo_cierra_el_padre_activo(): void {
        $operador = $this->operador();
        $this->activarPadre($operador, $this->tarima($operador));

        $this->post(route('wms.modo'), ['modo' => 2])->assertSessionHas('warn');
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-1'])->assertSessionHasErrors('numero_serie');
    }

    public function test_la_vista_escapa_el_contenido_escaneado(): void {
        $operador = $this->operador();
        $this->actingAs($operador)->post(route('escaneo.simple'), [
            'sku' => '<script>alert(1)</script>', 'destino_id' => $this->destino()->id, 'tarima' => $this->tarima($operador)->uuid,
        ]);

        $this->get(route('wms'))->assertDontSee('<script>alert(1)</script>', false);
    }
}
