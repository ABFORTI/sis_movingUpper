<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder{
    public function run():void{
        $admin = User::query()->updateOrCreate([
            'email' => 'desarrollo@ab-forti.com',
            ], [
                'name' => 'Administrador',
                'password' => Hash::make('admin1234'),
                'is_active' => true,
            ]);
        $admin->asignarRol(Role::ADMIN);

        $auditor = User::query()->updateOrCreate([
            'email' => 'auditor@upperlogistics.mx',
        ],[
            'name' => 'Auditor',
            'password' => Hash::make('password'),
            'is_active'=> true,
        ]);
        $auditor->asignarRol(Role::AUDITOR);

        $colaborador = User::query()->updateOrCreate([
            'email' => 'prueba@upperlogistics.mx',
        ], [
            'name' => 'Operador',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $colaborador->asignarRol(Role::OPERADOR);
    }
}
