<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $fillable = [
        'nombre_razon',
        'nit_ci',
        'telefono',
        'email',
        'direccion',
    ];

    public function proformas(): HasMany
    {
        return $this->hasMany(Proforma::class, 'cliente_id');
    }
}

