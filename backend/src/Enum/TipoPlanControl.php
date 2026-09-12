<?php

declare(strict_types=1);

namespace App\Enum;

enum TipoPlanControl: string
{
    case TEMPERATURAS = 'temperaturas';
    case LIMPIEZA = 'limpieza';
    case PLAGAS = 'plagas';
    case RECEPCION = 'recepcion';
    case TRAZABILIDAD = 'trazabilidad';
    case ALERGENOS = 'alergenos';
    case AGUA = 'agua';
    case RESIDUOS = 'residuos';
    case MANTENIMIENTO = 'mantenimiento';
    case ACEITE_FRITURA = 'aceite_fritura';
    case OTRO = 'otro';
}
