<?php

namespace App\Models;

use App\Enums\Rol;
use App\Models\Concerns\PerteneceAEmpresa;
use App\Notifications\VerificarCorreoCliente;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Ronda A, parte 3: MustVerifyEmail. Solo se le exige al cliente (middleware
 * `correo_verificado` en las escrituras del portal); el personal lo crea el
 * administrador y no verifica.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PerteneceAEmpresa;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'activo',
        'cliente_id',
        'empresa_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
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
            'rol' => Rol::class,
            'activo' => 'boolean',
            'datos_pendientes' => 'boolean',
            'solo_google' => 'boolean',
        ];
    }

    /** El enlace lleva al back y de vuelta al front (no a una ruta web). */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerificarCorreoCliente);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function ordenesAsignadas(): HasMany
    {
        return $this->hasMany(OrdenServicio::class, 'operario_id');
    }

    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'created_by');
    }

    public function cuentasPorCobrarCreadas(): HasMany
    {
        return $this->hasMany(CuentaPorCobrar::class, 'created_by');
    }

    public function pagosCuentaRegistrados(): HasMany
    {
        return $this->hasMany(PagoCuenta::class, 'created_by');
    }
}
