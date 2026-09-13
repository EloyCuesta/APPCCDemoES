<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

/** Credencial de un solo uso. No es un recurso API. */
#[ORM\Entity]
class TokenConfiguracionPassword
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Usuario $usuario;
    #[ORM\Column(length: 64, unique: true), Ignore]
    private string $tokenHash;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Usuario $usuario, string $tokenHash, \DateTimeImmutable $ahora)
    {
        $this->usuario = $usuario;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $ahora;
        $this->expiresAt = $ahora->modify('+24 hours');
    }
    public function getId(): ?int { return $this->id; }
    public function getUsuario(): Usuario { return $this->usuario; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getConsumedAt(): ?\DateTimeImmutable { return $this->consumedAt; }
    public function consumir(\DateTimeImmutable $ahora): void { $this->consumedAt = $ahora; }
}
