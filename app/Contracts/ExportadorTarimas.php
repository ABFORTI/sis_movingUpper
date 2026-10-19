<?php

namespace App\Contracts;

use App\Models\Tarima;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface ExportadorTarimas
{
    /**
     * @param Collection<int, Tarima> $tarimas
     * @param string $alcance nombre del archivo sin fecha
     */
    public function todas(Collection $tarimas, string $alcance): StreamedResponse;
}
