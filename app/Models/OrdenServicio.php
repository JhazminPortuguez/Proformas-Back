<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoOrden;
use App\Enums\TipoProformaItem;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrdenServicio extends Model
{
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'ordenes_servicio';

    protected $fillable = [
        'empresa_id',
        'proforma_id',
        'cliente_id',
        'numero',
        'operario_id',
        'estado',
        'fecha_programada',
        'fecha_inicio',
        'fecha_fin',
        'observaciones',
        'motivo_cancelacion',
        'cancelada_at',
        'cerrada_at',
        'cerrada_por',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoOrden::class,
            'fecha_programada' => 'date',
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
            'cancelada_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class, 'proforma_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function operario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operario_id');
    }

    public function cerrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrdenItem::class, 'orden_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(OrdenEvento::class, 'orden_id')->orderBy('id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'orden_id');
    }

    public function cuentaPorCobrar(): HasOne
    {
        return $this->hasOne(CuentaPorCobrar::class, 'orden_id');
    }

    /**
     * Órdenes que todavía admiten acciones operativas.
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->whereIn('estado', [
            EstadoOrden::Pendiente->value,
            EstadoOrden::Asignada->value,
            EstadoOrden::EnProceso->value,
        ]);
    }

    public function estaActiva(): bool
    {
        return $this->estado?->esActiva() ?? false;
    }

    public function estaCerrada(): bool
    {
        return $this->cerrada_at !== null;
    }

    /**
     * Indica si quedan líneas MATERIAL con producto y cantidad pendiente.
     */
    public function tieneMaterialesPendientes(): bool
    {
        if ($this->relationLoaded('items')) {
            return $this->items->contains(fn (OrdenItem $item): bool => $item->tipo === TipoProformaItem::Material
                && $item->producto_id !== null
                && ! $item->estaConsumido());
        }

        return $this->items()
            ->where('tipo', TipoProformaItem::Material->value)
            ->whereNotNull('producto_id')
            ->whereColumn('cantidad_consumida', '<', 'cantidad')
            ->exists();
    }
}
