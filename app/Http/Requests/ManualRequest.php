<?php

namespace App\Http\Requests;

use App\Enums\VisibilidadManual;
use App\Models\Manual;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class ManualRequest extends FormRequest {
    public function authorize(): bool {
        return (bool) $this->user()?->isAdmin();
    }

    /** Quita etiquetas, caracteres de control y espacios repetidos del nombre. */
    protected function prepareForValidation(): void {
        if (is_string($this->input('nombre'))) {
            $nombre = strip_tags($this->input('nombre'));
            $nombre = preg_replace('/[\p{C}]+/u', ' ', $nombre) ?? '';
            $this->merge(['nombre' => trim(preg_replace('/\s+/u', ' ', $nombre) ?? '')]);
        }
    }

    public function rules(): array {
        $creando = ! $this->route('manual');

        return [
            'nombre' => ['required', 'string', 'min:3', 'max:150'],
            'visible_para' => ['required', Rule::enum(VisibilidadManual::class)],
            'archivo' => [
                $creando ? 'required' : 'nullable',
                'file',
                'extensions:pdf',                 // extensión que envía el navegador
                'mimes:pdf',                      // tipo detectado por el contenido (finfo)
                'mimetypes:application/pdf',
                'max:'.Manual::TAMANO_MAXIMO_KB,
                $this->firmaPdf(...),             // los bytes iniciales deben ser %PDF-
            ],
        ];
    }

    private function firmaPdf(string $atributo, mixed $valor, Closure $fallar): void {
        if (! $valor instanceof UploadedFile || ! $valor->isValid()) {
            return;
        }

        $cabecera = (string) @file_get_contents($valor->getRealPath(), false, null, 0, 1024);
        if (! str_contains($cabecera, '%PDF-')) {
            $fallar('El archivo no es un PDF válido.');
        }
    }

    public function messages(): array {
        return [
            'nombre.required' => 'Escribe el nombre del manual.',
            'nombre.min' => 'El nombre debe tener al menos 3 caracteres.',
            'nombre.max' => 'El nombre no puede tener más de 150 caracteres.',
            'visible_para.*' => 'Elige quién puede ver el manual.',
            'archivo.required' => 'Selecciona el archivo PDF.',
            'archivo.uploaded' => 'No se pudo subir el archivo. Verifica que no pese más de 20 MB.',
            'archivo.max' => 'El PDF no puede pesar más de 20 MB.',
            'archivo.extensions' => 'El archivo debe ser un PDF.',
            'archivo.mimes' => 'El archivo no es un PDF válido.',
            'archivo.mimetypes' => 'El archivo no es un PDF válido.',
            'archivo.file' => 'Selecciona un archivo válido.',
        ];
    }

    /** @return array{nombre: string, visible_para: string} */
    public function datos(): array {
        return $this->safe()->only(['nombre', 'visible_para']);
    }
}
