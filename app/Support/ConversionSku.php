<?php

namespace App\Support;

/**
 * Equivalencias de SKU definidas en config/conversiones_sku.php ('codigo' =>
 * 'cambio'). Toda la lógica de conversión pasa por aquí: el catálogo solo se
 * edita en ese archivo.
 *
 * La conversión se aplica una sola vez y nunca en cadena: un SKU que ya es un
 * 'cambio' no se vuelve a convertir.
 */
final class ConversionSku {
    /** El SKU que se registra en lugar de $sku, o null si $sku no está en el catálogo. */
    public static function cambio(?string $sku): ?string {
        $norm = NumeroSerieNormalizado::de($sku);
        $cambio = self::catalogo()[$norm] ?? null;

        return $cambio !== null && NumeroSerieNormalizado::de($cambio) !== $norm ? $cambio : null;
    }

    /**
     * Todas las formas del mismo producto, normalizadas: el SKU, su 'cambio' y
     * los códigos que se convierten a él. Sirve para buscarlo en el inventario,
     * que puede traerlo con el código anterior o con el nuevo.
     *
     * @return list<string>
     */
    public static function equivalentes(?string $sku): array {
        $norm = NumeroSerieNormalizado::de($sku);
        $formas = [$norm];
        foreach (self::catalogo() as $codigo => $cambio) {
            $cambio = NumeroSerieNormalizado::de($cambio);
            if ($codigo === $norm || $cambio === $norm) {
                array_push($formas, $codigo, $cambio);
            }
        }

        return array_values(array_unique($formas));
    }

    /** @return array<string, string> código normalizado => SKU equivalente tal como está escrito */
    private static function catalogo(): array {
        $catalogo = [];
        foreach ((array) config('conversiones_sku', []) as $codigo => $cambio) {
            $codigo = NumeroSerieNormalizado::de((string) $codigo);
            $cambio = NumeroSerieNormalizado::limpio((string) $cambio);
            if ($codigo !== '' && $cambio !== '') {
                $catalogo[$codigo] = $cambio;
            }
        }

        return $catalogo;
    }
}
