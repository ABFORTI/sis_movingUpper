<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Autorizaciones de traslado. El formato de la OTS del cliente no se conoce,
     * así que el documento se adjunta tal cual (evidencia con SHA-256) y Upper
     * indica el alcance pieza por pieza; otra persona lo valida (cuatro ojos).
     * Una autorización vigente no se edita: se revoca o se registra otra.
     */
    public function up(): void
    {
        Schema::create('autorizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mudanza_id')->constrained('mudanzas')->restrictOnDelete();
            $table->string('folio', 20);
            $table->string('documento', 100);
            $table->date('fecha_documento');
            $table->string('autorizado_por', 150);
            $table->string('autorizado_cargo', 150)->nullable();
            $table->string('estado', 20)->index();
            $table->unsignedInteger('total_partidas');
            $table->char('hash_alcance', 64);
            $table->text('notas')->nullable();
            $table->foreignId('registrada_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validada_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('validada_at')->nullable();
            $table->text('motivo_cierre')->nullable();
            $table->foreignId('cerrada_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cerrada_at')->nullable();
            $table->timestamps();

            $table->unique(['mudanza_id', 'folio']);
        });

        // Alcance: copia de cada pieza tal como se autorizó. Solo se inserta.
        Schema::create('autorizacion_partidas', function (Blueprint $table) {
            $table->foreignId('autorizacion_id')->constrained('autorizaciones')->restrictOnDelete();
            $table->foreignId('partida_id')->constrained('partidas')->restrictOnDelete();
            $table->unsignedInteger('consecutivo');
            $table->string('sku', 100);
            $table->string('numero_serie', 150)->nullable();
            $table->unsignedInteger('tarima_numero');
            $table->primary(['autorizacion_id', 'partida_id']);
            $table->index('partida_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizacion_partidas');
        Schema::dropIfExists('autorizaciones');
    }
};
