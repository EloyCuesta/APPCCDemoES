<?php

declare(strict_types=1);

namespace App\Enum;

enum FrecuenciaTarea: string
{
    case DIARIA = 'diaria';
    case SEMANAL = 'semanal';
    case MENSUAL = 'mensual';
    case POR_TURNO = 'por_turno';
    case POR_RECEPCION = 'por_recepcion';
    case BAJO_DEMANDA = 'bajo_demanda';
}
