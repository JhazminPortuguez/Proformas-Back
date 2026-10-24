<?php

namespace App\Models;

use App\Enums\EstadoProforma;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proforma extends Model
{
    use HasFactory, PerteneceAEmpresa;

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
        'created_by',
        'updated_by',
        'empresa_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_validez' => 'date',
            'estado' => EstadoProforma::class,
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'impuesto' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProformaItem::class, 'proforma_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
