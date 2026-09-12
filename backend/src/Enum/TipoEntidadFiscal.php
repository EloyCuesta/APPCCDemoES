<?php

declare(strict_types=1);

namespace App\Enum;

enum TipoEntidadFiscal: string
{
    case EMPRESA = 'empresa';
    case AUTONOMO = 'autonomo';
}
