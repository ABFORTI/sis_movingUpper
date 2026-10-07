<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UsuarioRequest extends FormRequest {
    public function authorize(): bool {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array {
        $usuario = $this->route('usuario');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario)],
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::min(8)->letters()->numbers()],
            'rol' => [
                'required',
                Rule::in([Role::ADMIN, Role::AUDITOR, Role::OPERADOR]),
                Rule::exists('roles', 'slug')->where('is_active', true),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array {
        return [
            'name.required' => 'Escribe el nombre.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'password.required' => 'Escribe una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.letters' => 'La contraseña debe incluir letras.',
            'password.numbers' => 'La contraseña debe incluir números.',
            'rol.*' => 'Selecciona un rol válido.',
        ];
    }
}
