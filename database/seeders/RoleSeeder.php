<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder {
    public function run(): void {
        $roles = (array) config('roles_permissions.roles', []);

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'description' => $role['description'] ?? null,
                    'is_active' => true,
                ]
            );
        }
    }
}
