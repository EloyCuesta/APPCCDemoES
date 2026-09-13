<?php
declare(strict_types=1);
namespace App\Dto;

final readonly class AltaUsuarioOutput
{
    public function __construct(public int $usuarioId, public int $establecimientoId, public int $membresiaId,
        public ?string $tokenConfiguracionPassword, public ?\DateTimeImmutable $passwordExpiresAt) {}
}
