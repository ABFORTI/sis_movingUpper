<?php

namespace Tests\Feature;

use App\Enums\Demo;
use App\Models\Partida;
use App\Models\Registro;
use App\Models\Tarima;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;


class DemoTest extends TestCase {
    use PreparaWms;

    private function activar(User $op, Tarima $tarima, string $sku, ?string $demo): TestResponse {
        $this->skuEnInventario($sku, almacen: $tarima->movimiento?->origen?->codigo);

        return $this->actingAs($op)->post(route('escaneo.padre'), array_filter(['sku' => $sku, 'tarima' => $tarima->uuid, 'demo' => $demo]));
    }

    /** @return list<list<mixed>> filas del Excel de trabajo, sin encabezado */
    private function excel(): array {
        $ruta = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($ruta, $this->get(route('export.todas'))->assertOk()->streamedContent());
        try {
            return array_slice(IOFactory::load($ruta)->getSheet(0)->toArray(null, false, false), 1);
        } finally {
            @unlink($ruta);
        }
    }

    public function test_el_selector_ofrece_las_opciones_y_por_defecto_no_aplica(): void {
        $op = $this->operador();
        $this->tarima($op);

        $html = $this->actingAs($op)->get(route('wms'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<select id="demo" name="demo"#', $html);
        $this->assertMatchesRegularExpression('#<option value="Na" selected>No aplica</option>#', $html);
        foreach (['DemoA' => 'DEMO A', 'DemoB' => 'DEMO B', 'DemoC' => 'DEMO C', 'DemoD' => 'DEMO D'] as $valor => $texto) {
            $this->assertStringContainsString("<option value=\"{$valor}\" >{$texto}</option>", $html);
        }

        $this->assertLessThan(strpos($html, 'Activar SKU'), strpos($html, 'name="demo"'));
    }

    public function test_el_demo_elegido_queda_en_el_sku_y_se_ve_al_escanear(): void {
        $op = $this->operador();
        $tarima = $this->tarima($op);

        $this->activar($op, $tarima, '2364F', 'DemoB')->assertSessionHasNoErrors()
            ->assertSessionHas('ok', fn (string $m) => str_contains($m, '2364F (DEMO B) activo'));
        $this->assertSame(Demo::B, Registro::sole()->demo);
        $this->get(route('wms'))->assertOk()->assertSee('<span class="badge badge-warn" data-sku-demo>DEMO B</span>', false);

        // Sin elegir (o "No aplica"): queda No aplica y no se muestra nada.
        $this->post(route('escaneo.padre.cerrar'));
        $this->activar($op, $tarima, 'RF-100', null)->assertSessionHasNoErrors();
        $this->assertSame(Demo::NoAplica, Registro::where('sku', 'RF-100')->sole()->demo);
        $this->get(route('wms'))->assertOk()->assertDontSee('data-sku-demo', false);
    }

    public function test_valor_desconocido_se_rechaza(): void {
        $op = $this->operador();
        $this->activar($op, $this->tarima($op), '2364F', 'DemoZ')->assertSessionHasErrors('demo');
        $this->assertSame(0, Registro::count());
    }

    public function test_al_reabrir_el_sku_conserva_el_demo_de_cuando_se_abrio(): void {
        $op = $this->operador();
        $tarima = $this->tarima($op);
        $this->activar($op, $tarima, '2364F', 'DemoA');
        $this->post(route('escaneo.padre.cerrar'));

        $this->activar($op, $tarima, '2364F', 'DemoC')->assertSessionHasNoErrors()
            ->assertSessionHas('ok', fn (string $m) => str_contains($m, 'Se conserva «DEMO A»'));
        $this->assertSame(Demo::A, Registro::sole()->demo);
    }

    public function test_la_etiqueta_lleva_el_demo_solo_si_aplica(): void {
        $op = $this->operador();
        $tarima = $this->tarima($op);
        $this->activar($op, $tarima, '2364F', 'DemoD');
        $this->escanearNs('NS-DEMO-1');
        $this->post(route('escaneo.padre.cerrar'));
        $this->activar($op, $tarima, 'RF-100', 'Na');
        $this->escanearNs('NS-NORMAL-1');

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->assertOk()->getContent();
        $etiquetas = array_values(array_filter(explode('^XA', $zpl), fn ($e) => str_contains($e, '^XZ')));
        $this->assertCount(2, $etiquetas);
        [$demo, $normal] = str_contains($etiquetas[0], 'NS-DEMO-1') ? $etiquetas : array_reverse($etiquetas);
        // Blanco sobre negro: recuadro y texto invertido.
        $this->assertMatchesRegularExpression('/\^GB\d+,\d+,\d+\^FS\s*\^FO\d+,\d+\^A0N,\d+,\d+\^FB\d+,1,0,C\^FR\^FH\\\\\^FDDEMO D\^FS/', $demo);
        $this->assertStringNotContainsString('DEMO', $normal);

        // La vista previa (SVG) también.
        $this->get(route('etiquetas.show', $tarima))->assertOk()->assertSee('DEMO D');
    }

    public function test_el_excel_indica_el_demo_de_cada_pieza(): void {
        Storage::fake('documentos');
        $op = $this->operador();
        $tarima = $this->tarima($op);
        $this->activar($op, $tarima, '2364F', 'DemoC');
        $this->escanearNs('NS-DEMO-1');
        $this->post(route('escaneo.padre.cerrar'));
        $this->activar($op, $tarima, 'RF-100', null);
        $this->escanearNs('NS-NORMAL-1');
        $this->post(route('escaneo.padre.cerrar'));
        $this->postJson(route('tarimas.impresa', $tarima))->assertOk();
        $this->postJson(route('tarimas.guardar', $tarima))->assertOk();

        $this->assertSame(Demo::C, Partida::where('numero_serie', 'NS-DEMO-1')->sole()->demo);
        $porNs = collect($this->excel())->keyBy(6);
        $this->assertSame('DEMO C', $porNs['NS-DEMO-1'][9]);
        $this->assertSame('No aplica', $porNs['NS-NORMAL-1'][9]);
    }
}
