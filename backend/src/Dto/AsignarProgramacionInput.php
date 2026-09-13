<?php

declare(strict_types=1);

namespace App\Dto;

final class AsignarProgramacionInput
{
    // La ausencia se distingue del null explícito (desasignar).
    public ?string $usuario;
}
