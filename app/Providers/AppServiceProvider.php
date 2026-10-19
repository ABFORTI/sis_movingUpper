<?php

namespace App\Providers;

use App\Contracts\ExportadorTarimas;
use App\Services\Etiquetas\FormatoEtiqueta;
use App\Services\Exportacion\ExcelExportador;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->bind(ExportadorTarimas::class, ExcelExportador::class);

        $this->app->bind(FormatoEtiqueta::class, fn () => FormatoEtiqueta::desdeConfig());
    }

    public function boot(): void {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
