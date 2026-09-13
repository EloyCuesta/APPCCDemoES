<?php
declare(strict_types=1);
namespace App\Dto;

use App\Enum\RolEstablecimiento;
use App\Service\Support\EmailUsuario;
use Symfony\Component\Validator\Constraints as Assert;

final class CrearInvitacionInput
{
    #[Assert\NotBlank, Assert\Length(max: 180), Assert\Email(normalizer: [EmailUsuario::class, 'normalizar'])]
    public string $email = '';
    #[Assert\NotNull]
    public ?RolEstablecimiento $rol = null;
}
