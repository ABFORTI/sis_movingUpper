<?php

namespace App\KpiApi;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class KpiApiServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->singleton(KpiRegistry::class);
    }

    public function boot(): void {
        RateLimiter::for('kpi-api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $clave = $token instanceof \Illuminate\Database\Eloquent\Model && $token->getKey()
                ? 'token:' . $token->getKey()
                : 'ip:' . $request->ip();

            return Limit::perMinute((int) config('kpi-api.limite_por_minuto', 60))->by('kpi-api|' . $clave);
        });
    }
}
