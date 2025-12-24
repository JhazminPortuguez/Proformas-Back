<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proforma extends Model
{
    protected $table = 'proformas';

    protected $fillable = [
        'numero',
        'cliente_id',
        'fecha_emision',
        'fecha_validez',
        'estado',
        'subtotal',
        'descuento',
        'impuesto',
        'total',
        'observaciones',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProformaItem::class, 'proforma_id');
    }
}

