<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlcancePromocion;
use App\Enums\TipoProformaItem;
use App\Enums\TipoPromocion;
use App\Models\Concerns\PerteneceAEmpresa;
use App\Support\FechaBolivia;
use Database\Factories\PromocionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ronda C, parte 1: promoción del catálogo (la gestiona el administrador).
 * Una promoción que ya se usó en una línea no se borra: se desactiva.
 */
class Promocion extends Model
{
    /** @use HasFactory<PromocionFactory> */
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'promociones';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'tipo',
        'valor',
        'aplica_a',
        'servicio_id',
        'producto_id',
        'vigente_desde',
        'vigente_hasta',
        'activa',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPromocion::class,
            'aplica_a' => AlcancePromocion::class,
            'valor' => 'decimal:2',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'activa' => 'boolean',
        ];
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function lineasProforma(): HasMany
    {
        return $this->hasMany(ProformaItem::class, 'promocion_id');
    }

    public function lineasOrden(): HasMany
    {
        return $this->hasMany(OrdenItem::class, 'promocion_id');
    }

    /** Activas y dentro de su vigencia hoy (Bolivia). */
    public function scopeVigentes(Builder $query, ?string $hoy = null): Builder
    {
        $hoy ??= FechaBolivia::hoyTexto();

        return $query
            ->where('activa', true)
            ->whereDate('vigente_desde', '<=', $hoy)
            ->whereDate('vigente_hasta', '>=', $hoy);
    }

    public function estaVigente(?string $hoy = null): bool
    {
        $hoy ??= FechaBolivia::hoyTexto();

        return $this->activa
            && $this->vigente_desde->toDateString() <= $hoy
            && $this->vigente_hasta->toDateString() >= $hoy;
    }

    /** Por qué no se puede aplicar hoy, o null si está vigente. */
    public function motivoNoVigente(?string $hoy = null): ?string
    {
        $hoy ??= FechaBolivia::hoyTexto();

        if (! $this->activa) {
            return "La promoción \"{$this->nombre}\" está inactiva.";
        }

        if ($this->vigente_hasta->toDateString() < $hoy) {
            return "La promoción \"{$this->nombre}\" venció el ".FechaBolivia::formato($this->vigente_hasta).'.';
        }

        if ($this->vigente_desde->toDateString() > $hoy) {
            return "La promoción \"{$this->nombre}\" empieza el ".FechaBolivia::formato($this->vigente_desde).'.';
        }

        return null;
    }

    /** Si la promoción corresponde a una línea de ese tipo y catálogo. */
    public function aplicaALinea(TipoProformaItem $tipo, ?int $servicioId, ?int $productoId): bool
    {
        return match ($this->aplica_a) {
            AlcancePromocion::Cualquiera => true,
            AlcancePromocion::Servicio => $tipo === TipoProformaItem::Servicio && $servicioId === (int) $this->servicio_id,
            AlcancePromocion::Producto => $tipo === TipoProformaItem::Material && $productoId === (int) $this->producto_id,
        };
    }

    public function estaUsada(): bool
    {
        return $this->lineasProforma()->exists() || $this->lineasOrden()->exists();
    }
}
