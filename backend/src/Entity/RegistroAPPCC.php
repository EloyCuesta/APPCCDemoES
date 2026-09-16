<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\RegistroAPPCCRepository;
use App\State\Processor\RegistrarControlProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Index(name: 'idx_registro_local_fecha', columns: ['establecimiento_id', 'fecha_hora'])]
#[ORM\Index(name: 'idx_registro_usuario_fecha', columns: ['usuario_id', 'fecha_hora'])]
#[ORM\Index(name: 'idx_registro_confirmado_por', columns: ['confirmado_por_id'])]
#[ORM\Entity(repositoryClass: RegistroAPPCCRepository::class)]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/registros'),
    new Post(uriTemplate: '/registros', input: \App\Dto\CrearRegistroInput::class, validate: false,
        denormalizationContext: ['allow_extra_attributes' => false], securityPostDenormalize: "is_granted('ROLE_USER')", processor: RegistrarControlProcessor::class),
    new Get(uriTemplate: '/registros/{id}'),
])]
class RegistroAPPCC
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'registro')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull]
    private ?TareaProgramada $tareaProgramada = null;

    #[ORM\ManyToOne(inversedBy: 'registros')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo establecimiento es obligatorio.')]
    private ?Establecimiento $establecimiento = null;

    #[ORM\ManyToOne(inversedBy: 'registros')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writable: false)]
    #[Assert\NotNull(message: 'El campo usuario es obligatorio.')]
    private ?Usuario $usuario = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fechaHora;

    #[ORM\Column()]
    #[ApiProperty(required: false, description: 'Calculado para controles numéricos y booleanos; evaluación manual para controles con campos estructurados.')]
    #[Assert\NotNull(message: 'Debe indicar si el registro es conforme.')]
    private ?bool $conforme = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    #[Assert\NotBlank(allowNull: true, message: 'El valor numérico debe ser un decimal o null.')]
    #[Assert\Regex(pattern: '/^-?\\d{1,9}(?:\\.\\d{1,3})?$/D', message: 'El campo valor numérico debe ser un decimal con hasta 9 cifras enteras y 3 decimales.')]
    private ?string $valorNumerico = null;

    /** @var array<string|int, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $datos = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observaciones = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $confirmadoAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    #[ApiProperty(writable: false, readableLink: false)]
    private ?Usuario $confirmadoPor = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?string $versionDeclaracionFirma = null;

    public function getConfirmadoAt(): ?\DateTimeImmutable { return $this->confirmadoAt; }
    public function getConfirmadoPor(): ?Usuario { return $this->confirmadoPor; }
    public function getVersionDeclaracionFirma(): ?string { return $this->versionDeclaracionFirma; }

    public function confirmar(Usuario $usuario, \DateTimeImmutable $ahora): void
    {
        if ($this->id !== null || $this->confirmadoAt !== null || $usuario !== $this->usuario) {
            throw new \App\Exception\BusinessRuleException('La confirmación solo puede establecerse al crear el registro, por su autor.');
        }
        $this->confirmadoPor = $usuario;
        $this->confirmadoAt = $ahora;
        $this->versionDeclaracionFirma = '1';
    }

    /** @var Collection<int, Incidencia> */
    #[ORM\OneToMany(mappedBy: 'registro', targetEntity: Incidencia::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $incidencias;

    /** @var Collection<int, Evidencia> */
    #[ORM\OneToMany(mappedBy: 'registro', targetEntity: Evidencia::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $evidencias;

    public function __construct()
    {
        $this->fechaHora = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->evidencias = new ArrayCollection();
        $this->incidencias = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Acceso de lectura a la definición; la relación operativa es tareaProgramada. */
    #[ApiProperty(writable: false)]
    public function getTarea(): ?TareaAPPCC
    {
        return $this->tareaProgramada?->getTarea();
    }

    public function getTareaProgramada(): ?TareaProgramada { return $this->tareaProgramada; }

    public function setTareaProgramada(?TareaProgramada $tareaProgramada): static
    {
        if ($this->tareaProgramada === $tareaProgramada) { return $this; }
        if ($this->tareaProgramada !== null) {
            throw new \App\Exception\BusinessRuleException('No se puede reemplazar la ejecución de un registro.');
        }
        if ($tareaProgramada?->getRegistro() !== null && $tareaProgramada->getRegistro() !== $this) {
            throw new \App\Exception\BusinessRuleException('La ejecución ya tiene un registro.');
        }
        $this->tareaProgramada = $tareaProgramada;
        $tareaProgramada?->setRegistro($this);
        return $this;
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
        $previous?->removeRegistro($this);
        $establecimiento?->addRegistro($this);

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
        $previous?->removeRegistro($this);
        $usuario?->addRegistro($this);

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

    public function isConforme(): ?bool
    {
        return $this->conforme;
    }

    public function setConforme(?bool $conforme): static
    {
        $this->conforme = $conforme;

        return $this;
    }

    public function getValorNumerico(): ?string
    {
        return $this->valorNumerico;
    }

    public function setValorNumerico(?string $valorNumerico): static
    {
        $this->valorNumerico = $valorNumerico;

        return $this;
    }

    /** @return array<string|int, mixed>|null */
    public function getDatos(): ?array
    {
        return $this->datos;
    }

    /** @param array<string|int, mixed>|null $datos */
    public function setDatos(?array $datos): static
    {
        $this->datos = $datos;

        return $this;
    }

    public function getObservaciones(): ?string
    {
        return $this->observaciones;
    }

    public function setObservaciones(?string $observaciones): static
    {
        $this->observaciones = $observaciones;

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

    /** @return Collection<int, Incidencia> */
    public function getIncidencias(): Collection
    {
        return $this->incidencias;
    }

    public function addIncidencia(Incidencia $item): static
    {
        if (!$this->incidencias->contains($item)) {
            $this->incidencias->add($item);
            $item->setRegistro($this);
        }

        return $this;
    }

    public function removeIncidencia(Incidencia $item): static
    {
        if ($this->incidencias->removeElement($item) && $item->getRegistro() === $this) {
            $item->setRegistro(null);
        }

        return $this;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context, mixed $payload): void
    {
        $relatedEstablecimiento = $this->tareaProgramada?->getEstablecimiento();
        if ($this->establecimiento !== null && $relatedEstablecimiento !== null
            && $this->establecimiento !== $relatedEstablecimiento
            && ($this->establecimiento->getId() === null || $this->establecimiento->getId() !== $relatedEstablecimiento->getId())
        ) {
            $context->buildViolation('El campo tarea debe pertenecer al mismo establecimiento.')
                ->atPath('tarea')
                ->addViolation();
        }
    }

    /** @return Collection<int, Evidencia> */
    public function getEvidencias(): Collection { return $this->evidencias; }

    public function addEvidencia(Evidencia $item): static
    {
        if (!$this->evidencias->contains($item)) { $this->evidencias->add($item); $item->setRegistro($this); }
        return $this;
    }

    public function removeEvidencia(Evidencia $item): static
    {
        if ($this->evidencias->removeElement($item) && $item->getRegistro() === $this) { $item->setRegistro(null); }
        return $this;
    }
}
