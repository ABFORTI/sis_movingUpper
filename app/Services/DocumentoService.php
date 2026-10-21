<?php

namespace App\Services;

use App\Models\Evidencia;
use App\Models\Mudanza;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;


class DocumentoService {
    public function disco(): Filesystem {
        return Storage::disk('documentos');
    }

    /** Guarda contenido ya generado. @return array{archivo: string, sha256: string, tamano: int} */
    public function guardarContenido(string $carpeta, string $extension, string $contenido): array {
        $archivo = trim($carpeta, '/').'/'.Str::uuid().'.'.$extension;
        $this->disco()->put($archivo, $contenido);

        return ['archivo' => $archivo, 'sha256' => hash('sha256', $contenido), 'tamano' => strlen($contenido)];
    }

    public function adjuntar(UploadedFile $subido, Model $evidenciable, Mudanza $mudanza, User $usuario): Evidencia {
        $extension = strtolower($subido->getClientOriginalExtension() ?: $subido->extension() ?: 'bin');
        $guardado = $this->guardarContenido("evidencias/{$mudanza->folio}", $extension, $subido->get());

        try {
            return Evidencia::create([
                'mudanza_id' => $mudanza->id,
                'evidenciable_type' => $evidenciable->getMorphClass(),
                'evidenciable_id' => $evidenciable->getKey(),
                'archivo' => $guardado['archivo'],
                'nombre_original' => Str::limit($subido->getClientOriginalName(), 195, ''),
                'mime' => $subido->getMimeType() ?: 'application/octet-stream',
                'tamano' => $guardado['tamano'],
                'sha256' => $guardado['sha256'],
                'subida_by' => $usuario->id,
            ]);
        } catch (Throwable $e) {
            $this->disco()->delete($guardado['archivo']);
            throw $e;
        }
    }

    public function intacto(string $archivo, string $sha256): bool {
        return $this->disco()->exists($archivo) && hash_equals($sha256, hash('sha256', $this->disco()->get($archivo)));
    }


    public function descargar(string $archivo, string $sha256, string $nombre, bool $enLinea = false): StreamedResponse {
        abort_unless($this->intacto($archivo, $sha256), 409, 'El archivo no coincide con su registro (falta o fue alterado). Avisa al administrador.');

        $disposicion = $enLinea ? 'inline' : 'attachment';

        return $this->disco()->response($archivo, $nombre, [
            'Content-Disposition' => $disposicion.'; filename="'.addcslashes($nombre, '"\\').'"',
            'X-Content-SHA256' => $sha256,
        ]);
    }
}
