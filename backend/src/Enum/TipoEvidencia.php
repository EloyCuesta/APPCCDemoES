<?php

declare(strict_types=1);

namespace App\Enum;

enum TipoEvidencia: string
{
    case FOTO = 'foto';
    case DOCUMENTO = 'documento';
    case FIRMA = 'firma';
}
