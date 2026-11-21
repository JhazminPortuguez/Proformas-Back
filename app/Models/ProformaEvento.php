<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoProformaEvento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento del historial de una proforma que no cabe en sus columnas: por
 * ahora, cada intento de enviarla por correo.
 */
class ProformaEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'proforma_eventos';

    protected $fillable = [
        'proforma_id',
        'tipo',
        'destinatario',
        'detalle',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoProformaEvento::class,
            'created_at' => 'datetime',
        ];
    }

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class, 'proforma_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
