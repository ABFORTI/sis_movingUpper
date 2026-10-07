<?php

namespace App\Services\Etiquetas;

use App\Models\Registro;
use App\Models\Tarima;

/**
 * Tarimas con solo SKUs padre (Modo 1): datos a la izquierda, QR con la URL
 * firmada del detalle de N/S (la misma ruta de siempre) a la derecha y
 * código de barras de respaldo en el pie.
 *
 *  75 mm × 50 mm (600 × 400 dots)
 * ┌─────────────────────────────────────────────────┐ 0
 * │ [UPPER LOGISTICS]                  [EUROQUIP]   │ 2 mm
 * │ ═══════════════════════════════════════════════ │ 7
 * │ ███ TARIMA 12 ██████████  │ ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓     │ 7.75   bloque invertido 5 mm
 * │ SKU                       │ ▓▓            ▓▓     │ 13.5
 * │ 2364F                     │ ▓▓     QR     ▓▓     │        hasta 5.5 mm
 * │ Bomba hidráulica          │ ▓▓            ▓▓     │ 21.5   2.25 mm
 * │ DESTINO       FECHA       │ ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓     │ 24.75  rótulo 2 mm + valor 2.75 mm
 * │ PROD-A        2026-09-30  │                     │
 * │ N/S · DETALLE EN EL QR    │  QR ≈ 24.5 mm       │ 30.75
 * │ SN-000123 +33             │  (módulo 0.5 mm,    │
 * │ SKUS PADRE    TOTAL N/S   │   zona blanca de    │ 36.75
 * │ 1             34          │   3 módulos + aire) │
 * │ ║║│║║│║║│║║│║║│║║│║║      TAR-12|20260930      │ 43     Code128 5 mm
 * └─────────────────────────────────────────────────┘ 50 mm
 */
class EtiquetaPadreQr extends DisenoBase {
    /** Columna del QR, con su zona blanca y el margen alrededor incluidos. */
    private const ANCHO_QR = 34.5;

    /** Módulo del QR: 4 dots = 0.5 mm → QR de ≈ 24.5 mm (lectura segura con celular y handheld). */
    private const MODULO_QR = 4;

    public function aplicaA(Tarima $tarima): bool {
        return $tarima->cargarDetalle()->esSoloPadre();
    }

    public function paginas(Tarima $tarima): array {
        $tarima->cargarDetalle();
        $registros = $tarima->registros;
        $numerosSerie = $registros->flatMap(fn (Registro $r) => $r->numerosSerie->pluck('numero_serie'));

        $lienzo = $this->nuevoLienzo();
        $y0 = $this->franjaLogos($lienzo);

        $x = $this->formato->margenX;
        $anchoQr = $this->mm(self::ANCHO_QR);
        $ancho = $this->formato->anchoUtil() - $anchoQr - $this->mm(1);
        $mitad = intdiv($ancho - $this->mm(1), 2);
        $x2 = $x + $ancho - $mitad;

        // ── Columna de datos ──
        $y = $y0;
        $y += $this->bloqueTarima($lienzo, $x, $y, $ancho, 'TARIMA '.$tarima->id) + $this->mm(0.75);

        // SKUs: uno grande; dos en la misma línea; tres o más, el primero y cuántos faltan.
        $skus = $registros->pluck('sku');
        [$sku, $descripcion] = match (true) {
            $skus->count() === 1 => [$skus->first(), $registros->first()->descripcion_mostrada],
            $skus->count() === 2 => [$skus->join(' · '), '2 SKUs padre'],
            default => [$skus->first(), '+ '.($skus->count() - 1).' SKUs más'],
        };
        $y += $this->campo($lienzo, $x, $y, $ancho, 'SKU', $sku, 5.5, 2.5) + $this->mm(0.25);
        $lienzo->texto($x, $y, $ancho, $this->mm(self::TEXTO_APOYO), $descripcion, 'L');
        $y += $this->mm(self::TEXTO_APOYO) + $this->mm(1);

        $this->campo($lienzo, $x, $y, $mitad, 'Destino', $this->tarimas->destino($tarima), self::TEXTO_NORMAL, self::TEXTO_APOYO);
        $alto = $this->campo($lienzo, $x2, $y, $mitad, 'Fecha', $this->tarimas->fechaEtiqueta($tarima)->format('Y-m-d'), self::TEXTO_NORMAL, self::TEXTO_APOYO);
        $y += $alto + $this->mm(1);

        $ns = match ($numerosSerie->count()) {
            0 => 'Sin N/S capturados',
            1 => $numerosSerie->first(),
            default => $numerosSerie->first().' +'.($numerosSerie->count() - 1),
        };
        $y += $this->campo($lienzo, $x, $y, $ancho, 'N/S · detalle en el QR', $ns, self::TEXTO_NORMAL, self::TEXTO_APOYO) + $this->mm(1);

        $this->campo($lienzo, $x, $y, $mitad, 'SKUs padre', (string) $registros->count(), self::TEXTO_NORMAL, self::TEXTO_APOYO);
        $this->campo($lienzo, $x2, $y, $mitad, 'Total N/S', (string) $numerosSerie->count(), self::TEXTO_NORMAL, self::TEXTO_APOYO);

        // ── QR: centrado en su columna, con aire alrededor (≈ 5 mm) ──
        $yQr = $y0 - $this->mm(0.375);
        $lienzo->qrEnCaja($this->formato->limiteDerecho() - $anchoQr, $yQr, $anchoQr, $this->inicioPie() - $yQr, $this->tarimas->urlDetalle($tarima), self::MODULO_QR);

        $this->pie($lienzo, $tarima);

        return [$lienzo];
    }
}
