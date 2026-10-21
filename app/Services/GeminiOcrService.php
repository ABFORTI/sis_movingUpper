<?php

namespace App\Services;

use App\Exceptions\OcrException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;


class GeminiOcrService {
    private const PATRON_CODIGO = '/^[A-Za-z0-9][A-Za-z0-9\-\/._#]*$/';
    private const MAX_CARACTERES = 150;

    private const PROMPT = 
    <<<'PROMPT'
        Eres un sistema OCR de etiquetas de productos industriales en un almacén.
        Lee la etiqueta de la imagen y extrae SOLO texto visible:

        - "sku": el SKU, número de parte o código de producto (rótulos como SKU, P/N, Part No., Item, Código, REF).
        - "modelo": el modelo (rótulos como Model, Modelo, MOD, Type).
        - "numero_serie": el número de serie de ESTA unidad (rótulos como S/N, SN, Serial No., N/S, No. de serie).
        Si hay varios números de serie, elige el rotulado como S/N y menciona los demás en "observaciones".
        - "observaciones": avisos breves en español (texto borroso, cortado, varias etiquetas, etc.).

        Reglas:
        - Devuelve el valor sin su rótulo (sin "S/N:", "Model:", etc.).
        - Conserva exactamente letras, números, guiones, ceros y mayúsculas.
        - No inventes datos ni completes caracteres ilegibles: si algún carácter es dudoso, usa null.
        - No confundas el SKU o el modelo con el número de serie; si no puedes distinguirlos, usa null.
        - Un código de barras sin texto legible debajo no cuenta como lectura.
    PROMPT;

    /**
     * @return array{sku: ?string, modelo: ?string, numero_serie: ?string, observaciones: list<string>}
     *
     * @throws OcrException
     */
    public function analizar(string $rutaImagen, string $mimeType): array {
        $apiKey = config('services.gemini.api_key');
        $modelo = config('services.gemini.model');
        if (! $apiKey || ! $modelo) {
            throw new OcrException('La lectura por foto no está configurada. Avisa a supervisión.', 503);
        }

        try {
            $respuesta = Http::timeout(config('services.gemini.timeout', 120))
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent", [
                    'contents' => [[
                        'parts' => [
                            ['text' => self::PROMPT],
                            ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode(file_get_contents($rutaImagen))]],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'sku' => ['type' => 'STRING', 'nullable' => true],
                                'modelo' => ['type' => 'STRING', 'nullable' => true],
                                'numero_serie' => ['type' => 'STRING', 'nullable' => true],
                                'observaciones' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                            ],
                            'required' => ['sku', 'modelo', 'numero_serie', 'observaciones'],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('OCR Gemini sin respuesta', ['mensaje' => $e->getMessage()]);
            throw new OcrException('El servicio de lectura no respondió. Intenta de nuevo o escribe la N/S a mano.', 504);
        }

        if ($respuesta->status() === 429) {
            Log::warning('OCR Gemini: límite de uso alcanzado');
            throw new OcrException('Se alcanzó el límite de lecturas por foto. Espera un minuto o escribe la N/S a mano.', 429);
        }
        if ($respuesta->failed()) {
            Log::error('OCR Gemini: error del servicio', ['status' => $respuesta->status(), 'error' => $respuesta->json('error.message')]);
            throw new OcrException('No fue posible leer la foto en este momento. Escribe la N/S a mano.', 502);
        }

        $texto = $respuesta->json('candidates.0.content.parts.0.text');
        if (! is_string($texto) || trim($texto) === '') {
            Log::warning('OCR Gemini sin texto', ['finishReason' => $respuesta->json('candidates.0.finishReason'), 'bloqueo' => $respuesta->json('promptFeedback.blockReason')]);
            throw new OcrException('No se pudo leer la etiqueta. Toma otra foto más cerca y con buena luz.', 422);
        }

        try {
            $datos = json_decode($texto, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::warning('OCR Gemini: respuesta no es JSON');
            throw new OcrException('No se pudo interpretar la lectura. Toma otra foto.', 502);
        }
        if (! is_array($datos)) {
            throw new OcrException('No se pudo interpretar la lectura. Toma otra foto.', 502);
        }

        $observaciones = array_values(array_filter(
            is_array($datos['observaciones'] ?? null) ? $datos['observaciones'] : [],
            fn ($o) => is_string($o) && trim($o) !== '',
        ));

        return [
            'sku' => $this->codigo($datos['sku'] ?? null),
            'modelo' => $this->codigo($datos['modelo'] ?? null),
            'numero_serie' => $this->codigo($datos['numero_serie'] ?? null),
            'observaciones' => array_map(fn ($o) => mb_substr(trim($o), 0, 200), array_slice($observaciones, 0, 5)),
        ];
    }

    private function codigo(mixed $valor): ?string {
        if (! is_string($valor)) {
            return null;
        }
        $valor = trim($valor);
        if ($valor === '' || mb_strlen($valor) > self::MAX_CARACTERES || ! preg_match(self::PATRON_CODIGO, $valor)) {
            return null;
        }

        return $valor;
    }
}
