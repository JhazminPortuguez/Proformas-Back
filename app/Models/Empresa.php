<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    protected $table = 'empresas';

    protected $fillable = [
        'nombre',
        'nit',
        'telefono',
        'email',
        'direccion',
        'logo_path',
        'condiciones',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'empresa_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class, 'empresa_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'empresa_id');
    }

    public function proformas(): HasMany
    {
        return $this->hasMany(Proforma::class, 'empresa_id');
    }

    public function servicios(): HasMany
    {
        return $this->hasMany(Servicio::class, 'empresa_id');
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudCotizacion::class, 'empresa_id');
    }

    public function ordenesServicio(): HasMany
    {
        return $this->hasMany(OrdenServicio::class, 'empresa_id');
    }

    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'empresa_id');
    }

    public function cuentasPorCobrar(): HasMany
    {
        return $this->hasMany(CuentaPorCobrar::class, 'empresa_id');
    }

    public function pagosCuenta(): HasMany
    {
        return $this->hasMany(PagoCuenta::class, 'empresa_id');
    }
}
