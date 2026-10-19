<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una mudanza puede tener varios movimientos entre almacenes (58 → 3 y
     * 5 → 7 en la misma mudanza). Cada tarima pertenece a un movimiento: su
     * almacén de origen decide contra qué inventario se validan sus piezas.
     *
     * Los textos `almacen_origen` / `almacen_destino` de cada mudanza se
     * convierten en su primer movimiento (sus tarimas quedan en él) antes de
     * quitar esas columnas. down() las restaura desde ese movimiento.
     */
    public function up(): void
    {
        Schema::create('almacenes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('mudanza_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mudanza_id')->constrained('mudanzas')->restrictOnDelete();
            $table->foreignId('almacen_origen_id')->constrained('almacenes')->restrictOnDelete();
            $table->foreignId('almacen_destino_id')->constrained('almacenes')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['mudanza_id', 'almacen_origen_id', 'almacen_destino_id'], 'mudanza_movimiento_unico');
        });

        Schema::table('tarimas', function (Blueprint $table) {
            $table->foreignId('mudanza_movimiento_id')->nullable()->after('mudanza_id')
                ->constrained('mudanza_movimientos')->restrictOnDelete();
        });

        Schema::table('embarques', function (Blueprint $table) {
            $table->foreignId('mudanza_movimiento_id')->nullable()->after('mudanza_id')
                ->constrained('mudanza_movimientos')->restrictOnDelete();
        });

        $this->migrarAlmacenesDeTexto();

        Schema::table('mudanzas', function (Blueprint $table) {
            $table->dropColumn(['almacen_origen', 'almacen_destino']);
        });
    }

    private function migrarAlmacenesDeTexto(): void
    {
        DB::transaction(function () {
            foreach (DB::table('mudanzas')->orderBy('id')->get(['id', 'almacen_origen', 'almacen_destino']) as $mudanza) {
                $origen = trim((string) $mudanza->almacen_origen);
                $destino = trim((string) $mudanza->almacen_destino);
                if ($origen === '' || $destino === '') {
                    continue;
                }

                $movimiento = DB::table('mudanza_movimientos')->insertGetId([
                    'mudanza_id' => $mudanza->id,
                    'almacen_origen_id' => $this->almacen($origen),
                    'almacen_destino_id' => $this->almacen($destino),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('tarimas')->where('mudanza_id', $mudanza->id)->update(['mudanza_movimiento_id' => $movimiento]);
                DB::table('embarques')->where('mudanza_id', $mudanza->id)->update(['mudanza_movimiento_id' => $movimiento]);
            }
        });
    }

    private function almacen(string $texto): int
    {
        $codigo = mb_substr($texto, 0, 50);
        $existente = DB::table('almacenes')->where('codigo', $codigo)->value('id');

        return $existente ?? DB::table('almacenes')->insertGetId([
            'codigo' => $codigo,
            'nombre' => mb_strlen($texto) > 50 ? mb_substr($texto, 0, 150) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('mudanzas', function (Blueprint $table) {
            $table->string('almacen_origen', 150)->nullable()->after('cliente');
            $table->string('almacen_destino', 150)->nullable()->after('almacen_origen');
        });

        foreach (DB::table('mudanza_movimientos')->orderBy('id')->get() as $movimiento) {
            DB::table('mudanzas')->where('id', $movimiento->mudanza_id)->whereNull('almacen_origen')->update([
                'almacen_origen' => DB::table('almacenes')->where('id', $movimiento->almacen_origen_id)->value('codigo'),
                'almacen_destino' => DB::table('almacenes')->where('id', $movimiento->almacen_destino_id)->value('codigo'),
            ]);
        }

        Schema::table('embarques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mudanza_movimiento_id');
        });
        Schema::table('tarimas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mudanza_movimiento_id');
        });
        Schema::dropIfExists('mudanza_movimientos');
        Schema::dropIfExists('almacenes');
    }
};
