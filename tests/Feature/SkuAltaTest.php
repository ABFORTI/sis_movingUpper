<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Incidencia;
use App\Models\InventarioItem;
use App\Models\NumeroSerie;
use App\Models\Partida;
use App\Models\Registro;
use App\Models\SkuAlta;
use App\Services\Inventario\InventarioService;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** Alta de un SKU solo con su código, para productos que aún no vienen en el inventario de Excel. */
class SkuAltaTest extends TestCase {
    use PreparaWms;

    private function alta(string $sku, ?string $descripcion = null, ?string $almacen = null) {
        return $this->post(route('inventarios.skus.store'), [
            'almacen' => $this->almacen($almacen ?? $this->almacenOrigen)->id, 'sku' => $sku, 'descripcion' => $descripcion,
        ]);
    }

    public function test_se_da_de_alta_solo_con_el_sku_sin_crear_ns_ni_inventario(): void {
        $this->enInventario([['sku' => 'REF-100', 'numero_serie' => 'NS-1']]);
        $operador = $this->operador();

        $this->actingAs($operador)->get(route('inventarios.index'))->assertOk()->assertSee('Dar de alta un SKU');
        $this->alta('  nuevo-77 ')->assertRedirect()->assertSessionHasNoErrors();

        $alta = SkuAlta::sole();
        $this->assertSame(['nuevo-77', 'NUEVO-77', null], [$alta->sku, $alta->sku_norm, $alta->descripcion]);
        $this->assertSame(1, InventarioItem::count(), 'No se crean renglones de inventario.');
        $this->assertSame(0, NumeroSerie::count() + Registro::count() + Partida::count(), 'Ni N/S, ni registros, ni piezas.');
        $this->assertSame(1, Bitacora::where('accion', 'sku.alta')->count());
        $this->get(route('inventarios.index'))->assertSee('1 SKU dado de alta sin N/S')->assertSee('nuevo-77');
    }

    public function test_rechaza_duplicados_del_alta_y_del_inventario(): void {
        $this->enInventario([['sku' => 'REF-100', 'numero_serie' => 'NS-1']]);
        $this->actingAs($this->operador());

        $this->alta('ref-100')->assertSessionHasErrorsIn('altaSku', 'sku');
        $this->alta('NUEVO-1')->assertSessionHasNoErrors();
        $this->alta(' nuevo-1')->assertSessionHasErrorsIn('altaSku', ['sku' => 'El SKU NUEVO-1 ya se dio de alta en el Almacén 58.']);
        $this->alta('')->assertSessionHasErrorsIn('altaSku', 'sku');
        $this->assertSame(1, SkuAlta::count());

        // Otro almacén tiene su propio catálogo.
        $this->alta('NUEVO-1', almacen: '3')->assertSessionHasNoErrors();
        $this->assertSame(2, SkuAlta::count());
    }

    public function test_el_sku_dado_de_alta_se_usa_en_el_escaneo_y_sus_ns_quedan_en_revision(): void {
        $this->enInventario([['sku' => 'REF-100', 'descripcion' => 'Refrigerador', 'numero_serie' => 'NS-1']]);
        $operador = $this->operador();
        $this->actingAs($operador);
        $this->alta('COMP-160', 'Compresor 160 L')->assertSessionHasNoErrors();
        $tarima = $this->tarima($operador);

        // Aparece en el selector junto con los del inventario.
        $opciones = $this->getJson(route('escaneo.skus', ['tarima' => $tarima->uuid]))->assertOk()->json('opciones');
        $this->assertSame([
            ['sku' => 'COMP-160', 'modelo' => null, 'descripcion' => 'Compresor 160 L', 'piezas' => 0, 'alta' => true],
            ['sku' => 'REF-100', 'modelo' => null, 'descripcion' => 'Refrigerador', 'piezas' => 1, 'alta' => false],
        ], $opciones);
        $this->assertSame(['COMP-160'], array_column($this->getJson(route('escaneo.skus', ['tarima' => $tarima->uuid, 'q' => 'compre']))->json('opciones'), 'sku'));

        // Se activa y se escanean sus N/S: sin coincidencia en el inventario → pendiente de revisión.
        $this->post(route('escaneo.padre'), ['sku' => 'comp-160', 'tarima' => $tarima->uuid])->assertSessionHasNoErrors();
        $this->assertSame(['COMP-160', 'Compresor 160 L'], [Registro::sole()->sku, Registro::sole()->descripcion]);
        $this->postJson(route('escaneo.ns'), ['numero_serie' => 'CP-1'])->assertOk()->assertJson(['estado' => 'registrada']);
        $this->assertTrue(NumeroSerie::sole()->pendienteRevision());
        $this->assertSame(1, Incidencia::where('tipo', Incidencia::NO_EN_INVENTARIO)->count());
        $this->assertNull(NumeroSerie::sole()->inventario_item_id);
    }

    public function test_si_luego_llega_en_el_excel_manda_el_inventario(): void {
        $carga = $this->enInventario([['sku' => 'REF-100', 'numero_serie' => 'NS-1']]);
        $this->actingAs($this->operador());
        $this->alta('COMP-160', 'Descripción a mano')->assertSessionHasNoErrors();
        $this->enInventario([['sku' => 'COMP-160', 'descripcion' => 'Del Excel', 'numero_serie' => 'CP-9']]);

        $inventario = app(InventarioService::class);
        $this->assertSame(['sku' => 'COMP-160', 'modelo' => null, 'descripcion' => 'Del Excel', 'alta' => false], $inventario->sku($carga, 'COMP-160'));
        $this->assertSame([false, false], $inventario->buscarSkus($carga, null)->pluck('alta')->all(), 'Sin duplicar el SKU en el selector.');
    }

    public function test_un_sku_que_no_existe_en_ninguno_sigue_rechazandose(): void {
        $this->enInventario([['sku' => 'REF-100', 'numero_serie' => 'NS-1']]);
        $operador = $this->operador();
        $tarima = $this->tarima($operador);

        $this->actingAs($operador)->post(route('escaneo.padre'), ['sku' => 'NO-EXISTE', 'tarima' => $tarima->uuid])->assertSessionHasErrors('sku');
        $this->assertSame(0, Registro::count());
    }
}
