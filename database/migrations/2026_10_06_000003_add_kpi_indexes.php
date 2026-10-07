<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para la API de KPIs (docs/kpi-api/sistemas/movingupper.md): sus
 * consultas filtran registros vivos (deleted_at IS NULL) por rango de created_at.
 * tarimas.guardada_at ya tiene índice e impresiones.created_at nace con el suyo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('numeros_serie', function (Blueprint $table) {
            $table->index(['deleted_at', 'created_at']);
        });

        Schema::table('registros', function (Blueprint $table) {
            $table->index(['deleted_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('numeros_serie', function (Blueprint $table) {
            $table->dropIndex(['deleted_at', 'created_at']);
        });

        Schema::table('registros', function (Blueprint $table) {
            $table->dropIndex(['deleted_at', 'created_at']);
        });
    }
};
