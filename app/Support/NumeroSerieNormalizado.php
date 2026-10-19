<?php

namespace App\Support;

/**
 * Forma comparable de un número de serie o SKU. Solo quita diferencias que no
 * cambian su identidad:
 *
 * - caracteres de control (el Enter o Tab que agrega el escáner) e invisibles
 *   (espacio de ancho cero, BOM);
 * - espacios al inicio y al final (incluido el espacio no separable de Excel);
 * - mayúsculas/minúsculas: el teclado del handheld captura en mayúsculas y
 *   "sn-01" y "SN-01" son la misma etiqueta.
 *
 * No toca lo demás: los ceros a la izquierda, guiones, puntos, barras y
 * espacios intermedios se conservan, y nunca se convierte a número. Así
 * "00123" no coincide con "123" ni "SN 01" con "SN01".
 */
final class NumeroSerieNormalizado {
    public static function de(?string $valor): string {
        $valor = (string) $valor;
        $valor = (string) preg_replace('/[\x{00}-\x{1F}\x{7F}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $valor);
        $valor = (string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $valor);

        return mb_strtoupper($valor, 'UTF-8');
    }

    /** El valor tal como se guarda (sin control ni espacios sobrantes), conservando mayúsculas y minúsculas. */
    public static function limpio(?string $valor): string {
        $valor = (string) preg_replace('/[\x{00}-\x{1F}\x{7F}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', (string) $valor);

        return (string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $valor);
    }
}
