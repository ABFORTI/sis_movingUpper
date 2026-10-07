<?php

namespace Tests\Feature\Concerns;

use App\Models\Destino;
use App\Models\Role;
use App\Models\Tarima;
use App\Models\User;
use Database\Seeders\DestinoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

trait PreparaWms {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();
        $this->seed([RoleSeeder::class, DestinoSeeder::class]);
    }

    protected function admin(): User {
        return User::factory()->conRol(Role::ADMIN)->create();
    }

    protected function operador(): User {
        return User::factory()->conRol(Role::OPERADOR)->create();
    }

    protected function auditor(): User {
        return User::factory()->conRol(Role::AUDITOR)->create();
    }

    protected function tarima(?User $creador = null): Tarima {
        return Tarima::create(['created_by' => $creador?->id]);
    }

    protected function destino(): Destino {
        return Destino::first();
    }

    protected function activarPadre(User $usuario, Tarima $tarima, string $sku = '2364F'): void {
        $this->actingAs($usuario)->post(route('escaneo.padre'), [
            'sku' => $sku,
            'descripcion' => 'Bomba hidráulica',
            'destino_id' => $this->destino()->id,
            'tarima' => $tarima->uuid,
        ])->assertSessionHasNoErrors();
    }
}
