<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SKU dados de alta a mano en un almacén porque todavía no vienen en su
     * inventario de Excel. Solo el código (y una descripción opcional): no son
     * renglones del inventario ni traen números de serie. Se conservan aunque
     * se reemplace el inventario del almacén.
     */
    public function up(): void
    {
        Schema::create('skus_alta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('almacen_id')->constrained('almacenes')->restrictOnDelete();
            $table->string('sku', 100);
            $table->string('sku_norm', 100);
            $table->string('descripcion', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un SKU una sola vez por almacén (sin distinguir mayúsculas ni espacios).
            $table->unique(['almacen_id', 'sku_norm']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skus_alta');
    }
};
