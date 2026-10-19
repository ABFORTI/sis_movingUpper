<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/** Archivo que respalda algo (foto, PDF, correo, Excel). Con SHA-256; no se borra. */
class Evidencia extends Model {
    public const UPDATED_AT = null;

    /** Tamaño máximo por archivo. */
    public const TAMANO_MAXIMO_KB = 10240;

    /** Extensiones aceptadas: fotos, PDF, hojas de cálculo, correos y texto. */
    public const EXTENSIONES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf', 'xlsx', 'xls', 'csv', 'eml', 'msg', 'txt'];

    protected $table = 'evidencias';

    protected $fillable = [
        'mudanza_id',
        'evidenciable_type',
        'evidenciable_id',
        'archivo',
        'nombre_original',
        'mime',
        'tamano',
        'sha256',
        'subida_by',
    ];

    /** Reglas de validación para un campo de varios archivos llamado "archivos". */
    public static function reglas(): array {
        return [
            'archivos' => ['nullable', 'array', 'max:10'],
            'archivos.*' => ['file', 'max:'.self::TAMANO_MAXIMO_KB, 'extensions:'.implode(',', self::EXTENSIONES)],
        ];
    }

    public static function mensajes(): array {
        return [
            'archivos.max' => 'Máximo 10 archivos a la vez.',
            'archivos.*.max' => 'Cada archivo puede pesar hasta 10 MB.',
            'archivos.*.extensions' => 'Formato no aceptado. Usa foto, PDF, Excel, CSV, correo o texto.',
            'archivos.*.file' => 'No se pudo subir uno de los archivos.',
        ];
    }

    protected static function booted(): void {
        static::updating(fn () => throw new LogicException('Una evidencia no se modifica.'));
        static::deleting(fn () => throw new LogicException('Una evidencia no se borra.'));
    }

    public function evidenciable(): MorphTo {
        return $this->morphTo();
    }

    public function subidaPor(): BelongsTo {
        return $this->belongsTo(User::class, 'subida_by');
    }

    public function esImagen(): bool {
        return str_starts_with($this->mime, 'image/');
    }
}
