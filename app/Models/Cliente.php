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
}
