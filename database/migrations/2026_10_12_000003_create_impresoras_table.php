<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de impresoras Zebra identificadas por su MAC Bluetooth. Cada
     * handheld elige la suya; al imprimir se busca esa MAC entre las impresoras
     * que ve Zebra Browser Print (Bluetooth Clásico) y, en Chrome directo (BLE),
     * el selector se filtra por su nombre Bluetooth.
     */
    public function up(): void
    {
        Schema::create('impresoras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->char('mac', 17)->unique();          // AA:BB:CC:DD:EE:FF
            $table->string('nombre_bluetooth', 100)->nullable();
            $table->string('ubicacion', 150)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impresoras');
    }
};
