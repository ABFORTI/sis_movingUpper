<?php

namespace App\Http\Controllers;

use App\Exceptions\OcrException;
use App\Services\GeminiOcrService;
use App\Support\SkuActivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Foto del N/S": lee la etiqueta y propone el SKU/modelo y la N/S.
 *
 * No guarda nada (ni la foto): el SKU se elige en el selector y la N/S se
 * confirma con "Agregar N/S", así que pasan por las mismas validaciones,
 * coincidencia con el inventario, incidencias y control de repetidas que una
 * lectura escaneada. Funciona con o sin SKU activo.
 */
class OcrController extends Controller {
    public function analizar(Request $request, GeminiOcrService $gemini, SkuActivo $skuActivo): JsonResponse {
        $request->validate([
            'imagen' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:min_width=200,min_height=200'],
        ], [
            'imagen.required' => 'Toma o adjunta una foto de la etiqueta.',
            'imagen.image' => 'El archivo no es una imagen.',
            'imagen.mimes' => 'Usa una foto JPG, PNG o WEBP.',
            'imagen.max' => 'La foto pesa más de 10 MB.',
            'imagen.dimensions' => 'La foto es demasiado pequeña para leer la etiqueta.',
        ]);

        $imagen = $request->file('imagen');
        try {
            $lectura = $gemini->analizar($imagen->getRealPath(), $imagen->getMimeType());
        } catch (OcrException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $avisos = $lectura['observaciones'];
        $mismo = fn (?string $a, ?string $b) => $a !== null && $b !== null && strcasecmp($a, $b) === 0;
        $ns = $lectura['numero_serie'];
        $registroActivo = $skuActivo->obtener();
        $activo = $registroActivo?->sku;
        // Un SKU convertido sigue impreso con su código anterior en la etiqueta del producto.
        $original = $registroActivo?->sku_original;

        // La N/S nunca puede ser el código del producto: si coinciden, la lectura los confundió.
        if ($ns !== null && ($mismo($ns, $lectura['sku']) || $mismo($ns, $lectura['modelo']) || $mismo($ns, $activo) || $mismo($ns, $original))) {
            $avisos[] = "«{$ns}» parece el SKU o modelo, no la N/S. Escribe la N/S a mano.";
            $ns = null;
        }

        if ($activo !== null && ($lectura['sku'] !== null || $lectura['modelo'] !== null)
            && ! $mismo($activo, $lectura['sku']) && ! $mismo($activo, $lectura['modelo'])
            && ! $mismo($original, $lectura['sku']) && ! $mismo($original, $lectura['modelo'])) {
            $leido = $lectura['sku'] ?? $lectura['modelo'];
            $avisos[] = "La etiqueta parece de otro producto ({$leido}); el SKU activo es {$activo}. Revisa antes de agregar.";
        }

        if ($ns === null && $lectura['sku'] === null && $lectura['modelo'] === null) {
            $avisos[] = 'No se leyó ningún dato con certeza. Toma otra foto más cerca y con buena luz, o escribe a mano.';
        } elseif ($ns === null) {
            $avisos[] = 'No se leyó la N/S con certeza.';
        }

        return response()->json([
            'sku' => $lectura['sku'],
            'modelo' => $lectura['modelo'],
            'numero_serie' => $ns,
            'sku_activo' => $activo,
            'avisos' => array_values(array_unique($avisos)),
        ]);
    }
}
