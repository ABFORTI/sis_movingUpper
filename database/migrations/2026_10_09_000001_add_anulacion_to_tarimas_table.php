<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las tarimas ya no se borran: se anulan con motivo, usuario y fecha. Conservan
     * su número y su contenido, y el número nunca se reutiliza.
     */
    public function up(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->timestamp('anulada_at')->nullable()->index()->after('consecutivo_inicial');
            $table->foreignId('anulada_by')->nullable()->after('anulada_at')->constrained('users')->nullOnDelete();
            $table->string('motivo_anulacion', 255)->nullable()->after('anulada_by');
        });
    }

    public function down(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anulada_by');
            $table->dropColumn(['anulada_at', 'motivo_anulacion']);
        });
    }
};
