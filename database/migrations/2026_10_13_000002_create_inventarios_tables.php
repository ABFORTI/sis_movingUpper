<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventario por almacén, cargado desde el Excel del cliente. Cada carga se
     * conserva (historial); solo una por almacén está vigente y es contra la que
     * se valida el escaneo. Cargar el inventario de un almacén nunca toca el de
     * otro: la carga y cada renglón llevan su almacén.
     *
     * Los tres números de serie se guardan tal cual y normalizados (*_norm:
     * sin espacios al inicio/fin ni caracteres invisibles, en mayúsculas) para
     * buscarlos por índice. Ver App\Support\NumeroSerieNormalizado.
     */
    public function up(): void
    {
        Schema::create('inventario_cargas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();
            $table->string('archivo_nombre', 200);
            // Copia del Excel original en el disco privado "documentos".
            $table->string('archivo')->nullable();
            $table->char('sha256', 64);
            $table->unsignedInteger('filas_leidas')->default(0);
            $table->unsignedInteger('filas_importadas')->default(0);
            $table->unsignedInteger('filas_omitidas')->default(0);
            $table->json('observaciones')->nullable();
            $table->boolean('vigente')->default(true);
            $table->foreignId('cargada_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reemplazada_at')->nullable();
            $table->timestamps();

            // Una sola carga vigente por almacén, garantizado por la base de datos.
            $table->unsignedBigInteger('vigente_almacen')
                ->storedAs('CASE WHEN vigente = 1 THEN almacen_id END')
                ->nullable()
                ->unique();
        });

        Schema::create('inventario_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventario_carga_id')->constrained('inventario_cargas')->restrictOnDelete();
            $table->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();
            $table->unsignedInteger('fila');
            $table->string('sku', 100);
            $table->string('sku_norm', 100);
            $table->string('modelo', 150)->nullable();
            $table->string('descripcion', 255)->nullable();
            $table->string('ns_fabricante', 150)->nullable();
            $table->string('ns_fabricante_norm', 150)->nullable();
            $table->string('numero_serie', 150)->nullable();
            $table->string('numero_serie_norm', 150)->nullable();
            $table->string('ns_lote', 150)->nullable();
            $table->string('ns_lote_norm', 150)->nullable();

            $table->index(['inventario_carga_id', 'sku_norm']);
            $table->index(['inventario_carga_id', 'ns_fabricante_norm']);
            $table->index(['inventario_carga_id', 'numero_serie_norm']);
            $table->index(['inventario_carga_id', 'ns_lote_norm']);
        });

        Schema::table('numeros_serie', function (Blueprint $table) {
            // Renglón del inventario con el que coincidió y en cuál de sus tres campos.
            $table->foreignId('inventario_item_id')->nullable()->constrained('inventario_items')->restrictOnDelete();
            $table->string('coincidencia', 20)->nullable();

            // Un renglón del inventario (un producto) se registra una sola vez entre
            // las N/S vivas, aunque se escanee otro de sus tres números.
            $table->unsignedBigInteger('inventario_item_activo')
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN inventario_item_id END')
                ->nullable()
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('numeros_serie', function (Blueprint $table) {
            $table->dropUnique(['inventario_item_activo']);
            $table->dropColumn('inventario_item_activo');
        });
        Schema::table('numeros_serie', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventario_item_id');
            $table->dropColumn('coincidencia');
        });
        Schema::dropIfExists('inventario_items');
        Schema::dropIfExists('inventario_cargas');
    }
};
