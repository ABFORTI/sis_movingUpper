<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La carga ya no exige una autorización del cliente. Las tablas
     * `autorizaciones` y `autorizacion_partidas` se conservan como histórico
     * (no se borra nada); solo deja de ser obligatorio ligar cada pieza cargada
     * a una autorización. Las cargas anteriores conservan la suya.
     */
    public function up(): void
    {
        Schema::table('embarque_partidas', function (Blueprint $table) {
            $table->foreignId('autorizacion_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Solo es reversible si ninguna carga quedó sin autorización.
        Schema::table('embarque_partidas', function (Blueprint $table) {
            $table->foreignId('autorizacion_id')->nullable(false)->change();
        });
    }
};
