<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\{ApiProperty, ApiResource, Get, Link};
use App\Api\{ConsultaCollection, IriConsulta, EnumConsulta};
use App\Enum\TipoEvidencia;
use App\Repository\EvidenciaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: EvidenciaRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [
    new ConsultaCollection(uriTemplate: '/evidencias', parameters: [
        'registro' => new IriConsulta('registro', '/api/registros'),
        'incidencia' => new IriConsulta('incidencia', '/api/incidencias'),
        'tipo' => new EnumConsulta('tipo', TipoEvidencia::class),
    ]),
    new ConsultaCollection(uriTemplate: '/registros/{id}/evidencias',
        uriVariables: ['id' => new Link(fromClass: RegistroAPPCC::class, toProperty: 'registro')], parameters: [
            'tipo' => new EnumConsulta('tipo', TipoEvidencia::class),
        ]),
    new Get(uriTemplate: '/evidencias/{id}', requirements: ['id' => '[1-9][0-9]*']),
], normalizationContext: ['groups' => ['evidencia:read']])]
class Evidencia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['evidencia:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'evidencias')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    #[Groups(['evidencia:read'])]
    private ?RegistroAPPCC $registro = null;

    #[ORM\ManyToOne(inversedBy: 'evidencias')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    #[Groups(['evidencia:read'])]
    private ?Incidencia $incidencia = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    #[ApiProperty(writable: false)]
    #[Groups(['evidencia:read'])]
    private ?Usuario $subidaPor = null;

    #[ORM\Column(length: 20, enumType: TipoEvidencia::class)]
    #[Assert\NotNull]
    #[Groups(['evidencia:read'])]
    private ?TipoEvidencia $tipo = null;

    #[ORM\Column(length: 512)]
    #[ApiProperty(readable: false, writable: false)]
    #[\Symfony\Component\Serializer\Attribute\Ignore]
    #[Assert\NotBlank]
    #[Assert\Length(max: 512)]
    private string $storageKey = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['evidencia:read'])]
    private string $nombreOriginal = '';

    #[ORM\Column(length: 127)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 127)]
    #[Groups(['evidencia:read'])]
    private string $mimeType = '';

    #[ORM\Column(type: Types::BIGINT)]
    #[Assert\Positive]
    #[Groups(['evidencia:read'])]
    private int $tamanoBytes = 0;

    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Length(exactly: 64)]
    #[Assert\Regex(pattern: '/^[a-fA-F0-9]{64}$/D')]
    #[Groups(['evidencia:read'])]
    private ?string $hashSha256 = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    #[Groups(['evidencia:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    #[ApiProperty(writable: false)]
    #[Groups(['evidencia:read'])]
    public function getDownloadUrl(): ?string { return $this->id === null ? null : '/api/evidencias/'.$this->id.'/descargar'; }

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
