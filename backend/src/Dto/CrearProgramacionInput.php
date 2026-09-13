<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CrearProgramacionInput
{
    #[Assert\NotBlank]
    public string $fechaProgramada = '';
    public ?string $fechaLimite = null;
    public ?string $asignadoA = null;
}
