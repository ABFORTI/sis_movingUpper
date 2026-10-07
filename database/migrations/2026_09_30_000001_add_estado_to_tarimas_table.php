<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de la tarima sobre la misma fila (sin duplicar su contenido):
 * temporal (guardada_at NULL) → impresa (impresa_at) → guardada (guardada_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->timestamp('impresa_at')->nullable()->after('created_by');
            $table->timestamp('guardada_at')->nullable()->index()->after('impresa_at');
            $table->foreignId('guardada_by')->nullable()->after('guardada_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guardada_by');
            $table->dropColumn(['impresa_at', 'guardada_at']);
        });
    }
};
