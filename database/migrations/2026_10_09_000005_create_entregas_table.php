<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entrega = un Excel de inventario que se le dio al cliente. Es inmutable:
     * el archivo se guarda tal cual con su SHA-256 y cada pieza incluida queda
     * copiada en entrega_partidas, así se puede demostrar qué se entregó en cada
     * versión aunque después se corrijan tarimas.
     */
    public function up(): void
    {
        Schema::create('entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mudanza_id')->constrained('mudanzas')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('archivo');
            $table->char('sha256', 64);
            $table->unsignedInteger('tamano');
            $table->unsignedInteger('total_partidas');
            $table->unsignedInteger('nuevas')->default(0);
            $table->unsignedInteger('cambiadas')->default(0);
            $table->unsignedInteger('salieron')->default(0);
            $table->string('notas', 500)->nullable();
            $table->foreignId('generada_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['mudanza_id', 'version']);
        });

        Schema::create('entrega_partidas', function (Blueprint $table) {
            $table->foreignId('entrega_id')->constrained('entregas')->restrictOnDelete();
            $table->foreignId('partida_id')->constrained('partidas')->restrictOnDelete();
            $table->unsignedBigInteger('consecutivo');
            $table->unsignedInteger('tarima_numero');
            $table->string('sku', 100);
            $table->string('descripcion')->nullable();
            $table->string('numero_serie', 150);
            $table->string('categoria', 30);
            $table->timestamp('registrado_at')->nullable();
            // nueva / tarima (cambió de tarima) / igual, respecto de la versión anterior
            $table->string('novedad', 10);

            $table->primary(['entrega_id', 'partida_id']);
            $table->index('partida_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrega_partidas');
        Schema::dropIfExists('entregas');
    }
};
