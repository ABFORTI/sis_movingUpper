<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;


class AgregarNumeroSerieRequest extends FormRequest {
    public function authorize(): bool {
        return true;
    }

    public function rules(): array {
        return [
            'numero_serie' => ['required', 'string', 'max:150'],
        ];
    }

    public function messages(): array {
        return [
            'numero_serie.required' => 'Escanea o escribe la N/S.',
            'numero_serie.max' => 'La N/S no puede tener más de 150 caracteres.',
        ];
    }
}
