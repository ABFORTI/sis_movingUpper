<?php

namespace App\Http\Requests;

use App\Enums\Demo;
use App\Models\Tarima;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;


class RegistrarSkuRequest extends FormRequest {
    public function authorize(): bool {
        $tarima = Tarima::where('uuid', (string) $this->input('tarima'))->first();

        return $tarima === null || $this->user()->can('trabajar', $tarima);
    }

    public function rules(): array {
        return [
            'sku' => ['required', 'string', 'max:100'],
            'tarima' => ['required', 'uuid', Rule::exists('tarimas', 'uuid')->whereNull('guardada_at')->whereNull('anulada_at')],
            'demo' => ['nullable', Rule::enum(Demo::class)],
        ];
    }

    public function messages(): array {
        return [
            'sku.required' => 'Elige el SKU de la lista (o escanéalo).',
            'sku.max' => 'El SKU no puede tener más de 100 caracteres.',
            'tarima.*' => 'Selecciona un embarque válido.',
            'demo.*' => 'Elige si es demo (o «No aplica»).',
        ];
    }

    public function tarima(): Tarima {
        return Tarima::where('uuid', $this->validated('tarima'))->firstOrFail();
    }

    public function demo(): Demo {
        return Demo::tryFrom((string) $this->validated('demo')) ?? Demo::NoAplica;
    }
}
