<?php
declare(strict_types=1);
namespace App\Dto;

final readonly class InvitacionCreadaOutput
{
    public function __construct(public int $id, public string $email, public string $rol,
        public string $token, public \DateTimeImmutable $expiresAt) {}
}
