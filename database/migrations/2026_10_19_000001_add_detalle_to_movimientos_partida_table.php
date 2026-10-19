<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correcciones de N/S desde el Historial (agregar, editar, eliminar): el
     * movimiento guarda qué cambió ("SKU A → B · N/S X → Y") además del motivo,
     * para saber qué ocurrió con cada pieza sin depender de la bitácora.
     */
    public function up(): void
    {
        Schema::table('movimientos_partida', function (Blueprint $table) {
            $table->string('detalle', 255)->nullable()->after('motivo');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_partida', function (Blueprint $table) {
            $table->dropColumn('detalle');
        });
    }
};
