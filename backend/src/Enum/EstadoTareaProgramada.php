<?php

declare(strict_types=1);

namespace App\Enum;

enum EstadoTareaProgramada: string
{
    case PENDIENTE = 'pendiente';
    case COMPLETADA = 'completada';
    case OMITIDA = 'omitida';
    case VENCIDA = 'vencida';
}
