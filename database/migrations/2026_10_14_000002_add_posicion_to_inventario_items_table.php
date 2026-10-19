<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Posición de ubicación" de cada renglón del Excel de inventario. Va en la
     * columna "Categoría" del manifiesto. Columna nula: los inventarios ya
     * cargados la tienen vacía hasta que se vuelvan a cargar con esa columna.
     */
    public function up(): void
    {
        Schema::table('inventario_items', function (Blueprint $table) {
            $table->string('posicion', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventario_items', function (Blueprint $table) {
            $table->dropColumn('posicion');
        });
    }
};
