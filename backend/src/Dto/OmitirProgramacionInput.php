<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class OmitirProgramacionInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(min: 3, max: 2000, normalizer: 'trim')]
    public string $motivo = '';
}
