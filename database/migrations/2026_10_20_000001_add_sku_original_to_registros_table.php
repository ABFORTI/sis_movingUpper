<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SKU que se escaneó cuando se le aplicó una conversión del catálogo
     * (config/conversiones_sku.php): `sku` guarda el equivalente y aquí queda el
     * original. NULL = sin conversión, como todos los registros anteriores.
     */
    public function up(): void
    {
        Schema::table('registros', function (Blueprint $table) {
            $table->string('sku_original', 100)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('registros', function (Blueprint $table) {
            $table->dropColumn('sku_original');
        });
    }
};
