<?php

namespace App\Contracts;

use App\Models\Tarima;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface ExportadorTarimas
{
    public function tarima(Tarima $tarima): StreamedResponse;

    /** @param Collection<int, Tarima> $tarimas */
    public function todas(Collection $tarimas): StreamedResponse;
}
