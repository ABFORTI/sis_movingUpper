<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Impresora;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

/** Catálogo de impresoras Zebra por MAC Bluetooth. */
class ImpresoraTest extends TestCase {
    use PreparaWms;

    private function datos(array $extra = []): array {
        return $extra + ['nombre' => 'ZQ620 Andén 1', 'mac' => 'ac-3f-a4-cd-c5-15', 'nombre_bluetooth' => 'XXZKJ203401234', 'activa' => '1'];
    }

    public function test_admin_registra_y_la_mac_se_normaliza(): void {
        $this->actingAs($this->admin())->post(route('impresoras.store'), $this->datos())
            ->assertRedirect(route('impresoras.index'))->assertSessionHasNoErrors();

        $impresora = Impresora::sole();
        $this->assertSame('AC:3F:A4:CD:C5:15', $impresora->mac);
        $this->assertTrue($impresora->activa);
        $this->assertSame(1, Bitacora::where('accion', 'impresora.creada')->count());

        $this->get(route('impresoras.index'))->assertOk()->assertSee('AC:3F:A4:CD:C5:15')->assertSee('XXZKJ203401234');
    }

    public function test_mac_invalida_o_repetida(): void {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('impresoras.store'), $this->datos(['mac' => 'AC3FA4CDC5']))->assertSessionHasErrors('mac');
        $this->actingAs($admin)->post(route('impresoras.store'), $this->datos(['mac' => 'GG:3F:A4:CD:C5:15']))->assertSessionHasErrors('mac');
        $this->actingAs($admin)->post(route('impresoras.store'), $this->datos())->assertSessionHasNoErrors();
        // La misma MAC escrita de otra forma es la misma impresora.
        $this->actingAs($admin)->post(route('impresoras.store'), $this->datos(['nombre' => 'Otra', 'mac' => 'AC3FA4CDC515']))
            ->assertSessionHasErrors(['mac' => 'Esa MAC ya está registrada.']);

        $this->assertSame(1, Impresora::count());
    }

    public function test_editar_y_desactivar_sin_borrar(): void {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('impresoras.store'), $this->datos());
        $impresora = Impresora::sole();

        $this->put(route('impresoras.update', $impresora), $this->datos(['activa' => '0', 'mac' => 'AC:3F:A4:CD:C5:15']))->assertSessionHasNoErrors();
        $this->assertFalse($impresora->fresh()->activa);
        $this->assertSame(1, Impresora::count());
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('impresoras.destroy'));
    }

    public function test_solo_admin_administra(): void {
        foreach ([$this->operador(), $this->auditor()] as $usuario) {
            $this->actingAs($usuario)->get(route('impresoras.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('impresoras.store'), $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Impresora::count());
    }

    public function test_el_panel_de_impresion_ofrece_solo_las_activas(): void {
        Impresora::create(['nombre' => 'ZQ620 Andén 1', 'mac' => 'AC3FA4CDC515', 'nombre_bluetooth' => 'XXZKJ-1', 'activa' => true]);
        Impresora::create(['nombre' => 'ZQ620 vieja', 'mac' => '001122334455', 'activa' => false]);

        $this->actingAs($this->operador())->get(route('impresion.prueba'))->assertOk()
            ->assertSee('id="impresoraEquipo"', false)
            ->assertSee('value="AC:3F:A4:CD:C5:15"', false)
            ->assertSee('data-nombre-bluetooth="XXZKJ-1"', false)
            ->assertDontSee('ZQ620 vieja')
            ->assertSee('id="impresorasDetectadas"', false);
    }

    public function test_sin_impresoras_registradas_el_panel_lo_indica(): void {
        $this->actingAs($this->operador())->get(route('impresion.prueba'))->assertOk()
            ->assertSee('pide a un administrador que registre la ZQ620');
        $this->actingAs($this->admin())->get(route('impresion.prueba'))->assertOk()
            ->assertSee(route('impresoras.create'), false);
    }
}
