<?php

namespace App\Models;

use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\OrigenProforma;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'moneda',
        'impuesto_tasa',
        'subtotal',
        'descuento',
        'impuesto',
        'total',
        'observaciones',
        'enviada_at',
        'aceptada_at',
        'aceptada_por',
        'rechazada_at',
        'motivo_rechazo',
        'origen',
        'solicitud_id',
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
            'origen' => OrigenProforma::class,
            'moneda' => 'string',
            'impuesto_tasa' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'impuesto' => 'decimal:2',
            'total' => 'decimal:2',
            'enviada_at' => 'datetime',
            'aceptada_at' => 'datetime',
            'rechazada_at' => 'datetime',
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

    public function aceptador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aceptada_por');
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCotizacion::class, 'solicitud_id');
    }

    public function ordenes(): HasMany
    {
        return $this->hasMany(OrdenServicio::class, 'proforma_id');
    }

    /**
     * Orden de servicio más reciente generada desde la proforma.
     */
    public function orden(): HasOne
    {
        return $this->hasOne(OrdenServicio::class, 'proforma_id')->latestOfMany();
    }

    public function tieneOrdenVigente(): bool
    {
        $orden = $this->relationLoaded('orden')
            ? $this->orden
            : $this->orden()->first();

        return $orden !== null && $orden->estado !== EstadoOrden::Cancelada;
    }
}
