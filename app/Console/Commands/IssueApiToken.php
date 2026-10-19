<?php

namespace App\Console\Commands;

use App\KpiApi\KpiApi;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueApiToken extends Command {
    protected $signature = 'api:token
        {email : Correo de la cuenta de servicio}
        {--name=kpi-central : Nombre del token (uno por consumidor)}
        {--crear : Crea la cuenta de servicio si no existe}';

    protected $description = 'Emite un token Bearer de solo lectura (kpis:read) para la API de KPIs';

    public function handle(): int {
        $email = (string) $this->argument('email');
        $usuario = User::where('email', $email)->first();

        if (! $usuario) {
            if (! $this->option('crear')) {
                $this->error("No existe el usuario {$email}. Use --crear para crear la cuenta de servicio.");

                return self::FAILURE;
            }

            $usuario = User::create([
                'name' => 'Cuenta de servicio KPIs',
                'email' => $email,
                'password' => Str::password(64),
                'is_active' => true,
            ]);
            $this->info("Cuenta de servicio {$email} creada (sin rol, contraseña aleatoria).");
        }

        $token = $usuario->createToken((string) $this->option('name'), [KpiApi::ABILITY]);

        $this->line('Token (cópielo ahora: no se vuelve a mostrar):');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
