<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\FrecuenciaTarea;
use App\Repository\TareaAPPCCRepository;
use App\State\Processor\TareaAPPCCProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: TareaAPPCCRepository::class)]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/tareas'),
    new Post(uriTemplate: '/tareas'),
    new Get(uriTemplate: '/tareas/{id}'),
    new Patch(uriTemplate: '/tareas/{id}', processor: TareaAPPCCProcessor::class),
])]
class TareaAPPCC
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tareas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo plan de control es obligatorio.')]
    private ?PlanControl $planControl = null;

    #[ORM\ManyToOne(inversedBy: 'tareas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo establecimiento es obligatorio.')]
    private ?Establecimiento $establecimiento = null;

    #[ORM\ManyToOne(inversedBy: 'tareas')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    private ?PuntoControl $puntoControl = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'El campo nombre es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 180, maxMessage: 'El campo nombre no puede superar {{ limit }} caracteres.')]
    private string $nombre = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(enumType: FrecuenciaTarea::class)]
    #[Assert\NotNull(message: 'El campo frecuencia es obligatorio.')]
    private ?FrecuenciaTarea $frecuencia = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'H:i:s'])]
    private ?\DateTimeImmutable $horaPrevista = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    #[Assert\NotBlank(allowNull: true, message: 'El límite mínimo debe ser un decimal o null.')]
    #[Assert\Regex(pattern: '/^-?\\d{1,9}(?:\\.\\d{1,3})?$/D', message: 'El campo límite mínimo debe ser un decimal con hasta 9 cifras enteras y 3 decimales.')]
    private ?string $limiteMinimo = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    #[Assert\NotBlank(allowNull: true, message: 'El límite máximo debe ser un decimal o null.')]
    #[Assert\Regex(pattern: '/^-?\\d{1,9}(?:\\.\\d{1,3})?$/D', message: 'El campo límite máximo debe ser un decimal con hasta 9 cifras enteras y 3 decimales.')]
    private ?string $limiteMaximo = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: 'El campo unidad no puede superar {{ limit }} caracteres.')]
    private ?string $unidad = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instrucciones = null;

    /** @var array<string|int, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $configuracion = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $obligatoria = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $activa = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, RegistroAPPCC> */
    #[ORM\OneToMany(mappedBy: 'tarea', targetEntity: RegistroAPPCC::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $registros;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->registros = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanControl(): ?PlanControl
    {
        return $this->planControl;
    }

    public function setPlanControl(?PlanControl $planControl): static
    {
        if ($this->planControl === $planControl) {
            return $this;
        }

        $previous = $this->planControl;
        $this->planControl = $planControl;
        $previous?->removeTarea($this);
        $planControl?->addTarea($this);

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
        $previous?->removeTarea($this);
        $establecimiento?->addTarea($this);

        return $this;
    }

    public function getPuntoControl(): ?PuntoControl
    {
        return $this->puntoControl;
    }

    public function setPuntoControl(?PuntoControl $puntoControl): static
    {
        if ($this->puntoControl === $puntoControl) {
            return $this;
        }

        $previous = $this->puntoControl;
        $this->puntoControl = $puntoControl;
        $previous?->removeTarea($this);
        $puntoControl?->addTarea($this);

        return $this;
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

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getFrecuencia(): ?FrecuenciaTarea
    {
        return $this->frecuencia;
    }

    public function setFrecuencia(?FrecuenciaTarea $frecuencia): static
    {
        $this->frecuencia = $frecuencia;

        return $this;
    }

    public function getHoraPrevista(): ?\DateTimeImmutable
    {
        return $this->horaPrevista;
    }

    public function setHoraPrevista(?\DateTimeImmutable $horaPrevista): static
    {
        $this->horaPrevista = $horaPrevista;

        return $this;
    }

    public function getLimiteMinimo(): ?string
    {
        return $this->limiteMinimo;
    }

    public function setLimiteMinimo(?string $limiteMinimo): static
    {
        $this->limiteMinimo = $limiteMinimo;

        return $this;
    }

    public function getLimiteMaximo(): ?string
    {
        return $this->limiteMaximo;
    }

    public function setLimiteMaximo(?string $limiteMaximo): static
    {
        $this->limiteMaximo = $limiteMaximo;

        return $this;
    }

    public function getUnidad(): ?string
    {
        return $this->unidad;
    }

    public function setUnidad(?string $unidad): static
    {
        $this->unidad = $unidad;

        return $this;
    }

    public function getInstrucciones(): ?string
    {
        return $this->instrucciones;
    }

    public function setInstrucciones(?string $instrucciones): static
    {
        $this->instrucciones = $instrucciones;

        return $this;
    }

    /** @return array<string|int, mixed>|null */
    public function getConfiguracion(): ?array
    {
        return $this->configuracion;
    }

    /** @param array<string|int, mixed>|null $configuracion */
    public function setConfiguracion(?array $configuracion): static
    {
        $this->configuracion = $configuracion;

        return $this;
    }

    public function isObligatoria(): bool
    {
        return $this->obligatoria;
    }

    public function setObligatoria(bool $obligatoria): static
    {
        $this->obligatoria = $obligatoria;

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

    /** @return Collection<int, RegistroAPPCC> */
    public function getRegistros(): Collection
    {
        return $this->registros;
    }

    public function addRegistro(RegistroAPPCC $item): static
    {
        if (!$this->registros->contains($item)) {
            $this->registros->add($item);
            $item->setTarea($this);
        }

        return $this;
    }

    public function removeRegistro(RegistroAPPCC $item): static
    {
        if ($this->registros->removeElement($item) && $item->getTarea() === $this) {
            $item->setTarea(null);
        }

        return $this;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context, mixed $payload): void
    {
        $relatedEstablecimiento = $this->planControl?->getEstablecimiento();
        if ($this->establecimiento !== null && $relatedEstablecimiento !== null
            && $this->establecimiento !== $relatedEstablecimiento
            && ($this->establecimiento->getId() === null || $this->establecimiento->getId() !== $relatedEstablecimiento->getId())
        ) {
            $context->buildViolation('El campo plan de control debe pertenecer al mismo establecimiento.')
                ->atPath('planControl')
                ->addViolation();
        }
        $relatedEstablecimiento = $this->puntoControl?->getEstablecimiento();
        if ($this->establecimiento !== null && $relatedEstablecimiento !== null
            && $this->establecimiento !== $relatedEstablecimiento
            && ($this->establecimiento->getId() === null || $this->establecimiento->getId() !== $relatedEstablecimiento->getId())
        ) {
            $context->buildViolation('El campo punto de control debe pertenecer al mismo establecimiento.')
                ->atPath('puntoControl')
                ->addViolation();
        }

        if (is_numeric($this->limiteMinimo) && is_numeric($this->limiteMaximo) && $this->limiteMinimo > $this->limiteMaximo) {
            $context->buildViolation('El límite máximo no puede ser menor que el límite mínimo.')
                ->atPath('limiteMaximo')
                ->addViolation();
        }

        foreach ($this->registros as $registro) {
            $recordEstablecimiento = $registro->getEstablecimiento();
            if ($this->establecimiento !== null && $recordEstablecimiento !== null
                && $this->establecimiento !== $recordEstablecimiento
                && ($this->establecimiento->getId() === null || $this->establecimiento->getId() !== $recordEstablecimiento->getId())
            ) {
                $context->buildViolation('No se puede trasladar una tarea con registros de otro establecimiento.')
                    ->atPath('establecimiento')
                    ->addViolation();

                break;
            }
        }
    }
}
