<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Incidencias: hallazgos y diferencias durante toda la operación. No detienen
     * el escaneo; una incidencia abierta que "bloquea traslado" impide cargar las
     * piezas afectadas (se aplicará en la etapa de carga).
     */
    public function up(): void
    {
        Schema::create('incidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mudanza_id')->constrained('mudanzas')->restrictOnDelete();
            $table->string('folio', 20);
            $table->string('tipo', 30)->index();
            $table->string('estado', 20)->index();
            $table->boolean('bloquea_traslado')->default(true);
            $table->string('titulo', 150);
            $table->text('descripcion')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo_resolucion', 30)->nullable();
            $table->text('resolucion')->nullable();
            $table->foreignId('creada_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelta_at')->nullable();
            $table->foreignId('resuelta_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mudanza_id', 'folio']);
        });

        Schema::create('incidencia_partida', function (Blueprint $table) {
            $table->foreignId('incidencia_id')->constrained('incidencias')->restrictOnDelete();
            $table->foreignId('partida_id')->constrained('partidas')->restrictOnDelete();
            $table->primary(['incidencia_id', 'partida_id']);
            $table->index('partida_id');
        });

        // Historial de la incidencia: solo se inserta.
        Schema::create('incidencia_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incidencia_id')->constrained('incidencias')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->text('detalle')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // Archivos que respaldan algo (fotos, PDF, correos, Excel del cliente). Con hash.
        Schema::create('evidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mudanza_id')->constrained('mudanzas')->restrictOnDelete();
            $table->morphs('evidenciable');
            $table->string('archivo');
            $table->string('nombre_original', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('tamano');
            $table->char('sha256', 64);
            $table->foreignId('subida_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias');
        Schema::dropIfExists('incidencia_eventos');
        Schema::dropIfExists('incidencia_partida');
        Schema::dropIfExists('incidencias');
    }
};
