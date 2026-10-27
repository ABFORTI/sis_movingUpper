<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "¿Es demo?" del SKU (App\Enums\Demo): se elige al activar el SKU y la
     * partida lo copia al guardar, como el SKU y la descripción. 'Na' = No
     * aplica, que es lo que quedan todos los registros anteriores.
     */
    public function up(): void
    {
        Schema::table('registros', function (Blueprint $table) {
            $table->string('demo', 10)->default('Na')->after('descripcion');
        });
        Schema::table('partidas', function (Blueprint $table) {
            $table->string('demo', 10)->default('Na')->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::table('registros', function (Blueprint $table) {
            $table->dropColumn('demo');
        });
        Schema::table('partidas', function (Blueprint $table) {
            $table->dropColumn('demo');
        });
    }
};
