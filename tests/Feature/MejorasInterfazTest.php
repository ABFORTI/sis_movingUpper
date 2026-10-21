<?php

namespace Tests\Feature;

use App\Models\Embarque;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** Menú del auditor en la computadora, Fotos del N/S y sugerencias al registrar tráileres. */
class MejorasInterfazTest extends TestCase {
    use PreparaWms;

    /** Enlace de la barra (pestaña en el celular) con su clase. */
    private function enlaceBarra(string $html, string $ruta): ?string {
        preg_match('#<a href="'.preg_quote(route($ruta), '#').'"[^>]*class="([^"]*nav-link[^"]*)"#', $html, $m);

        return $m[1] ?? null;
    }

    /** Enlace dentro del menú "Más" con su clase. */
    private function enlaceMas(string $html, string $ruta): ?string {
        preg_match('#<a href="'.preg_quote(route($ruta), '#').'" class="([^"]*nav-mas-link[^"]*)"#', $html, $m);

        return $m[1] ?? null;
    }

    public function test_el_auditor_ve_mudanzas_manuales_e_incidencias_en_el_menu_en_la_computadora(): void {
        $html = $this->actingAs($this->auditor())->get(route('wms'))->assertOk()->getContent();

        // "Más" también se muestra en la computadora.
        $this->assertMatchesRegularExpression('/<details class="nav-mas" data-nav-mas>/', $html);
        // Manuales y Mudanzas: solo en "Más", en la computadora y en el celular.
        $this->assertNull($this->enlaceBarra($html, 'manuales.index'));
        $this->assertNull($this->enlaceBarra($html, 'mudanzas.index'));
        $this->assertStringNotContainsString('nav-solo-movil', $this->enlaceMas($html, 'manuales.index'));
        $this->assertStringNotContainsString('nav-solo-movil', $this->enlaceMas($html, 'mudanzas.index'));
        // Incidencias: en la computadora en "Más"; en el celular sigue como pestaña.
        $this->assertStringContainsString('nav-solo-movil', $this->enlaceBarra($html, 'incidencias.index'));
        $this->assertStringContainsString('nav-solo-escritorio', $this->enlaceMas($html, 'incidencias.index'));
        // Lo principal sigue visible en la barra.
        foreach (['wms', 'historial', 'embarques.index', 'inventarios.index', 'entregas.index'] as $ruta) {
            $this->assertNotNull($clase = $this->enlaceBarra($html, $ruta), $ruta);
            $this->assertStringNotContainsString('nav-solo-movil', $clase, $ruta);
        }
    }

    public function test_el_menu_del_operador_y_del_admin_no_cambia(): void {
        $html = $this->actingAs($this->operador())->get(route('wms'))->assertOk()->getContent();
        $this->assertStringNotContainsString('nav-solo-movil', $this->enlaceBarra($html, 'incidencias.index'));
        $this->assertNotNull($this->enlaceBarra($html, 'manuales.index'));
        $this->assertNull($this->enlaceMas($html, 'incidencias.index'));

        $html = $this->actingAs($this->admin())->get(route('wms'))->assertOk()->getContent();
        $this->assertStringNotContainsString('nav-solo-movil', $this->enlaceBarra($html, 'incidencias.index'));
        $this->assertNull($this->enlaceMas($html, 'incidencias.index'));
        $this->assertNotNull($this->enlaceMas($html, 'mudanzas.index'));
    }

    public function test_fotos_del_ns_visible_con_y_sin_sku_activo(): void {
        $op = $this->operador();
        $tarima = $this->tarima($op);

        // Sin SKU activo: el botón está habilitado y explica que también lee el SKU.
        $html = $this->actingAs($op)->get(route('wms'))->assertOk()->getContent();
        $this->assertStringContainsString('Fotos del N/S', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-captura-foto(?![^>]*disabled)[^>]*>/s', $html);
        $this->assertStringContainsString('para leer el SKU y la N/S', $html);

        $this->activarPadre($op, $tarima);
        $html = $this->get(route('wms'))->assertOk()->getContent();
        $this->assertStringContainsString('Fotos del N/S', $html);
        $this->assertStringContainsString('Toma una foto de la etiqueta para leer la N/S', $html);
    }

    public function test_sugiere_placas_cajas_y_choferes_registrados_sin_repetidos(): void {
        $auditor = $this->auditor();
        $this->actingAs($auditor)->post(route('embarques.store'), ['placas' => 'abc-123', 'remolque' => 'caja 53', 'chofer' => 'Juan  Pérez'])->assertSessionHasNoErrors();
        // Otra escritura de los mismos datos: se guarda igual que la primera.
        $this->post(route('embarques.store'), ['placas' => ' ABC-123 ', 'remolque' => 'CAJA  53', 'chofer' => 'juan pérez'])->assertSessionHasNoErrors();
        // Un valor nuevo se acepta sin restricción.
        $this->post(route('embarques.store'), ['placas' => 'XYZ-999', 'chofer' => 'Ana Ruiz'])->assertSessionHasNoErrors();

        $this->assertSame(['ABC-123', 'ABC-123', 'XYZ-999'], Embarque::orderBy('id')->pluck('placas')->all());
        $this->assertSame(['CAJA 53', 'CAJA 53', null], Embarque::orderBy('id')->pluck('remolque')->all());
        $this->assertSame(['Juan Pérez', 'Juan Pérez', 'Ana Ruiz'], Embarque::orderBy('id')->pluck('chofer')->all());

        $html = $this->get(route('embarques.index'))->assertOk()->getContent();
        $this->assertStringContainsString('list="sug-placas"', $html);
        $this->assertStringContainsString('list="sug-remolque"', $html);
        $this->assertStringContainsString('list="sug-chofer"', $html);
        // Una vez cada uno, el más reciente primero.
        preg_match('#<datalist id="sug-placas">(.*?)</datalist>#s', $html, $m);
        $this->assertSame(['XYZ-999', 'ABC-123'], preg_match_all('/<option value="([^"]+)"/', $m[1], $o) ? $o[1] : []);
        $this->assertSame(1, substr_count($html, '<option value="Juan Pérez">'));

        // El detalle sigue abriendo (la salida, con su chofer sugerido, aparece al tener piezas cargadas).
        $this->get(route('embarques.show', Embarque::latest('id')->first()))->assertOk();
    }

    public function test_sin_tráileres_previos_no_hay_sugerencias(): void {
        $html = $this->actingAs($this->auditor())->get(route('embarques.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="placas"', $html);
        $this->assertStringNotContainsString('<datalist', $html);
        $this->assertStringNotContainsString('list="sug-', $html);
    }
}
