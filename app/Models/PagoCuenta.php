<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodoPago;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoCuenta extends Model
{
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'pagos_cuenta';

    protected $fillable = [
        'empresa_id',
        'cuenta_id',
        'monto',
        'metodo',
        'fecha_pago',
        'referencia',
        'observaciones',
        'created_by',
        'anulado_at',
        'anulado_por',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'metodo' => MetodoPago::class,
            'fecha_pago' => 'date',
            'anulado_at' => 'datetime',
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaPorCobrar::class, 'cuenta_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function estaAnulado(): bool
    {
        return $this->anulado_at !== null;
    }
}
