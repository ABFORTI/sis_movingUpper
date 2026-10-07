<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\Feature\Concerns\PreparaWms;
use Tests\TestCase;

class AutenticacionTest extends TestCase {
    use PreparaWms;

    public function test_invitado_es_redirigido_al_login(): void {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Iniciar sesión');
    }

    public function test_usuario_activo_inicia_sesion(): void {
        $usuario = $this->operador();

        $this->post(route('login'), ['email' => $usuario->email, 'password' => 'password'])
            ->assertRedirect(route('wms'));

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_contrasena_incorrecta_no_entra(): void {
        $usuario = $this->operador();

        $this->post(route('login'), ['email' => $usuario->email, 'password' => 'mala'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_inactivo_no_entra(): void {
        $usuario = User::factory()->inactivo()->conRol(Role::OPERADOR)->create();

        $this->post(route('login'), ['email' => $usuario->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_desactivado_con_sesion_abierta_es_expulsado(): void {
        $usuario = $this->operador();
        $usuario->update(['is_active' => false]);

        $this->actingAs($usuario)->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_bloqueo_tras_cinco_intentos(): void {
        $usuario = $this->operador();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $usuario->email, 'password' => 'mala']);
        }

        $this->post(route('login'), ['email' => $usuario->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_solo_admin_administra_usuarios(): void {
        $this->actingAs($this->operador())->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($this->operador())->post(route('usuarios.store'), [])->assertForbidden();

        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Nuevo',
            'email' => 'nuevo@upperlogistics.mx',
            'password' => 'clave1234',
            'password_confirmation' => 'clave1234',
            'rol' => Role::OPERADOR,
            'is_active' => 1,
        ])->assertRedirect(route('usuarios.index'));

        $nuevo = User::where('email', 'nuevo@upperlogistics.mx')->firstOrFail();
        $this->assertTrue($nuevo->hasRole(Role::OPERADOR));
    }

    public function test_admin_asigna_el_rol_auditor_y_el_auditor_no_administra_usuarios(): void {
        $this->actingAs($this->admin())->post(route('usuarios.store'), [
            'name' => 'Auditora',
            'email' => 'auditora@upperlogistics.mx',
            'password' => 'clave1234',
            'password_confirmation' => 'clave1234',
            'rol' => Role::AUDITOR,
            'is_active' => 1,
        ])->assertRedirect(route('usuarios.index'));

        $auditor = User::where('email', 'auditora@upperlogistics.mx')->firstOrFail();
        $this->assertTrue($auditor->hasRole(Role::AUDITOR));
        $this->assertFalse($auditor->isAdmin());

        $this->actingAs($auditor)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($auditor)->post(route('usuarios.store'), [])->assertForbidden();
    }

    public function test_admin_no_puede_quitarse_su_rol(): void {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('usuarios.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'rol' => Role::OPERADOR,
            'is_active' => 1,
        ])->assertSessionHasErrors('rol');

        $this->assertTrue($admin->fresh()->isAdmin());
    }
}
