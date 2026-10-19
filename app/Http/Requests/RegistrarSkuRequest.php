<?php

namespace App\Http\Requests;

use App\Models\Tarima;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SKU elegido en el selector. Que exista en el inventario del almacén de la
 * tarima lo valida EscaneoService (no se confía en las opciones del navegador);
 * la descripción sale del inventario, por eso no se recibe.
 */
class RegistrarSkuRequest extends FormRequest {
    public function authorize(): bool {
        $tarima = Tarima::where('uuid', (string) $this->input('tarima'))->first();

        return $tarima === null || $this->user()->can('trabajar', $tarima);
    }

    public function rules(): array {
        return [
            'sku' => ['required', 'string', 'max:100'],
            'tarima' => ['required', 'uuid', Rule::exists('tarimas', 'uuid')->whereNull('guardada_at')->whereNull('anulada_at')],
        ];
    }

    public function messages(): array {
        return [
            'sku.required' => 'Elige el SKU de la lista (o escanéalo).',
            'sku.max' => 'El SKU no puede tener más de 100 caracteres.',
            'tarima.*' => 'Selecciona un embarque válido.',
        ];
    }

    public function tarima(): Tarima {
        return Tarima::where('uuid', $this->validated('tarima'))->firstOrFail();
    }
}
