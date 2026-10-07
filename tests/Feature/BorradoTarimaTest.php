<?php

namespace Tests\Feature;

use App\Models\NumeroSerie;
use App\Models\Registro;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class BorradoTarimaTest extends TestCase {
    use PreparaWms;

    public function test_operador_vacia_con_borrado_logico_y_libera_las_ns(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-1']);

        $this->delete(route('tarimas.vaciar', $tarima))->assertRedirect(route('wms'));

        $this->assertSame(0, Registro::count());
        $this->assertSame(1, Registro::onlyTrashed()->count());
        $this->assertSame($operador->id, Registro::onlyTrashed()->first()->deleted_by);
        $this->assertSame(1, NumeroSerie::onlyTrashed()->count());

        $this->activarPadre($operador, $tarima);
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-1'])->assertSessionHasNoErrors();
    }

    public function test_admin_vacia_definitivamente_incluyendo_papelera(): void {
        $operador = $this->operador();
        $tarima = $this->tarima($operador);
        $this->activarPadre($operador, $tarima);
        $this->post(route('escaneo.ns'), ['numero_serie' => 'SN-1']);
        $this->delete(route('tarimas.vaciar', $tarima));
        $this->activarPadre($operador, $tarima);

        $this->actingAs($this->admin())->delete(route('tarimas.vaciar', $tarima));

        $this->assertSame(0, Registro::withTrashed()->count());
        $this->assertSame(0, NumeroSerie::withTrashed()->count());
    }

    public function test_solo_admin_elimina_tarimas(): void {
        $tarima = $this->tarima();

        $this->actingAs($this->operador())->delete(route('tarimas.destroy', $tarima))->assertForbidden();
        $this->assertModelExists($tarima);

        $this->actingAs($this->admin())->delete(route('tarimas.destroy', $tarima))->assertRedirect(route('wms'));
        $this->assertModelMissing($tarima);
    }
}
