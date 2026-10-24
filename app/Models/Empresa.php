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
}
