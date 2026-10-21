<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MudanzaRequest extends FormRequest {
 
    public function authorize(): bool {
        $usuario = $this->user();

        return $this->route('mudanza')
            ? (bool) $usuario?->isAdmin()
            : (bool) ($usuario?->isAdmin() || $usuario?->hasRole(Role::AUDITOR));
    }

    protected function prepareForValidation(): void {
 
        $this->merge([
            'movimientos' => collect((array) $this->input('movimientos', []))
                ->map(fn ($m) => ['origen' => trim((string) ($m['origen'] ?? '')), 'destino' => trim((string) ($m['destino'] ?? ''))])
                ->reject(fn (array $m) => $m['origen'] === '' && $m['destino'] === '')
                ->values()->all(),
        ]);
    }

    public function rules(): array {
        return [
            'cliente' => ['required', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:500'],
            'movimientos' => ['required', 'array', 'min:1', 'max:30'],
            'movimientos.*.origen' => ['required', 'string', 'max:50'],
            'movimientos.*.destino' => ['required', 'string', 'max:50'],
        ];
    }

    public function after(): array {
        return [function (Validator $validator) {
            foreach ((array) $this->input('movimientos', []) as $i => $m) {
                if (($m['origen'] ?? '') !== '' && mb_strtoupper($m['origen']) === mb_strtoupper($m['destino'] ?? '')) {
                    $validator->errors()->add("movimientos.{$i}.destino", 'En cada movimiento, el almacén destino debe ser distinto al de origen.');
                }
            }
        }];
    }

    public function messages(): array {
        return [
            'cliente.required' => 'Escribe el nombre del cliente.',
            'movimientos.required' => 'Agrega al menos un movimiento: almacén de origen y almacén de destino.',
            'movimientos.min' => 'Agrega al menos un movimiento: almacén de origen y almacén de destino.',
            'movimientos.*.origen.required' => 'Escribe el almacén de origen de cada movimiento.',
            'movimientos.*.destino.required' => 'Escribe el almacén de destino de cada movimiento.',
            '*.max' => 'El texto es demasiado largo.',
            'movimientos.*.*.max' => 'El código de almacén admite hasta 50 caracteres.',
        ];
    }

    /** @return array{cliente: string, notas: ?string} */
    public function datos(): array {
        return $this->safe()->only(['cliente', 'notas']);
    }

    /** @return list<array{origen: string, destino: string}> */
    public function movimientos(): array {
        return $this->validated('movimientos');
    }
}
