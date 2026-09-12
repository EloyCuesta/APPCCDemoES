<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\AccionCorrectivaRepository;
use App\State\Processor\AccionCorrectivaProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AccionCorrectivaRepository::class)]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/acciones-correctivas'),
    new Post(uriTemplate: '/acciones-correctivas', validate: false, processor: AccionCorrectivaProcessor::class),
    new Get(uriTemplate: '/acciones-correctivas/{id}'),
])]
class AccionCorrectiva
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'accionesCorrectivas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo incidencia es obligatorio.')]
    private ?Incidencia $incidencia = null;

    #[ORM\ManyToOne(inversedBy: 'accionesCorrectivas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writable: false)]
    #[Assert\NotNull(message: 'El campo usuario es obligatorio.')]
    private ?Usuario $usuario = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'El campo descripción es obligatorio.', normalizer: 'trim')]
    private string $descripcion = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultado = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fechaHora;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->fechaHora = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidencia(): ?Incidencia
    {
        return $this->incidencia;
    }

    public function setIncidencia(?Incidencia $incidencia): static
    {
        if ($this->incidencia === $incidencia) {
            return $this;
        }

        $previous = $this->incidencia;
        $this->incidencia = $incidencia;
        $previous?->removeAccionCorrectiva($this);
        $incidencia?->addAccionCorrectiva($this);

        return $this;
    }

    public function getUsuario(): ?Usuario
    {
        return $this->usuario;
    }

    public function setUsuario(?Usuario $usuario): static
    {
        if ($this->usuario === $usuario) {
            return $this;
        }

        $previous = $this->usuario;
        $this->usuario = $usuario;
        $previous?->removeAccionCorrectiva($this);
        $usuario?->addAccionCorrectiva($this);

        return $this;
    }

    public function getDescripcion(): string
    {
        return $this->descripcion;
    }

    public function setDescripcion(string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getResultado(): ?string
    {
        return $this->resultado;
    }

    public function setResultado(?string $resultado): static
    {
        $this->resultado = $resultado;

        return $this;
    }

    public function getFechaHora(): \DateTimeImmutable
    {
        return $this->fechaHora;
    }

    public function setFechaHora(\DateTimeImmutable $fechaHora): static
    {
        $this->fechaHora = $fechaHora;

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
