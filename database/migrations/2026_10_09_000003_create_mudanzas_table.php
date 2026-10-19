<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Debe coincidir con Mudanza::CONTADOR_FOLIO y TarimaService::claveNumeracion(). */
    private const CONTADOR_FOLIO = 'folio_mudanzas';

    public function up(): void
    {
        Schema::create('mudanzas', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 20)->unique();
            $table->string('cliente', 150)->nullable();
            $table->string('almacen_origen', 150)->nullable();
            $table->string('almacen_destino', 150)->nullable();
            $table->string('notas', 500)->nullable();
            $table->string('estado', 20)->index();
            $table->timestamp('activada_at')->nullable();
            $table->foreignId('activada_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('cerrada_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Una sola mudanza en curso a la vez, garantizado por la base de datos
            // (mismo patrón que registros.sku_activo). Para operar varias mudanzas
            // a la vez basta con quitar este índice.
            $table->unsignedTinyInteger('en_curso')
                ->storedAs("CASE WHEN estado IN ('activa', 'suspendida', 'en_cierre') THEN 1 END")
                ->nullable();
            $table->unique('en_curso');
        });

        Schema::table('tarimas', function (Blueprint $table) {
            $table->foreignId('mudanza_id')->nullable()->after('uuid')->constrained('mudanzas')->restrictOnDelete();
            $table->unsignedInteger('numero')->nullable()->after('mudanza_id');
        });

        Schema::table('bitacora', function (Blueprint $table) {
            $table->foreignId('mudanza_id')->nullable()->after('id')->constrained('mudanzas')->nullOnDelete();
        });

        $this->asignarMudanzaInicial();

        Schema::table('tarimas', function (Blueprint $table) {
            $table->foreignId('mudanza_id')->nullable(false)->change();
            $table->unsignedInteger('numero')->nullable(false)->change();
            $table->unique(['mudanza_id', 'numero']);
        });
    }

    /**
     * Las tarimas existentes pasan a MUD-0001 conservando su número actual
     * (numero = id), así las etiquetas ya impresas siguen siendo válidas. La
     * mudanza queda activa para no interrumpir la operación; el administrador
     * completa cliente y almacenes después.
     */
    public function asignarMudanzaInicial(): void
    {
        if (! DB::table('tarimas')->whereNull('mudanza_id')->exists()) {
            return;
        }

        DB::transaction(function () {
            DB::table('contadores')->insertOrIgnore([
                'clave' => self::CONTADOR_FOLIO, 'valor' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $folio = (int) DB::table('contadores')->where('clave', self::CONTADOR_FOLIO)->lockForUpdate()->value('valor') + 1;
            DB::table('contadores')->where('clave', self::CONTADOR_FOLIO)->update(['valor' => $folio, 'updated_at' => now()]);

            $mudanzaId = DB::table('mudanzas')->insertGetId([
                'folio' => sprintf('MUD-%04d', $folio),
                'estado' => 'activa',
                'notas' => 'Creada automáticamente con las tarimas registradas antes del control de mudanzas.',
                'activada_at' => DB::table('tarimas')->min('created_at') ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('tarimas')->whereNull('mudanza_id')->update([
                'mudanza_id' => $mudanzaId,
                'numero' => DB::raw('id'),
            ]);

            DB::table('contadores')->insertOrIgnore([
                'clave' => "tarimas_mudanza_{$mudanzaId}",
                'valor' => (int) DB::table('tarimas')->where('mudanza_id', $mudanzaId)->max('numero'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tarimas', function (Blueprint $table) {
            $table->dropUnique(['mudanza_id', 'numero']);
            $table->dropConstrainedForeignId('mudanza_id');
            $table->dropColumn('numero');
        });

        Schema::table('bitacora', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mudanza_id');
        });

        Schema::dropIfExists('mudanzas');
        DB::table('contadores')->where('clave', self::CONTADOR_FOLIO)->orWhere('clave', 'like', 'tarimas_mudanza_%')->delete();
    }
};
