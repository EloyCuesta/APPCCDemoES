<?php

declare(strict_types=1);

namespace App\Enum;

enum TipoPuntoControl: string
{
    case CAMARA_FRIGORIFICA = 'camara_frigorifica';
    case CONGELADOR = 'congelador';
    case ALMACEN = 'almacen';
    case COCINA = 'cocina';
    case RECEPCION = 'recepcion';
    case LAVAVAJILLAS = 'lavavajillas';
    case EQUIPO = 'equipo';
    case ZONA = 'zona';
    case OTRO = 'otro';
}
