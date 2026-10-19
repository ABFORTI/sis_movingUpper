<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de acciones de negocio (anular, vaciar, exportar…). Solo se inserta;
     * el modelo Bitacora impide modificar o borrar filas.
     */
    public function up(): void
    {
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('accion', 60)->index();
            $table->string('entidad_type', 60)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos')->nullable();
            $table->string('motivo', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['entidad_type', 'entidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
    }
};
