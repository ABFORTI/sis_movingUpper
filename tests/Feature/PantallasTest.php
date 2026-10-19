<?php

namespace Tests\Feature;

use App\Models\Incidencia;
use App\Models\Role;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** Cada pantalla tocada por los cambios abre sin errores para cada rol, y no queda nada de lo retirado. */
class PantallasTest extends TestCase {
    use PreparaWms;

    public function test_las_pantallas_abren_para_cada_rol_sin_restos_de_lo_retirado(): void {
        Storage::fake('documentos');
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->escanearNs('NO-INV', agregarAlInventario: false);
        $incidencia = Incidencia::sole();
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'ABC-123']);

        foreach ([Role::ADMIN => $this->admin(), Role::AUDITOR => $this->auditor(), Role::OPERADOR => $operador] as $rol => $usuario) {
            $rutas = [
                route('wms'), route('historial'), route('inventarios.index'), route('incidencias.index'),
                route('incidencias.create'), route('incidencias.show', $incidencia), route('embarques.index'),
                route('embarques.show', \App\Models\Embarque::sole()), route('etiquetas.show', $tarima),
            ];
            if ($rol !== Role::OPERADOR) {
                $rutas = [...$rutas, route('mudanzas.index'), route('mudanzas.create'), route('entregas.index')];
            }
            if ($rol === Role::ADMIN) {
                $rutas[] = route('mudanzas.edit', $this->mudanza);
            }

            foreach ($rutas as $url) {
                $html = $this->actingAs($usuario)->get($url)->assertOk()->getContent();
                foreach (['Solo SKUs', 'Solo SKU\'s', 'Categoría', 'Producto destino', 'En investigación', 'Pendiente del cliente', 'Descartar'] as $retirado) {
                    $this->assertStringNotContainsString($retirado, $html, "«{$retirado}» sigue visible en {$url} ({$rol}).");
                }
            }
            // Inventarios en la navegación para todos; Mudanzas para admin y auditor.
            // El módulo de viajes se llama «Tráileres»: «Embarque» es la agrupación de piezas.
            $nav = $this->actingAs($usuario)->get(route('wms'))->getContent();
            $this->assertStringContainsString(route('inventarios.index'), $nav);
            $rol === Role::OPERADOR
                ? $this->assertStringNotContainsString(route('mudanzas.index'), $nav)
                : $this->assertStringContainsString(route('mudanzas.index'), $nav);
            $this->assertStringContainsString('Tráileres', $nav);
        }
    }

    /**
     * Lo que el usuario ve (textos, títulos, avisos, confirmaciones, marcadores
     * de posición) dice «embarque», nunca «tarima». Las rutas, los nombres de
     * campos y los atributos internos conservan «tarima» por compatibilidad.
     */
    public function test_el_usuario_ve_embarque_y_no_tarima(): void {
        Storage::fake('documentos');
        $operador = $this->operador();
        $guardada = $this->tarima($operador);
        $this->activarPadre($operador, $guardada);
        $this->escanearNs('SN-1');
        $this->escanearNs('SN-2');
        $this->postJson(route('tarimas.impresa', $guardada))->assertOk();
        $this->postJson(route('tarimas.guardar', $guardada))->assertOk();
        $enCaptura = $this->tarima($operador);
        $this->activarPadre($operador, $enCaptura, 'OTRO');
        $this->escanearNs('NO-INV', agregarAlInventario: false);
        $this->actingAs($this->admin())->post(route('embarques.store'), ['placas' => 'ABC-123']);
        $trailer = \App\Models\Embarque::sole();
        $this->actingAs($operador)->post(route('embarques.cargar', $trailer), ['codigo' => 'SN-1'])->assertSessionHasNoErrors();

        $admin = $this->admin();
        $rutas = [
            route('wms'), route('historial'), route('inventarios.index'), route('incidencias.index'),
            route('incidencias.create'), route('incidencias.show', Incidencia::sole()), route('embarques.index'),
            route('embarques.show', $trailer), route('etiquetas.show', $guardada), route('etiquetas.show', $enCaptura),
            route('tarimas.corregir', $guardada), route('mudanzas.index'), route('mudanzas.create'), route('entregas.index'),
            route('impresion.prueba'), app(\App\Services\TarimaService::class)->urlDetalle($guardada), route('login'),
        ];
        foreach ($rutas as $url) {
            if ($url === route('login')) {
                auth()->logout();
            } else {
                $this->actingAs($admin);
            }
            $html = $this->get($url)->assertOk()->getContent();
            $visible = preg_replace([
                '#<script\b[^>]*>.*?</script>#is',
                '#(href|action|src|formaction|class|data-[a-z-]+)="[^"]*"#i',
                '#\b(name|id|for)="[^"]*tarima[^"]*"#i',
                '#\bdata-[a-z-]*tarima[a-z-]*\b#i',
            ], '', $html);
            $this->assertDoesNotMatchRegularExpression('/tarima/i', $visible, "«tarima» sigue visible en {$url}.");
        }

        $this->actingAs($operador);
        $this->get(route('wms'))->assertSee('Nuevo embarque')->assertSee('Embarques escaneados');
        $this->get(route('etiquetas.show', $guardada))->assertSee('Etiquetas del Embarque E-001');
    }
}
