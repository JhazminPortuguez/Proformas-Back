<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoSolicitud;
use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolicitudCotizacion extends Model
{
    use HasFactory, PerteneceAEmpresa;

    protected $table = 'solicitudes_cotizacion';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'descripcion',
        'estado',
        'convertida_proforma_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoSolicitud::class,
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SolicitudItem::class, 'solicitud_id');
    }

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class, 'convertida_proforma_id');
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
