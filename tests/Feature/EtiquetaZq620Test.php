<?php

namespace Tests\Feature;

use App\Models\Tarima;
use App\Services\Etiquetas\EtiquetaListado;
use App\Services\Etiquetas\EtiquetaService;
use App\Services\Etiquetas\Lienzo;
use App\Services\TarimaService;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** Formato físico horizontal 75 × 50 mm a 203 dpi (Zebra ZQ620): 600 × 400 dots, cabezal de 576 dots. */
class EtiquetaZq620Test extends TestCase {
    use PreparaWms;

    private function tarimaPadre(int $numerosSerie = 3, string $sku = '2364F'): Tarima {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima, $sku);
        foreach (range(1, $numerosSerie) as $i) {
            $this->post(route('escaneo.ns'), ['numero_serie' => "SN-{$sku}-{$i}"]);
        }

        return $tarima;
    }

    private function tarimaListado(int $piezas): Tarima {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        foreach (range(1, $piezas) as $i) {
            $this->actingAs($operador)->post(route('escaneo.simple'), [
                'sku' => "SKU-{$i}", 'descripcion' => 'Caja', 'destino_id' => $this->destino()->id, 'tarima' => $tarima->uuid,
            ]);
        }

        return $tarima;
    }

    /**
     * Todo elemento debe quedar dentro de la etiqueta, dentro de los 72 mm que
     * imprime el cabezal y a 2 mm (16 dots) de sus bordes.
     */
    private function assertDentroDelArea(Lienzo $lienzo): void {
        $f = $lienzo->formato;
        foreach ($lienzo->elementos() as $e) {
            [$ancho, $alto] = match ($e['tipo']) {
                'texto', 'barras', 'recuadro', 'imagen' => [$e['ancho'], $e['alto']],
                'qr' => [$e['lado'], $e['lado']],
                'linea' => [$e['ancho'], $e['grosor']],
            };
            $this->assertGreaterThanOrEqual($f->margenX, $e['x'], "{$e['tipo']} fuera por la izquierda");
            $this->assertGreaterThanOrEqual($f->margen, $e['y'], "{$e['tipo']} fuera por arriba");
            $this->assertLessThanOrEqual($f->ancho - $f->margenX, $e['x'] + $ancho, "{$e['tipo']} fuera por la derecha");
            $this->assertLessThanOrEqual($f->alto - $f->margen, $e['y'] + $alto, "{$e['tipo']} fuera por abajo");
            // Y, por lo tanto, dentro de lo que el cabezal alcanza a imprimir.
            $this->assertGreaterThanOrEqual($f->desfaseX, $e['x']);
            $this->assertLessThanOrEqual($f->desfaseX + $f->anchoImprimible, $e['x'] + $ancho);
        }
    }

    /** Ningún elemento debe encimarse con otro (salvo el texto invertido sobre su recuadro). */
    private function assertSinEncimarse(Lienzo $lienzo): void {
        $cajas = collect($lienzo->elementos())
            ->reject(fn ($e) => $e['tipo'] === 'recuadro' || ($e['invertido'] ?? false))
            ->map(fn ($e) => [
                $e['x'], $e['y'],
                $e['x'] + ($e['lado'] ?? $e['ancho']),
                $e['y'] + ($e['lado'] ?? $e['alto'] ?? $e['grosor']),
                $e['texto'] ?? $e['tipo'],
            ])
            ->values();
        foreach ($cajas as $i => $a) {
            foreach ($cajas->slice($i + 1) as $b) {
                $seEnciman = $a[0] < $b[2] && $b[0] < $a[2] && $a[1] < $b[3] && $b[1] < $a[3];
                $this->assertFalse($seEnciman, "Se enciman '{$a[4]}' y '{$b[4]}'");
            }
        }
    }

    public function test_zpl_define_el_tamano_fisico_y_el_papel(): void {
        $zpl = $this->get(route('etiquetas.zpl', $this->tarimaPadre()))->assertOk()->getContent();

        $this->assertStringContainsString('^PW576', $zpl);   // 72 mm que imprime el cabezal (papel de 75 mm)
        $this->assertStringContainsString('^LL400', $zpl);   // 50 mm × 8 dots/mm
        $this->assertStringContainsString('^MNY', $zpl);     // etiqueta troquelada (gap)
        $this->assertStringContainsString('^CI28', $zpl);    // UTF-8
    }

    public function test_etiqueta_es_horizontal_de_75x50(): void {
        $f = app(EtiquetaService::class)->paginas($this->tarimaPadre())[0]->formato;

        $this->assertSame(600, $f->ancho);
        $this->assertSame(400, $f->alto);
        $this->assertGreaterThan($f->alto, $f->ancho);
        $this->assertSame('75 × 50 mm', $f->medidas());
    }

    public function test_etiqueta_padre_cabe_en_75x50_con_qr_legible(): void {
        $paginas = app(EtiquetaService::class)->paginas($this->tarimaPadre());

        $this->assertCount(1, $paginas);
        $this->assertDentroDelArea($paginas[0]);
        $this->assertSinEncimarse($paginas[0]);

        $qr = collect($paginas[0]->elementos())->firstWhere('tipo', 'qr');
        // Módulo ≥ 4 dots (0.5 mm) y lado ≥ 24 mm: se lee con celular y con handheld.
        $this->assertGreaterThanOrEqual(4, $qr['modulo']);
        $this->assertGreaterThanOrEqual(24 * 8, $qr['lado']);
    }

    public function test_qr_conserva_el_mismo_destino_firmado(): void {
        $tarima = $this->tarimaPadre();
        $url = app(TarimaService::class)->urlDetalle($tarima);

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->getContent();

        $this->assertStringContainsString('^FDMA,'.$url.'^FS', $zpl);
    }

    public function test_qr_se_sube_en_el_zpl_para_compensar_el_desfase_de_bq(): void {
        config(['etiquetas.ajuste_qr_mm' => 1.5]);
        $tarima = $this->tarimaPadre();
        $qr = collect(app(EtiquetaService::class)->paginas($tarima)[0]->elementos())->firstWhere('tipo', 'qr');

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->getContent();

        // 1.5 mm = 12 dots más arriba que en el diseño (la vista previa conserva la posición del diseño).
        $this->assertStringContainsString(sprintf('^FO%d,%d^BQN', $qr['x'] - 12, $qr['y'] - 12), $zpl);
    }

    public function test_sku_largo_se_reduce_o_recorta_sin_salirse(): void {
        $paginas = app(EtiquetaService::class)->paginas($this->tarimaPadre(1, str_repeat('X', 60)));

        $this->assertDentroDelArea($paginas[0]);
    }

    public function test_varios_skus_padre_caben(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        foreach (['SKU-A', 'SKU-B', 'SKU-C'] as $sku) {
            $this->activarPadre($operador, $tarima, $sku);
            $this->post(route('escaneo.ns'), ['numero_serie' => "N-{$sku}"]);
            $this->post(route('escaneo.padre.cerrar'));
        }

        $pagina = app(EtiquetaService::class)->paginas($tarima)[0];
        $this->assertDentroDelArea($pagina);
        $this->assertTrue(collect($pagina->elementos())->contains(fn ($e) => ($e['texto'] ?? '') === '+ 2 SKUs más'));
    }

    public function test_listado_pagina_segun_lo_que_cabe_fisicamente(): void {
        $capacidad = app(EtiquetaListado::class)->capacidad();
        $this->assertSame(9, $capacidad);

        $paginas = app(EtiquetaService::class)->paginas($this->tarimaListado(30));

        $this->assertCount(4, $paginas);
        foreach ($paginas as $pagina) {
            $this->assertDentroDelArea($pagina);
            $this->assertSinEncimarse($pagina);
        }
        $this->assertTrue(collect($paginas[3]->elementos())->contains(fn ($e) => ($e['texto'] ?? '') === 'TARIMA 1 (4/4)'));
    }

    public function test_logos_se_imprimen_sin_deformarse(): void {
        $pagina = app(EtiquetaService::class)->paginas($this->tarimaPadre())[0];
        $logos = collect($pagina->elementos())->where('tipo', 'imagen')->values();

        $this->assertCount(2, $logos);
        // Proporción del trazo de cada archivo: upper_logo1 810×178, Euroquip4 1202×235.
        foreach ([810 / 178, 1202 / 235] as $i => $proporcion) {
            $this->assertEqualsWithDelta($proporcion, $logos[$i]['ancho'] / $logos[$i]['alto'], 0.2);
        }
        // Uno a cada lado de la franja superior.
        $this->assertSame($pagina->formato->margenX, $logos[0]['x']);
        $this->assertSame($pagina->formato->limiteDerecho(), $logos[1]['x'] + $logos[1]['ancho']);

        $zpl = $this->get(route('etiquetas.zpl', Tarima::first()))->getContent();
        $this->assertSame(2, substr_count($zpl, '^GFA,'));
    }

    public function test_codigo_de_barras_usa_modulo_legible(): void {
        $pagina = app(EtiquetaService::class)->paginas($this->tarimaPadre())[0];
        $barras = collect($pagina->elementos())->firstWhere('tipo', 'barras');

        $this->assertSame(2, $barras['modulo']);   // 0.25 mm por módulo
        $this->assertStringContainsString('^BY2^BCN', $this->get(route('etiquetas.zpl', Tarima::first()))->getContent());
    }

    public function test_caracteres_especiales_no_rompen_el_zpl(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->actingAs($operador)->post(route('escaneo.simple'), [
            'sku' => 'Ñu~^XZ\\é', 'destino_id' => $this->destino()->id, 'tarima' => $tarima->uuid,
        ]);

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->getContent();

        $this->assertSame(1, substr_count($zpl, '^XA'));
        $this->assertSame(1, substr_count($zpl, '^XZ'));
        $this->assertStringContainsString('Ñu\\7E\\5EXZ\\5Cé', $zpl);
    }

    public function test_vista_previa_es_el_mismo_diseno_a_escala_real(): void {
        $tarima = $this->tarimaListado(20);

        $html = $this->get(route('etiquetas.show', $tarima))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'class="etiqueta-svg"'));   // 20 piezas, 9 por etiqueta
        $this->assertStringContainsString('width="75mm" height="50mm"', $html);
        $this->assertStringContainsString('@page { size: 75mm 50mm', $html);
    }

    public function test_diagnostico_registra_errores_tecnicos(): void {
        Log::shouldReceive('channel')->once()->with('impresion')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(fn ($msg, $ctx) => $ctx['metodo'] === 'bluetooth' && $ctx['etapa'] === 'envio');

        $this->actingAs($this->operador())->postJson(route('impresion.diagnostico'), [
            'metodo' => 'bluetooth', 'etapa' => 'envio', 'error' => 'NetworkError', 'detalle' => 'GATT Server is disconnected.',
        ])->assertNoContent();
    }

    public function test_diagnostico_requiere_sesion_y_datos_validos(): void {
        $this->postJson(route('impresion.diagnostico'), ['metodo' => 'bluetooth', 'etapa' => 'x'])->assertUnauthorized();
        $this->actingAs($this->operador())->postJson(route('impresion.diagnostico'), ['metodo' => 'otro', 'etapa' => 'x'])->assertUnprocessable();
    }
}
