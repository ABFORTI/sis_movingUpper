<?php

namespace App\Providers;

use App\Contracts\ExportadorTarimas;
use App\Services\Etiquetas\EtiquetaListado;
use App\Services\Etiquetas\EtiquetaPadreQr;
use App\Services\Etiquetas\EtiquetaService;
use App\Services\Etiquetas\FormatoEtiqueta;
use App\Services\Etiquetas\SvgRenderer;
use App\Services\Etiquetas\ZplRenderer;
use App\Services\Exportacion\ExcelExportador;
use App\Services\TarimaService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->bind(ExportadorTarimas::class, ExcelExportador::class);

        $this->app->bind(FormatoEtiqueta::class, fn () => FormatoEtiqueta::desdeConfig());

        $this->app->bind(EtiquetaListado::class, fn (Application $app) => new EtiquetaListado(
            $app->make(TarimaService::class),
            $app->make(FormatoEtiqueta::class),
            config('etiquetas.filas_por_etiqueta'),
        ));

        $this->app->tag([EtiquetaPadreQr::class, EtiquetaListado::class], 'etiquetas.disenos');
        $this->app->bind(EtiquetaService::class, fn (Application $app) => new EtiquetaService(
            $app->tagged('etiquetas.disenos'),
            $app->make(ZplRenderer::class),
            $app->make(SvgRenderer::class),
        ));
    }

    public function boot(): void {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
