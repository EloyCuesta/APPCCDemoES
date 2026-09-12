<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\ConfiguracionEstablecimientoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConfiguracionEstablecimientoRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['establecimiento'], message: 'El establecimiento ya tiene una configuración.')]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/configuraciones-establecimiento'),
    new Post(uriTemplate: '/configuraciones-establecimiento'),
    new Get(uriTemplate: '/configuraciones-establecimiento/{id}'),
    new Patch(uriTemplate: '/configuraciones-establecimiento/{id}'),
])]
class ConfiguracionEstablecimiento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'configuracion')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'Debe indicar el establecimiento.')]
    private ?Establecimiento $establecimiento = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'H:i:s'])]
    private ?\DateTimeImmutable $horaInicioJornada = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'H:i:s'])]
    private ?\DateTimeImmutable $horaFinJornada = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $requiereFirmaRegistro = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $permiteRegistrosAtrasados = false;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'El máximo de minutos de registro atrasado debe ser positivo o cero.')]
    #[ApiProperty(description: 'Límite configurable para cuando se permitan registros atrasados; no aplica todavía reglas temporales.')]
    private ?int $maximoMinutosRegistroAtrasado = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $generaIncidenciaAutomatica = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $requiereObservacionNoConforme = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $requiereFotoNoConforme = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $permitirCerrarIncidenciaSinAccion = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $avisarTareasPendientes = true;

    #[ORM\Column(options: ['default' => 30])]
    #[Assert\PositiveOrZero(message: 'Los minutos de aviso deben ser positivos o cero.')]
    private int $minutosAvisoTarea = 30;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEstablecimiento(): ?Establecimiento
    {
        return $this->establecimiento;
    }

    public function setEstablecimiento(?Establecimiento $establecimiento): static
    {
        if ($this->establecimiento === $establecimiento) {
            return $this;
        }

        $previous = $this->establecimiento;
        $this->establecimiento = $establecimiento;

        if ($previous !== null && $previous->getConfiguracion() === $this) {
            $previous->setConfiguracion(null);
        }

        if ($establecimiento !== null && $establecimiento->getConfiguracion() !== $this) {
            $establecimiento->setConfiguracion($this);
        }

        return $this;
    }

    public function getHoraInicioJornada(): ?\DateTimeImmutable
    {
        return $this->horaInicioJornada;
    }

    public function setHoraInicioJornada(?\DateTimeImmutable $horaInicioJornada): static
    {
        $this->horaInicioJornada = $horaInicioJornada;

        return $this;
    }

    public function getHoraFinJornada(): ?\DateTimeImmutable
    {
        return $this->horaFinJornada;
    }

    public function setHoraFinJornada(?\DateTimeImmutable $horaFinJornada): static
    {
        $this->horaFinJornada = $horaFinJornada;

        return $this;
    }

    public function isRequiereFirmaRegistro(): bool
    {
        return $this->requiereFirmaRegistro;
    }

    public function setRequiereFirmaRegistro(bool $requiereFirmaRegistro): static
    {
        $this->requiereFirmaRegistro = $requiereFirmaRegistro;

        return $this;
    }

    public function isPermiteRegistrosAtrasados(): bool
    {
        return $this->permiteRegistrosAtrasados;
    }

    public function setPermiteRegistrosAtrasados(bool $permiteRegistrosAtrasados): static
    {
        $this->permiteRegistrosAtrasados = $permiteRegistrosAtrasados;

        return $this;
    }

    public function getMaximoMinutosRegistroAtrasado(): ?int
    {
        return $this->maximoMinutosRegistroAtrasado;
    }

    public function setMaximoMinutosRegistroAtrasado(?int $maximoMinutosRegistroAtrasado): static
    {
        $this->maximoMinutosRegistroAtrasado = $maximoMinutosRegistroAtrasado;

        return $this;
    }

    public function isGeneraIncidenciaAutomatica(): bool
    {
        return $this->generaIncidenciaAutomatica;
    }

    public function setGeneraIncidenciaAutomatica(bool $generaIncidenciaAutomatica): static
    {
        $this->generaIncidenciaAutomatica = $generaIncidenciaAutomatica;

        return $this;
    }

    public function isRequiereObservacionNoConforme(): bool
    {
        return $this->requiereObservacionNoConforme;
    }

    public function setRequiereObservacionNoConforme(bool $requiereObservacionNoConforme): static
    {
        $this->requiereObservacionNoConforme = $requiereObservacionNoConforme;

        return $this;
    }

    public function isRequiereFotoNoConforme(): bool
    {
        return $this->requiereFotoNoConforme;
    }

    public function setRequiereFotoNoConforme(bool $requiereFotoNoConforme): static
    {
        $this->requiereFotoNoConforme = $requiereFotoNoConforme;

        return $this;
    }

    public function isPermitirCerrarIncidenciaSinAccion(): bool
    {
        return $this->permitirCerrarIncidenciaSinAccion;
    }

    public function setPermitirCerrarIncidenciaSinAccion(bool $permitirCerrarIncidenciaSinAccion): static
    {
        $this->permitirCerrarIncidenciaSinAccion = $permitirCerrarIncidenciaSinAccion;

        return $this;
    }

    public function isAvisarTareasPendientes(): bool
    {
        return $this->avisarTareasPendientes;
    }

    public function setAvisarTareasPendientes(bool $avisarTareasPendientes): static
    {
        $this->avisarTareasPendientes = $avisarTareasPendientes;

        return $this;
    }

    public function getMinutosAvisoTarea(): int
    {
        return $this->minutosAvisoTarea;
    }

    public function setMinutosAvisoTarea(int $minutosAvisoTarea): static
    {
        $this->minutosAvisoTarea = $minutosAvisoTarea;

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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
