<?php

namespace Tests\Feature;

use App\Services\TarimaService;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class EtiquetaYExportacionTest extends TestCase {
    use PreparaWms;

    public function test_tarima_solo_padre_genera_zpl_con_qr_de_url_firmada(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-1']);

        $zpl = $this->get(route('etiquetas.zpl', $tarima))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        $this->assertStringStartsWith('^XA', $zpl);
        $this->assertStringContainsString('^BQN', $zpl);
        $this->assertStringContainsString('/t/'.$tarima->uuid.'?signature=', $zpl);
        $this->assertStringContainsString('^BCN', $zpl);
        $this->assertStringContainsString('2364F', $zpl);
    }

    public function test_tarima_mixta_genera_tabla_paginada(): void {
        config(['etiquetas.filas_por_etiqueta' => 2]);
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        foreach (['A', 'B', 'C'] as $sku) {
            $this->actingAs($operador)->post(route('escaneo.simple'), [
                'sku' => $sku, 'destino_id' => $this->destino()->id, 'tarima' => $tarima->uuid,
            ]);
        }

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->assertOk()->getContent();

        $this->assertStringNotContainsString('^BQN', $zpl);
        $this->assertSame(2, substr_count($zpl, '^XA'));
        $this->assertStringContainsString('(1/2)', $zpl);
    }

    public function test_zpl_escapa_caracteres_de_control(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->actingAs($operador)->post(route('escaneo.simple'), [
            'sku' => 'AB^XZ~', 'destino_id' => $this->destino()->id, 'tarima' => $tarima->uuid,
        ]);

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->getContent();

        $this->assertStringContainsString('AB\\5EXZ\\7E', $zpl);
        $this->assertSame(1, substr_count($zpl, '^XZ'));
    }

    public function test_vista_previa_de_etiqueta(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);

        $this->get(route('etiquetas.show', $tarima))->assertOk()->assertSee('<svg', false)->assertSee('Imprimir etiqueta');
    }

    public function test_detalle_publico_requiere_firma_valida(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-PUBLICA']);
        auth()->logout();

        $url = app(TarimaService::class)->urlDetalle($tarima);

        $this->get($url)->assertOk()->assertSee('SN-PUBLICA');
        $this->get(route('tarimas.publico', $tarima))->assertForbidden();
        $this->get($url.'x')->assertForbidden();
    }

    public function test_exportar_excel(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-1']);

        $this->get(route('export.tarima', $tarima))
            ->assertOk()
            ->assertDownload("Tarima_{$tarima->id}_".now()->format('Y-m-d').'.xlsx');

        // /excel es el historial: una tarima temporal no se exporta ahí.
        $this->get(route('export.todas'))->assertSessionHasErrors('tarima');
    }

    public function test_exportar_sin_productos_regresa_con_error(): void {
        $this->actingAs($this->operador())->get(route('export.todas'))->assertSessionHasErrors('tarima');
    }
}
