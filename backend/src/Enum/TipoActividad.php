<?php

declare(strict_types=1);

namespace App\Enum;

enum TipoActividad: string
{
    case RESTAURANTE = 'restaurante';
    case BAR_CAFETERIA = 'bar_cafeteria';
    case CARNICERIA = 'carniceria';
    case PESCADERIA = 'pescaderia';
    case PANADERIA = 'panaderia';
    case PASTELERIA = 'pasteleria';
    case OBRADOR = 'obrador';
    case HOTEL = 'hotel';
    case CATERING = 'catering';
    case COMERCIO_ALIMENTARIO = 'comercio_alimentario';
    case OTRO = 'otro';
}
