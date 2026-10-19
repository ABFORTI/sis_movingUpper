<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada pieza lleva su propia etiqueta (modelo, número de embarque y código
     * de barras de su N/S). Esta marca dice qué piezas ya la tienen, para que al
     * seguir escaneando solo se impriman las nuevas y no se dupliquen etiquetas
     * pegadas. Solo se agrega una columna nula: no se toca ningún dato.
     */
    public function up(): void
    {
        Schema::table('numeros_serie', function (Blueprint $table) {
            $table->timestamp('etiqueta_impresa_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('numeros_serie', function (Blueprint $table) {
            $table->dropColumn('etiqueta_impresa_at');
        });
    }
};
