<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\ArchivoEvidencia;
use App\Enum\TipoEvidencia;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_subida_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_subida_expiracion', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_subida_usuario', columns: ['usuario_id'])]
#[ORM\Index(name: 'idx_subida_establecimiento', columns: ['establecimiento_id'])]
class SubidaTemporalEvidencia
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 64)]
    private string $tokenHash;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Usuario $usuario;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Establecimiento $establecimiento;
    #[ORM\Column(length: 20, enumType: TipoEvidencia::class)]
    private TipoEvidencia $tipo;
    #[ORM\Column(length: 512)]
    private string $storageKey;
    #[ORM\Column(length: 255)]
    private string $nombreOriginal;
    #[ORM\Column(length: 127)]
    private string $mimeType;
    #[ORM\Column(type: Types::BIGINT)]
    private int $tamanoBytes;
    #[ORM\Column(length: 64)]
    private string $hashSha256;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumidaAt = null;

    public function __construct(string $tokenHash, Usuario $usuario, Establecimiento $establecimiento, TipoEvidencia $tipo, ArchivoEvidencia $archivo, \DateTimeImmutable $expiresAt)
    {
        $this->tokenHash = $tokenHash;
        $this->usuario = $usuario;
        $this->establecimiento = $establecimiento;
        $this->tipo = $tipo;
        $this->storageKey = $archivo->storageKey;
        $this->nombreOriginal = $archivo->nombreOriginal;
        $this->mimeType = $archivo->mimeType;
        $this->tamanoBytes = $archivo->tamanoBytes;
        $this->hashSha256 = $archivo->hashSha256;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getUsuario(): Usuario { return $this->usuario; }
    public function getEstablecimiento(): Establecimiento { return $this->establecimiento; }
    public function getTipo(): TipoEvidencia { return $this->tipo; }
    public function getStorageKey(): string { return $this->storageKey; }
    public function getNombreOriginal(): string { return $this->nombreOriginal; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getTamanoBytes(): int { return $this->tamanoBytes; }
    public function getHashSha256(): string { return $this->hashSha256; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getConsumidaAt(): ?\DateTimeImmutable { return $this->consumidaAt; }
    public function consumir(\DateTimeImmutable $ahora): void
    {
        if ($this->consumidaAt !== null || $this->expiresAt <= $ahora) { throw new \App\Exception\BusinessRuleException('La subida temporal no está disponible.'); }
        $this->consumidaAt = $ahora;
    }
}
