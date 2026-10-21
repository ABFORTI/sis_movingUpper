<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;


class Contador {
    public static function reservar(string $clave, int $cantidad = 1): int {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("El contador {$clave} solo se puede usar dentro de una transacción.");
        }

        DB::table('contadores')->insertOrIgnore([
            'clave' => $clave, 'valor' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ultimo = (int) DB::table('contadores')->where('clave', $clave)->lockForUpdate()->value('valor');

        DB::table('contadores')->where('clave', $clave)->update(['valor' => $ultimo + $cantidad, 'updated_at' => now()]);

        return $ultimo + 1;
    }

    public static function siguiente(string $clave): int {
        return self::reservar($clave, 1);
    }

    /**
     *
     * @return bool true si el número se podrá reutilizar
     */
    public static function liberarUltimo(string $clave, int $numero): bool {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("El contador {$clave} solo se puede usar dentro de una transacción.");
        }

        $ultimo = DB::table('contadores')->where('clave', $clave)->lockForUpdate()->value('valor');
        if ($ultimo === null || (int) $ultimo !== $numero) {
            return false;
        }

        DB::table('contadores')->where('clave', $clave)->update(['valor' => $numero - 1, 'updated_at' => now()]);

        return true;
    }
}
