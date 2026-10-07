<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numeros_serie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_id')->constrained()->cascadeOnDelete();
            $table->string('numero_serie', 150);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            // N/S único en todo el sistema entre los registros vivos.
            $table->string('numero_serie_activo', 150)
                ->storedAs('CASE WHEN deleted_at IS NULL THEN numero_serie END')
                ->nullable()
                ->unique();
            $table->index('numero_serie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numeros_serie');
    }
};
