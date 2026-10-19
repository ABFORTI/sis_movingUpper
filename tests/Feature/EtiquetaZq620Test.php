<?php

namespace Tests\Feature;

use App\Models\Tarima;
use App\Services\Etiquetas\EtiquetaService;
use App\Services\Etiquetas\Lienzo;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/**
 * Etiqueta por pieza en el formato físico de siempre: horizontal 75 × 50 mm a
 * 203 dpi (Zebra ZQ620), 600 × 400 dots, cabezal de 576 dots. Solo lleva el
 * modelo, el número de embarque y el código de barras (Code 128) de la N/S.
 */
class EtiquetaZq620Test extends TestCase {
    use PreparaWms;

    private ?\App\Models\User $op = null;

    /** @param list<string> $series */
    private function embarque(array $series = ['SN-1', 'SN-2', 'SN-3'], string $sku = '2364F', ?string $modelo = null): Tarima {
        $operador = $this->op ??= $this->operador();
        $tarima = $this->tarima($operador);
        if ($modelo !== null) {
            $this->enInventario(array_map(fn ($ns) => ['sku' => $sku, 'modelo' => $modelo, 'numero_serie' => $ns], $series));
        }
        $this->actingAs($operador)->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($operador, $tarima, $sku);
        foreach ($series as $ns) {
            $this->escanearNs($ns, agregarAlInventario: $modelo === null)->assertSessionHasNoErrors();
        }

        return $tarima;
    }

    /** @return list<string> textos de la etiqueta, en orden */
    private function textos(Lienzo $lienzo): array {
        return collect($lienzo->elementos())->where('tipo', 'texto')->pluck('texto')->values()->all();
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

    /** Ningún elemento debe encimarse con otro. */
    private function assertSinEncimarse(Lienzo $lienzo): void {
        $cajas = collect($lienzo->elementos())
            ->map(fn ($e) => [$e['x'], $e['y'], $e['x'] + ($e['lado'] ?? $e['ancho']), $e['y'] + ($e['lado'] ?? $e['alto'] ?? $e['grosor']), $e['texto'] ?? $e['tipo']])
            ->values();
        foreach ($cajas as $i => $a) {
            foreach ($cajas->slice($i + 1) as $b) {
                $seEnciman = $a[0] < $b[2] && $b[0] < $a[2] && $a[1] < $b[3] && $b[1] < $a[3];
                $this->assertFalse($seEnciman, "Se enciman '{$a[4]}' y '{$b[4]}'");
            }
        }
    }

    public function test_zpl_conserva_el_tamano_fisico_y_el_papel(): void {
        $zpl = $this->get(route('etiquetas.zpl', $this->embarque()))->assertOk()->getContent();

        $this->assertStringContainsString('^PW576', $zpl);   // 72 mm que imprime el cabezal (papel de 75 mm)
        $this->assertStringContainsString('^LL400', $zpl);   // 50 mm × 8 dots/mm
        $this->assertStringContainsString('^MNY', $zpl);     // etiqueta troquelada (gap)
        $this->assertStringContainsString('^CI28', $zpl);    // UTF-8
        $this->assertStringContainsString('^PON', $zpl);     // misma orientación
    }

    public function test_etiqueta_es_horizontal_de_75x50(): void {
        $f = app(EtiquetaService::class)->paginas($this->embarque())[0]->formato;

        $this->assertSame(600, $f->ancho);
        $this->assertSame(400, $f->alto);
        $this->assertSame('75 × 50 mm', $f->medidas());
    }

    public function test_una_etiqueta_por_pieza_dentro_del_area_y_sin_encimarse(): void {
        $tarima = $this->embarque(['SN-1', 'SN-2', 'SN-3']);

        $paginas = app(EtiquetaService::class)->paginas($tarima);
        $this->assertCount(3, $paginas);
        foreach ($paginas as $pagina) {
            $this->assertDentroDelArea($pagina);
            $this->assertSinEncimarse($pagina);
        }

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->getContent();
        $this->assertSame(3, substr_count($zpl, '^XA'));
        $this->assertSame(3, substr_count($zpl, '^XZ'));
    }

    public function test_solo_modelo_numero_de_embarque_y_codigo_de_barras_del_ns(): void {
        $tarima = $this->embarque(['SN-000123'], 'REF-100', modelo: 'RF-2364F');
        $pagina = app(EtiquetaService::class)->paginas($tarima)[0];

        $this->assertSame(['MODELO', 'RF-2364F', 'EMBARQUE', 'MUD-0001 · E-001', 'SN-000123'], $this->textos($pagina));
        $this->assertSame(['texto', 'barras'], collect($pagina->elementos())->pluck('tipo')->unique()->values()->all(), 'Sin logos, QR, recuadros ni líneas.');

        $barras = collect($pagina->elementos())->firstWhere('tipo', 'barras');
        $this->assertSame('SN-000123', $barras['dato'], 'El código de barras es la N/S exacta, sin prefijos.');

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->getContent();
        $this->assertStringContainsString('^BCN,136,N,N,N,A^FH\\^FDSN-000123^FS', $zpl);
        $this->assertStringNotContainsString('^BQN', $zpl, 'Sin QR.');
        $this->assertStringNotContainsString('TAR-', $zpl);
        $this->assertStringNotContainsStringIgnoringCase('tarima', $zpl);
        foreach (['REF-100', 'Bomba', 'Producto', 'Destino', 'Fecha', 'Piezas', 'Almacén'] as $sobra) {
            $this->assertStringNotContainsString($sobra, $zpl, "La etiqueta no lleva «{$sobra}».");
        }
    }

    public function test_el_modelo_sale_del_inventario_y_si_no_hay_se_usa_el_sku(): void {
        // Con modelo en el inventario: el modelo; sin modelo: el SKU.
        $conModelo = $this->embarque(['A-1'], 'REF-100', modelo: 'RF-2364F');
        $sinModelo = $this->embarque(['B-1'], 'EST-200');

        $this->assertSame('RF-2364F', $this->textos(app(EtiquetaService::class)->paginas($conModelo)[0])[1]);
        $this->assertSame('EST-200', $this->textos(app(EtiquetaService::class)->paginas($sinModelo)[0])[1]);

        // Una N/S registrada como incidencia (sin renglón) toma el modelo de su SKU en el inventario.
        $this->post(route('escaneo.padre.cerrar'));
        $this->activarPadre($this->op, $conModelo, 'REF-100');
        $this->escanearNs('NO-INV', agregarAlInventario: false)->assertSessionHasNoErrors();
        $textos = collect(app(EtiquetaService::class)->paginas($conModelo))->map(fn ($p) => $this->textos($p));
        $this->assertSame(['RF-2364F', 'RF-2364F'], $textos->pluck(1)->all());
        $this->assertSame(['A-1', 'NO-INV'], $textos->pluck(4)->all());
    }

    public function test_el_numero_de_embarque_es_el_mismo_en_todas_sus_piezas(): void {
        $primero = $this->embarque(['P-1', 'P-2', 'P-3']);
        $segundo = $this->embarque(['Q-1']);

        $numeros = collect(app(EtiquetaService::class)->paginas($primero))->map(fn ($p) => $this->textos($p)[3])->unique();
        $this->assertSame(['MUD-0001 · E-001'], $numeros->values()->all());
        $this->assertSame('MUD-0001 · E-002', $this->textos(app(EtiquetaService::class)->paginas($segundo)[0])[3]);
    }

    public function test_codigo_de_barras_legible_y_ns_larga_sin_salirse(): void {
        $pagina = app(EtiquetaService::class)->paginas($this->embarque(['SN-000123']))[0];
        $barras = collect($pagina->elementos())->firstWhere('tipo', 'barras');
        $this->assertSame(2, $barras['modulo']);                 // 0.25 mm por módulo
        $this->assertSame(17 * 8, $barras['alto']);              // 17 mm de alto
        $this->assertGreaterThanOrEqual(20, $barras['x'] - $pagina->formato->margenX, 'Zona blanca a la izquierda.');

        $larga = app(EtiquetaService::class)->paginas($this->embarque([str_repeat('AB', 20)], 'OTRO'))[0];
        $this->assertDentroDelArea($larga);
        $this->assertSame(str_repeat('AB', 20), collect($larga->elementos())->firstWhere('tipo', 'barras')['dato']);
    }

    public function test_modelo_largo_se_reduce_o_recorta_sin_salirse(): void {
        $pagina = app(EtiquetaService::class)->paginas($this->embarque(['L-1'], str_repeat('X', 60)))[0];

        $this->assertDentroDelArea($pagina);
        $this->assertSinEncimarse($pagina);
    }

    public function test_caracteres_especiales_no_rompen_el_zpl(): void {
        $tarima = $this->embarque(['AB^XZ~\\1']);

        $zpl = $this->get(route('etiquetas.zpl', $tarima))->getContent();

        $this->assertSame(1, substr_count($zpl, '^XA'));
        $this->assertSame(1, substr_count($zpl, '^XZ'));
        // Con ^FH la impresora decodifica \5E, \7E y \5C: el código de barras vuelve a ser la N/S exacta.
        $this->assertStringContainsString('^FDAB\\5EXZ\\7E\\5C1^FS', $zpl);
    }

    public function test_ns_con_caracteres_fuera_de_code128_va_en_texto(): void {
        $pagina = app(EtiquetaService::class)->paginas($this->embarque(['ÑU-1']))[0];

        $this->assertNull(collect($pagina->elementos())->firstWhere('tipo', 'barras'));
        $this->assertContains('ÑU-1', $this->textos($pagina));
        $this->assertDentroDelArea($pagina);
    }

    public function test_vista_previa_es_el_mismo_diseno_a_escala_real(): void {
        $tarima = $this->embarque(['V-1', 'V-2']);

        $html = $this->get(route('etiquetas.show', $tarima))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="etiqueta-svg"'));
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
