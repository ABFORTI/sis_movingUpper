<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarima_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 10);
            $table->string('sku', 100);
            $table->string('descripcion')->nullable();
            $table->foreignId('destino_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            // Solo los registros vivos cuentan para la unicidad: al hacer soft delete
            // la columna queda en NULL y el SKU se puede volver a escanear.
            $table->string('sku_activo', 100)
                ->storedAs('CASE WHEN deleted_at IS NULL THEN sku END')
                ->nullable();
            $table->unique(['tarima_id', 'sku_activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros');
    }
};
