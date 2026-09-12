<?php

declare(strict_types=1);

namespace App\Enum;

enum RolEstablecimiento: string
{
    case ADMIN = 'admin';
    case RESPONSABLE = 'responsable';
    case TRABAJADOR = 'trabajador';
    case AUDITOR = 'auditor';
}
