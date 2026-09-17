<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Api\{ConsultaCollection, BooleanoConsulta, OrdenConsulta};
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\TipoPuntoControl;
use App\Repository\PuntoControlRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: PuntoControlRepository::class)]
#[ApiResource(operations: [
    new ConsultaCollection(uriTemplate: '/puntos-control', parameters: [
        'activo' => new BooleanoConsulta('activo'),
        'order[nombre]' => new OrdenConsulta('nombre'),
    ], order: ['nombre' => 'ASC', 'id' => 'ASC']),
    new Post(uriTemplate: '/puntos-control'),
    new Get(uriTemplate: '/puntos-control/{id}'),
    new Patch(uriTemplate: '/puntos-control/{id}'),
])]
class PuntoControl
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'puntosControl')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo establecimiento es obligatorio.')]
    private ?Establecimiento $establecimiento = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'El campo nombre es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 180, maxMessage: 'El campo nombre no puede superar {{ limit }} caracteres.')]
    private string $nombre = '';

    #[ORM\Column(enumType: TipoPuntoControl::class)]
    #[Assert\NotNull(message: 'El campo tipo es obligatorio.')]
    private ?TipoPuntoControl $tipo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $activo = true;

    /** @var Collection<int, TareaAPPCC> */
    #[ORM\OneToMany(mappedBy: 'puntoControl', targetEntity: TareaAPPCC::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $tareas;

    public function __construct()
    {
        $this->tareas = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context, mixed $payload): void
    {
        foreach ($this->tareas as $tarea) {
            $taskEstablecimiento = $tarea->getEstablecimiento();
            if ($this->establecimiento !== null && $taskEstablecimiento !== null
                && $this->establecimiento !== $taskEstablecimiento
                && ($this->establecimiento->getId() === null || $this->establecimiento->getId() !== $taskEstablecimiento->getId())
            ) {
                $context->buildViolation('No se puede cambiar de establecimiento mientras existan tareas de otro establecimiento.')
                    ->atPath('establecimiento')
                    ->addViolation();

                break;
            }
        }
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
        $previous?->removePuntoControl($this);
        $establecimiento?->addPuntoControl($this);

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

    public function getTipo(): ?TipoPuntoControl
    {
        return $this->tipo;
    }

    public function setTipo(?TipoPuntoControl $tipo): static
    {
        $this->tipo = $tipo;

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

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): static
    {
        $this->activo = $activo;

        return $this;
    }

    /** @return Collection<int, TareaAPPCC> */
    public function getTareas(): Collection
    {
        return $this->tareas;
    }

    public function addTarea(TareaAPPCC $item): static
    {
        if (!$this->tareas->contains($item)) {
            $this->tareas->add($item);
            $item->setPuntoControl($this);
        }

        return $this;
    }

    public function removeTarea(TareaAPPCC $item): static
    {
        if ($this->tareas->removeElement($item) && $item->getPuntoControl() === $this) {
            $item->setPuntoControl(null);
        }

        return $this;
    }
}
