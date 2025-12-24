<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio_unitario',
        'unidad_medida',
        'activo',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ProformaItem::class, 'producto_id');
    }
}

