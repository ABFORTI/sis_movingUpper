<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca de daño o anomalía que el operador pone a una N/S mientras la tarima
     * está en captura. Es un borrador: al guardar la tarima cada N/S marcada
     * abre su incidencia (que ya no se borra ni se sobrescribe).
     */
    public function up(): void
    {
        Schema::table('numeros_serie', function (Blueprint $table) {
            $table->timestamp('anomalia_at')->nullable();
            $table->foreignId('anomalia_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('anomalia_nota')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('numeros_serie', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anomalia_by');
            $table->dropColumn(['anomalia_at', 'anomalia_nota']);
        });
    }
};
