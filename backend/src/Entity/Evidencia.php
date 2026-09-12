<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\{ApiProperty, ApiResource, Get, GetCollection};
use App\Enum\TipoEvidencia;
use App\Repository\EvidenciaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: EvidenciaRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [new GetCollection(uriTemplate: '/evidencias'), new Get(uriTemplate: '/evidencias/{id}'), new \ApiPlatform\Metadata\Post(uriTemplate: '/evidencias', validate: false, processor: \App\State\Processor\EvidenciaProcessor::class)])]
class Evidencia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'evidencias')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?RegistroAPPCC $registro = null;

    #[ORM\ManyToOne(inversedBy: 'evidencias')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Incidencia $incidencia = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    #[ApiProperty(writable: false)]
    private ?Usuario $subidaPor = null;

    #[ORM\Column(length: 20, enumType: TipoEvidencia::class)]
    #[Assert\NotNull]
    private ?TipoEvidencia $tipo = null;

    #[ORM\Column(length: 512)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 512)]
    private string $storageKey = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $nombreOriginal = '';

    #[ORM\Column(length: 127)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 127)]
    private string $mimeType = '';

    #[ORM\Column(type: Types::BIGINT)]
    #[Assert\Positive]
    private int $tamanoBytes = 0;

    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Length(exactly: 64)]
    #[Assert\Regex(pattern: '/^[a-fA-F0-9]{64}$/D')]
    private ?string $hashSha256 = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRegistro(): ?RegistroAPPCC { return $this->registro; }

    public function setRegistro(?RegistroAPPCC $registro): static
    {
        if ($this->registro === $registro) { return $this; }
        $previous = $this->registro;
        $this->registro = $registro;
        $previous?->removeEvidencia($this);
        $registro?->addEvidencia($this);

        return $this;
    }

    public function getIncidencia(): ?Incidencia { return $this->incidencia; }

    public function setIncidencia(?Incidencia $incidencia): static
    {
        if ($this->incidencia === $incidencia) { return $this; }
        $previous = $this->incidencia;
        $this->incidencia = $incidencia;
        $previous?->removeEvidencia($this);
        $incidencia?->addEvidencia($this);

        return $this;
    }

    public function getSubidaPor(): ?Usuario { return $this->subidaPor; }

    public function setSubidaPor(?Usuario $subidaPor): static
    {
        $this->subidaPor = $subidaPor;

        return $this;
    }

    public function getTipo(): ?TipoEvidencia { return $this->tipo; }

    public function setTipo(?TipoEvidencia $tipo): static
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getStorageKey(): string { return $this->storageKey; }

    public function setStorageKey(string $storageKey): static
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getNombreOriginal(): string { return $this->nombreOriginal; }

    public function setNombreOriginal(string $nombreOriginal): static
    {
        $this->nombreOriginal = $nombreOriginal;

        return $this;
    }

    public function getMimeType(): string { return $this->mimeType; }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getTamanoBytes(): int { return $this->tamanoBytes; }

    public function setTamanoBytes(int $tamanoBytes): static
    {
        $this->tamanoBytes = $tamanoBytes;

        return $this;
    }

    public function getHashSha256(): ?string { return $this->hashSha256; }

    public function setHashSha256(?string $hashSha256): static
    {
        $this->hashSha256 = $hashSha256;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    #[ORM\PrePersist]
    public function initializeTimestamp(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (($this->registro === null) === ($this->incidencia === null)) {
            $context->buildViolation('La evidencia debe pertenecer exactamente a un registro o a una incidencia.')->atPath('registro')->addViolation();
        }
    }
}
