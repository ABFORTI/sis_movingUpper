<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Secuencias en la tabla `contadores`. Debe usarse dentro de una transacción:
 * el FOR UPDATE sobre la fila hace que las solicitudes simultáneas esperen su
 * turno y reciban números distintos y contiguos. Un número entregado nunca se
 * vuelve a entregar, aunque la operación que lo pidió se anule después.
 */
class Contador {
    /** Reserva $cantidad números y devuelve el primero. */
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
     * Devuelve $numero a la secuencia solo si es el último que se entregó: el
     * siguiente reservar() lo vuelve a dar. Cualquier otro número no se toca
     * (queda el hueco), así nunca se reasigna un número anterior ni se duplica.
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
