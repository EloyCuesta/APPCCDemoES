<?php
declare(strict_types=1);
namespace App\Dto;

use App\Enum\RolEstablecimiento;
use Symfony\Component\Validator\Constraints as Assert;

final class CambiarRolInput
{
    #[Assert\NotNull]
    public ?RolEstablecimiento $rol = null;
}
