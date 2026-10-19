<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable {
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function hasRole(string $slug): bool {
        return $this->roles->contains('slug', $slug);
    }

    public function isAdmin(): bool {
        return $this->hasRole(Role::ADMIN);
    }

    /** Todos ven todas las tarimas de la mudanza; operar las de otros es de admin y auditor. */
    public function puedeTrabajarTodasLasTarimas(): bool {
        return $this->hasRole(Role::ADMIN) || $this->hasRole(Role::AUDITOR);
    }

    /** Mover o retirar piezas de tarimas ya guardadas (con motivo). */
    public function puedeCorregirTarimas(): bool {
        return $this->hasRole(Role::ADMIN) || $this->hasRole(Role::AUDITOR);
    }

    /** Generar entregas al cliente y gestionar incidencias (asignar, cambiar estado, resolver). */
    public function esSupervision(): bool {
        return $this->hasRole(Role::ADMIN) || $this->hasRole(Role::AUDITOR);
    }

    public function asignarRol(string $slug): void {
        $this->roles()->sync([Role::where('slug', $slug)->firstOrFail()->id]);
        $this->unsetRelation('roles');
    }
}
