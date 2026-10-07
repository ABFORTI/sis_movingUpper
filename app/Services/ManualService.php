<?php

namespace App\Services;

use App\Models\Manual;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Guarda los PDF en el disco privado con un nombre generado (UUID + .pdf):
 * el nombre que envía el usuario nunca llega al sistema de archivos.
 */
class ManualService {
    public function crear(array $datos, UploadedFile $pdf, User $usuario): Manual {
        $archivo = $this->almacenar($pdf);

        try {
            return Manual::create($datos + [
                'archivo' => $archivo,
                'tamano' => $pdf->getSize(),
                'subido_by' => $usuario->id,
            ]);
        } catch (Throwable $e) {
            $this->disco()->delete($archivo);
            throw $e;
        }
    }

    /** Si llega un PDF nuevo, reemplaza al anterior y lo borra solo después de guardar. */
    public function actualizar(Manual $manual, array $datos, ?UploadedFile $pdf, User $usuario): Manual {
        if (! $pdf) {
            $manual->update($datos);

            return $manual;
        }

        $anterior = $manual->archivo;
        $nuevo = $this->almacenar($pdf);

        try {
            $manual->update($datos + [
                'archivo' => $nuevo,
                'tamano' => $pdf->getSize(),
                'subido_by' => $usuario->id,
            ]);
        } catch (Throwable $e) {
            $this->disco()->delete($nuevo);
            throw $e;
        }

        $this->disco()->delete($anterior);

        return $manual;
    }

    public function eliminar(Manual $manual): void {
        $archivo = $manual->archivo;
        $manual->delete();
        $this->disco()->delete($archivo);
    }

    public function existeArchivo(Manual $manual): bool {
        return $this->disco()->exists($manual->archivo);
    }

    public function disco(): Filesystem {
        return Storage::disk(Manual::DISCO);
    }

    private function almacenar(UploadedFile $pdf): string {
        return $this->disco()->putFileAs('', $pdf, Str::uuid()->toString().'.pdf');
    }
}
