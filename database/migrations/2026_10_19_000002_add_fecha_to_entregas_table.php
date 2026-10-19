<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reporte Diario: cada entrega es el trabajo de un día (las piezas
     * registradas esa fecha). Las entregas anteriores, de la mudanza completa,
     * se conservan con fecha nula.
     */
    public function up(): void
    {
        Schema::table('entregas', function (Blueprint $table) {
            $table->date('fecha')->nullable()->after('version');
            $table->index(['mudanza_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::table('entregas', function (Blueprint $table) {
            $table->dropIndex(['mudanza_id', 'fecha']);
            $table->dropColumn('fecha');
        });
    }
};
