<?php

declare(strict_types=1);

namespace App\Enum;

enum GravedadIncidencia: string
{
    case BAJA = 'baja';
    case MEDIA = 'media';
    case ALTA = 'alta';
    case CRITICA = 'critica';
}
