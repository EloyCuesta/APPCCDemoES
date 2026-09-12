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
use App\Repository\EstablecimientoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EstablecimientoRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/establecimientos'),
    new Post(uriTemplate: '/establecimientos'),
    new Get(uriTemplate: '/establecimientos/{id}'),
    new Patch(uriTemplate: '/establecimientos/{id}'),
])]
class Establecimiento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'establecimientos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo titular fiscal es obligatorio.')]
    private ?EntidadFiscal $entidadFiscal = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'El campo nombre es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 180, maxMessage: 'El campo nombre no puede superar {{ limit }} caracteres.')]
    private string $nombre = '';

    #[ORM\Column(enumType: TipoActividad::class)]
    #[Assert\NotNull(message: 'El campo tipo de actividad es obligatorio.')]
    private ?TipoActividad $tipoActividad = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'El campo dirección es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 255, maxMessage: 'El campo dirección no puede superar {{ limit }} caracteres.')]
    private string $direccion = '';

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank(message: 'El campo código postal es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 10, maxMessage: 'El campo código postal no puede superar {{ limit }} caracteres.')]
    private string $codigoPostal = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'El campo localidad es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 120, maxMessage: 'El campo localidad no puede superar {{ limit }} caracteres.')]
    private string $localidad = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'El campo provincia es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 120, maxMessage: 'El campo provincia no puede superar {{ limit }} caracteres.')]
    private string $provincia = '';

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: 'El campo teléfono no puede superar {{ limit }} caracteres.')]
    private ?string $telefono = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180, maxMessage: 'El campo correo electrónico no puede superar {{ limit }} caracteres.')]
    #[Assert\Email(message: 'El correo electrónico no es válido.')]
    private ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'El campo registro sanitario no puede superar {{ limit }} caracteres.')]
    private ?string $registroSanitario = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, UsuarioEstablecimiento> */
    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: UsuarioEstablecimiento::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $usuariosEstablecimiento;

    /** @var Collection<int, PlanControl> */
    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: PlanControl::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $planesControl;

    /** @var Collection<int, PuntoControl> */
    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: PuntoControl::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $puntosControl;

    /** @var Collection<int, TareaAPPCC> */
    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: TareaAPPCC::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $tareas;

    /** @var Collection<int, RegistroAPPCC> */
    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: RegistroAPPCC::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $registros;

    /** @var Collection<int, Incidencia> */
    #[ORM\OneToMany(mappedBy: 'establecimiento', targetEntity: Incidencia::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $incidencias;

    #[ORM\OneToOne(mappedBy: 'establecimiento', targetEntity: ConfiguracionEstablecimiento::class)]
    #[ApiProperty(readable: false, writable: false)]
    private ?ConfiguracionEstablecimiento $configuracion = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->usuariosEstablecimiento = new ArrayCollection();
        $this->planesControl = new ArrayCollection();
        $this->puntosControl = new ArrayCollection();
        $this->tareas = new ArrayCollection();
        $this->registros = new ArrayCollection();
        $this->incidencias = new ArrayCollection();
    }

    public function getConfiguracion(): ?ConfiguracionEstablecimiento
    {
        return $this->configuracion;
    }

    public function setConfiguracion(?ConfiguracionEstablecimiento $configuracion): static
    {
        if ($this->configuracion === $configuracion) {
            return $this;
        }

        $previous = $this->configuracion;
        $this->configuracion = $configuracion;

        if ($previous !== null && $previous->getEstablecimiento() === $this) {
            $previous->setEstablecimiento(null);
        }

        if ($configuracion !== null && $configuracion->getEstablecimiento() !== $this) {
            $configuracion->setEstablecimiento($this);
        }

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntidadFiscal(): ?EntidadFiscal
    {
        return $this->entidadFiscal;
    }

    public function setEntidadFiscal(?EntidadFiscal $entidadFiscal): static
    {
        if ($this->entidadFiscal === $entidadFiscal) {
            return $this;
        }

        $previous = $this->entidadFiscal;
        $this->entidadFiscal = $entidadFiscal;
        $previous?->removeEstablecimiento($this);
        $entidadFiscal?->addEstablecimiento($this);

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

    public function getTipoActividad(): ?TipoActividad
    {
        return $this->tipoActividad;
    }

    public function setTipoActividad(?TipoActividad $tipoActividad): static
    {
        $this->tipoActividad = $tipoActividad;

        return $this;
    }

    public function getDireccion(): string
    {
        return $this->direccion;
    }

    public function setDireccion(string $direccion): static
    {
        $this->direccion = $direccion;

        return $this;
    }

    public function getCodigoPostal(): string
    {
        return $this->codigoPostal;
    }

    public function setCodigoPostal(string $codigoPostal): static
    {
        $this->codigoPostal = $codigoPostal;

        return $this;
    }

    public function getLocalidad(): string
    {
        return $this->localidad;
    }

    public function setLocalidad(string $localidad): static
    {
        $this->localidad = $localidad;

        return $this;
    }

    public function getProvincia(): string
    {
        return $this->provincia;
    }

    public function setProvincia(string $provincia): static
    {
        $this->provincia = $provincia;

        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): static
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getRegistroSanitario(): ?string
    {
        return $this->registroSanitario;
    }

    public function setRegistroSanitario(?string $registroSanitario): static
    {
        $this->registroSanitario = $registroSanitario;

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

    /** @return Collection<int, UsuarioEstablecimiento> */
    public function getUsuariosEstablecimiento(): Collection
    {
        return $this->usuariosEstablecimiento;
    }

    public function addUsuarioEstablecimiento(UsuarioEstablecimiento $item): static
    {
        if (!$this->usuariosEstablecimiento->contains($item)) {
            $this->usuariosEstablecimiento->add($item);
            $item->setEstablecimiento($this);
        }

        return $this;
    }

    public function removeUsuarioEstablecimiento(UsuarioEstablecimiento $item): static
    {
        if ($this->usuariosEstablecimiento->removeElement($item) && $item->getEstablecimiento() === $this) {
            $item->setEstablecimiento(null);
        }

        return $this;
    }

    /** @return Collection<int, PlanControl> */
    public function getPlanesControl(): Collection
    {
        return $this->planesControl;
    }

    public function addPlanControl(PlanControl $item): static
    {
        if (!$this->planesControl->contains($item)) {
            $this->planesControl->add($item);
            $item->setEstablecimiento($this);
        }

        return $this;
    }

    public function removePlanControl(PlanControl $item): static
    {
        if ($this->planesControl->removeElement($item) && $item->getEstablecimiento() === $this) {
            $item->setEstablecimiento(null);
        }

        return $this;
    }

    /** @return Collection<int, PuntoControl> */
    public function getPuntosControl(): Collection
    {
        return $this->puntosControl;
    }

    public function addPuntoControl(PuntoControl $item): static
    {
        if (!$this->puntosControl->contains($item)) {
            $this->puntosControl->add($item);
            $item->setEstablecimiento($this);
        }

        return $this;
    }

    public function removePuntoControl(PuntoControl $item): static
    {
        if ($this->puntosControl->removeElement($item) && $item->getEstablecimiento() === $this) {
            $item->setEstablecimiento(null);
        }

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
            $item->setEstablecimiento($this);
        }

        return $this;
    }

    public function removeTarea(TareaAPPCC $item): static
    {
        if ($this->tareas->removeElement($item) && $item->getEstablecimiento() === $this) {
            $item->setEstablecimiento(null);
        }

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
            $item->setEstablecimiento($this);
        }

        return $this;
    }

    public function removeRegistro(RegistroAPPCC $item): static
    {
        if ($this->registros->removeElement($item) && $item->getEstablecimiento() === $this) {
            $item->setEstablecimiento(null);
        }

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
            $item->setEstablecimiento($this);
        }

        return $this;
    }

    public function removeIncidencia(Incidencia $item): static
    {
        if ($this->incidencias->removeElement($item) && $item->getEstablecimiento() === $this) {
            $item->setEstablecimiento(null);
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
