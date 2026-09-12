<?php

declare(strict_types=1);

namespace App\Enum;

enum EstadoIncidencia: string
{
    case ABIERTA = 'abierta';
    case EN_PROCESO = 'en_proceso';
    case RESUELTA = 'resuelta';
}
