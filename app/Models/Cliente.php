<?php

namespace App\Models;

use App\Enums\EstadoCliente;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'clientes';

    protected $fillable = [
        'nombre_razon',
        'nit_ci',
        'telefono',
        'email',
        'direccion',
        'estado',
        'empresa_id',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCliente::class,
        ];
    }

    public function proformas(): HasMany
    {
        return $this->hasMany(Proforma::class, 'cliente_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'cliente_id');
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudCotizacion::class, 'cliente_id');
    }

    public function ordenes(): HasMany
    {
        return $this->hasMany(OrdenServicio::class, 'cliente_id');
    }

    public function ordenesServicio(): HasMany
    {
        return $this->hasMany(OrdenServicio::class, 'cliente_id');
    }

    public function cuentasPorCobrar(): HasMany
    {
        return $this->hasMany(CuentaPorCobrar::class, 'cliente_id');
    }
}
