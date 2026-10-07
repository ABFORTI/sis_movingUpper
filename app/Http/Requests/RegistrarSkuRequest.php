<?php

namespace App\Http\Requests;

use App\Models\Tarima;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarSkuRequest extends FormRequest {
    /**
     * Aquí solo se comprueba que el usuario pueda ver la tarima. Si no existe o ya
     * está guardada, la validación responde "Selecciona una tarima válida".
     */
    public function authorize(): bool {
        $tarima = Tarima::where('uuid', (string) $this->input('tarima'))->first();

        return $tarima === null || $this->user()->can('view', $tarima);
    }

    public function rules(): array {
        return [
            'sku' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'destino_id' => ['required', 'integer', Rule::exists('destinos', 'id')->where('is_active', true)],
            'tarima' => ['required', 'uuid', Rule::exists('tarimas', 'uuid')->whereNull('guardada_at')],
        ];
    }

    public function messages(): array {
        return [
            'sku.required' => 'Escanea o escribe el SKU.',
            'sku.max' => 'El SKU no puede tener más de 100 caracteres.',
            'destino_id.*' => 'Selecciona un producto destino válido.',
            'tarima.*' => 'Selecciona una tarima válida.',
        ];
    }

    public function tarima(): Tarima {
        return Tarima::where('uuid', $this->validated('tarima'))->firstOrFail();
    }

    /** @return array{sku: string, descripcion: ?string, destino_id: int} */
    public function datosSku(): array {
        return $this->safe()->only(['sku', 'descripcion', 'destino_id']);
    }
}
