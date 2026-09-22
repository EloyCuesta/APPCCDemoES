<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\TipoActividad;
use App\Repository\PlantillaAPPCCRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PlantillaAPPCCRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_plantilla_codigo', columns: ['codigo'])]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/plantillas-appcc'),
    new Get(uriTemplate: '/plantillas-appcc/{id}'),
    new Post(uriTemplate: '/plantillas-appcc/{id}/aplicar', status: 200,
        input: \App\Dto\AplicarPlantillaInput::class, output: \App\Dto\AplicarPlantillaOutput::class,
        read: false, securityPostDenormalize: "is_granted('ROLE_USER')",
        denormalizationContext: ['allow_extra_attributes' => false],
        processor: \App\State\Processor\AplicarPlantillaProcessor::class,
        openapi: new \ApiPlatform\OpenApi\Model\Operation(summary: 'Aplicar una plantilla al establecimiento seleccionado (ADMIN o RESPONSABLE).',
            parameters: [new \ApiPlatform\OpenApi\Model\Parameter(name: 'X-Establecimiento-Id', in: 'header', required: true, schema: ['type' => 'integer', 'minimum' => 1])])),
])]
class PlantillaAPPCC
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[ApiProperty(writable: false, description: 'Código estable y versionado del catálogo inicial.')]
    private ?string $codigo = null;

    public function getCodigo(): ?string { return $this->codigo; }
    public function setCodigo(string $codigo): static { $this->codigo = $codigo; return $this; }

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'El campo nombre es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 180, maxMessage: 'El campo nombre no puede superar {{ limit }} caracteres.')]
    private string $nombre = '';

    #[ORM\Column(enumType: TipoActividad::class)]
    #[Assert\NotNull(message: 'El campo tipo de actividad es obligatorio.')]
    private ?TipoActividad $tipoActividad = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descripcion = null;

    /** @var array<string|int, mixed> */
    #[ORM\Column(type: Types::JSON)]
    #[ApiProperty(description: 'Configuración inicial de la plantilla; no contiene registros APPCC reales.')]
    private array $configuracion = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $activa = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): static
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getTipoActividad(): ?TipoActividad
    {
        return $this->tipoActividad;
    }

    public function setTipoActividad(?TipoActividad $tipoActividad): static
    {
        $this->tipoActividad = $tipoActividad;

        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    /** @return array<string|int, mixed> */
    public function getConfiguracion(): array
    {
        return $this->configuracion;
    }

    /** @param array<string|int, mixed> $configuracion */
    public function setConfiguracion(array $configuracion): static
    {
        $this->configuracion = $configuracion;

        return $this;
    }

    public function isActiva(): bool
    {
        return $this->activa;
    }

    public function setActiva(bool $activa): static
    {
        $this->activa = $activa;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
