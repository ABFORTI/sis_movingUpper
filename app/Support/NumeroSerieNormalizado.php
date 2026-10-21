<?php

namespace App\Support;


final class NumeroSerieNormalizado {
    public static function de(?string $valor): string {
        $valor = (string) $valor;
        $valor = (string) preg_replace('/[\x{00}-\x{1F}\x{7F}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $valor);
        $valor = (string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $valor);

        return mb_strtoupper($valor, 'UTF-8');
    }


    public static function limpio(?string $valor): string {
        $valor = (string) preg_replace('/[\x{00}-\x{1F}\x{7F}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', (string) $valor);

        return (string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $valor);
    }
}
