<?php

namespace Database\Seeders;

use App\Models\Destino;
use Illuminate\Database\Seeder;

class DestinoSeeder extends Seeder
{
    public function run(): void
    {
        $destinos = [
            'PROD-A' => 'Tornillería',
            'PROD-B' => 'Herramienta manual',
            'PROD-C' => 'Plomería',
            'PROD-D' => 'Eléctrico',
        ];

        foreach ($destinos as $codigo => $nombre) {
            Destino::query()->updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'is_active' => true]);
        }
    }
}
