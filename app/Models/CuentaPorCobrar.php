<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCobro;
use App\Models\Concerns\PerteneceAEmpresa;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuentaPorCobrar extends Model
{
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'cuentas_por_cobrar';

    protected $fillable = [
        'empresa_id',
        'orden_id',
        'cliente_id',
        'numero',
        'fecha_emision',
        'fecha_vencimiento',
        'moneda',
        'monto_total',
        'saldo',
        'estado_cobro',
        'observaciones',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
            'monto_total' => 'decimal:2',
            'saldo' => 'decimal:2',
            'estado_cobro' => EstadoCobro::class,
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'orden_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoCuenta::class, 'cuenta_id')
            ->orderByDesc('fecha_pago')
            ->orderByDesc('id');
    }

    public function pagosVigentes(): HasMany
    {
        return $this->hasMany(PagoCuenta::class, 'cuenta_id')
            ->whereNull('anulado_at');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Total cobrado considerando únicamente pagos vigentes (no anulados).
     */
    public function totalPagado(): string
    {
        if ($this->relationLoaded('pagosVigentes')) {
            return Dinero::sumar(...$this->pagosVigentes->pluck('monto')->all());
        }

        if ($this->relationLoaded('pagos')) {
            $vigentes = $this->pagos
                ->filter(fn (PagoCuenta $pago): bool => $pago->anulado_at === null)
                ->pluck('monto')
                ->all();

            return Dinero::sumar(...$vigentes);
        }

        return Dinero::sumar(...$this->pagosVigentes()->pluck('monto')->all());
    }

    public function estaVencida(): bool
    {
        if (Dinero::comparar($this->saldo, 0) <= 0 || $this->fecha_vencimiento === null) {
            return false;
        }

        return $this->fecha_vencimiento->toDateString() < today()->toDateString();
    }

    public function diasVencida(): int
    {
        if (! $this->estaVencida()) {
            return 0;
        }

        return (int) $this->fecha_vencimiento->startOfDay()->diffInDays(today(), true);
    }

    /**
     * Recalcula el saldo a partir del total y de los pagos vigentes.
     */
    public function recalcularSaldo(): self
    {
        $saldo = Dinero::restar($this->monto_total, $this->totalPagado());

        if (Dinero::comparar($saldo, 0) < 0) {
            $saldo = Dinero::redondear(0);
        }

        if (Dinero::mayorQue($saldo, $this->monto_total)) {
            $saldo = Dinero::redondear($this->monto_total);
        }

        $this->saldo = $saldo;

        return $this;
    }

    /**
     * Deriva el estado de cobro a partir del saldo actual.
     */
    public function actualizarEstado(): self
    {
        $saldo = Dinero::redondear($this->saldo);

        $this->estado_cobro = match (true) {
            Dinero::esCero($saldo) => EstadoCobro::Pagada,
            Dinero::comparar($saldo, $this->monto_total) >= 0 => EstadoCobro::Pendiente,
            default => EstadoCobro::Parcial,
        };

        return $this;
    }
}
