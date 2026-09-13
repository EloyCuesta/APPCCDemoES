<?php
declare(strict_types=1);
namespace App\Enum;

enum EstadoInvitacion: string
{
    case PENDIENTE = 'pendiente';
    case ACEPTADA = 'aceptada';
    case CANCELADA = 'cancelada';
    case EXPIRADA = 'expirada';
}
