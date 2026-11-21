<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoProformaEvento: string
{
    case CorreoEnviado = 'CORREO_ENVIADO';
    case CorreoFallido = 'CORREO_FALLIDO';
    case CorreoSinEmail = 'CORREO_SIN_EMAIL';
}
