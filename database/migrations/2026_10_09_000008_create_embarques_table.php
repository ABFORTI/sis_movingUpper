<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carga, traslado y recepción. Cada pieza cargada guarda la autorización que
     * la habilitó; una pieza solo puede ir en un embarque (UNIQUE partida_id).
     */
    public function up(): void
    {
        Schema::create('embarques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mudanza_id')->constrained('mudanzas')->restrictOnDelete();
            $table->string('folio', 20);
            $table->string('placas', 20);
            $table->string('remolque', 30)->nullable();
            $table->string('chofer', 150)->nullable();
            $table->string('sello', 50)->nullable();
            // en_carga → en_transito → recibido
            $table->string('estado', 20)->index();
            $table->text('notas')->nullable();
            $table->foreignId('creado_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('salida_at')->nullable();
            $table->foreignId('salida_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('recibido_at')->nullable();
            $table->foreignId('recibido_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['mudanza_id', 'folio']);
        });

        Schema::create('embarque_partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('embarque_id')->constrained('embarques')->restrictOnDelete();
            $table->foreignId('partida_id')->unique()->constrained('partidas')->restrictOnDelete();
            $table->foreignId('tarima_id')->constrained('tarimas')->restrictOnDelete();
            $table->foreignId('autorizacion_id')->constrained('autorizaciones')->restrictOnDelete();
            $table->timestamp('cargada_at');
            $table->foreignId('cargada_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recibida_at')->nullable();
            $table->foreignId('recibida_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->index(['embarque_id', 'tarima_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embarque_partidas');
        Schema::dropIfExists('embarques');
    }
};
