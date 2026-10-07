<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('visible_para', 20)->index();
            // Ruta dentro del disco privado "manuales" (nombre generado, nunca el del usuario).
            $table->string('archivo')->unique();
            $table->unsignedInteger('tamano');
            $table->foreignId('subido_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manuales');
    }
};
