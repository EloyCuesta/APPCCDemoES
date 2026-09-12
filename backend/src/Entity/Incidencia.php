<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\GravedadIncidencia;
use App\Enum\EstadoIncidencia;
use App\Repository\IncidenciaRepository;
use App\State\Processor\IncidenciaProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\UniqueConstraint(name: 'uniq_incidencia_registro', columns: ['registro_id'], options: ['where' => '(registro_id IS NOT NULL)'])]
#[ORM\Index(name: 'idx_incidencia_agenda', columns: ['establecimiento_id', 'estado', 'fecha_apertura'])]
#[ORM\Entity(repositoryClass: IncidenciaRepository::class)]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/incidencias'),
    new Post(uriTemplate: '/incidencias', validate: false, processor: IncidenciaProcessor::class),
    new Get(uriTemplate: '/incidencias/{id}'),
    new Patch(uriTemplate: '/incidencias/{id}', validate: false, processor: IncidenciaProcessor::class),
])]
class Incidencia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'incidencias')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo establecimiento es obligatorio.')]
    private ?Establecimiento $establecimiento = null;

    #[ORM\ManyToOne(inversedBy: 'incidencias')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    private ?RegistroAPPCC $registro = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'El campo título es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 180, maxMessage: 'El campo título no puede superar {{ limit }} caracteres.')]
    private string $titulo = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'El campo descripción es obligatorio.', normalizer: 'trim')]
    private string $descripcion = '';

    #[ORM\Column(enumType: GravedadIncidencia::class)]
    #[Assert\NotNull(message: 'El campo gravedad es obligatorio.')]
    private ?GravedadIncidencia $gravedad = null;

    #[ORM\Column(enumType: EstadoIncidencia::class, options: ['default' => 'abierta'])]
    #[Assert\NotNull(message: 'El campo estado es obligatorio.')]
    private ?EstadoIncidencia $estado = EstadoIncidencia::ABIERTA;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $fechaApertura;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'fechaApertura', message: 'La fecha de cierre no puede ser anterior a la fecha de apertura.')]
    private ?\DateTimeImmutable $fechaCierre = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, AccionCorrectiva> */
    #[ORM\OneToMany(mappedBy: 'incidencia', targetEntity: AccionCorrectiva::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $accionesCorrectivas;

    /** @var Collection<int, Evidencia> */
    #[ORM\OneToMany(mappedBy: 'incidencia', targetEntity: Evidencia::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $evidencias;

    /** @var Collection<int, HistorialIncidencia> */
    #[ORM\OneToMany(mappedBy: 'incidencia', targetEntity: HistorialIncidencia::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $historial;

    public function __construct()
    {
        $this->fechaApertura = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->evidencias = new ArrayCollection();
        $this->historial = new ArrayCollection();
        $this->accionesCorrectivas = new ArrayCollection();
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
        $previous?->removeIncidencia($this);
        $establecimiento?->addIncidencia($this);

        return $this;
    }

    public function getRegistro(): ?RegistroAPPCC
    {
        return $this->registro;
    }

    public function setRegistro(?RegistroAPPCC $registro): static
    {
        if ($this->registro === $registro) {
            return $this;
        }

        $previous = $this->registro;
        $this->registro = $registro;
        $previous?->removeIncidencia($this);
        $registro?->addIncidencia($this);

        return $this;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): static
    {
        $this->titulo = $titulo;

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

    public function getGravedad(): ?GravedadIncidencia
    {
        return $this->gravedad;
    }

    public function setGravedad(?GravedadIncidencia $gravedad): static
    {
        $this->gravedad = $gravedad;

        return $this;
    }

    public function getEstado(): ?EstadoIncidencia
    {
        return $this->estado;
    }

    public function setEstado(?EstadoIncidencia $estado): static
    {
        $this->estado = $estado;

        return $this;
    }

    public function getFechaApertura(): \DateTimeImmutable
    {
        return $this->fechaApertura;
    }

    public function setFechaApertura(\DateTimeImmutable $fechaApertura): static
    {
        $this->fechaApertura = $fechaApertura;

        return $this;
    }

    public function getFechaCierre(): ?\DateTimeImmutable
    {
        return $this->fechaCierre;
    }

    public function setFechaCierre(?\DateTimeImmutable $fechaCierre): static
    {
        $this->fechaCierre = $fechaCierre;

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

    /** @return Collection<int, AccionCorrectiva> */
    public function getAccionesCorrectivas(): Collection
    {
        return $this->accionesCorrectivas;
    }

    public function addAccionCorrectiva(AccionCorrectiva $item): static
    {
        if (!$this->accionesCorrectivas->contains($item)) {
            $this->accionesCorrectivas->add($item);
            $item->setIncidencia($this);
        }

        return $this;
    }

    public function removeAccionCorrectiva(AccionCorrectiva $item): static
    {
        if ($this->accionesCorrectivas->removeElement($item) && $item->getIncidencia() === $this) {
            $item->setIncidencia(null);
        }

        return $this;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context, mixed $payload): void
    {
        $relatedEstablecimiento = $this->registro?->getEstablecimiento();
        if ($this->establecimiento !== null && $relatedEstablecimiento !== null
            && $this->establecimiento !== $relatedEstablecimiento
            && ($this->establecimiento->getId() === null || $this->establecimiento->getId() !== $relatedEstablecimiento->getId())
        ) {
            $context->buildViolation('El campo registro debe pertenecer al mismo establecimiento.')
                ->atPath('registro')
                ->addViolation();
        }
    }

    /** @return Collection<int, Evidencia> */
    public function getEvidencias(): Collection { return $this->evidencias; }

    public function addEvidencia(Evidencia $item): static
    {
        if (!$this->evidencias->contains($item)) { $this->evidencias->add($item); $item->setIncidencia($this); }
        return $this;
    }

    public function removeEvidencia(Evidencia $item): static
    {
        if ($this->evidencias->removeElement($item) && $item->getIncidencia() === $this) { $item->setIncidencia(null); }
        return $this;
    }

    /** @return Collection<int, HistorialIncidencia> */
    public function getHistorial(): Collection { return $this->historial; }

    public function addHistorial(HistorialIncidencia $item): void
    {
        if ($item->getIncidencia() !== $this) { throw new \InvalidArgumentException('Historial de otra incidencia.'); }
        if (!$this->historial->contains($item)) { $this->historial->add($item); }
    }
}
