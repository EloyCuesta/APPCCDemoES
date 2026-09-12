<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\TipoEntidadFiscal;
use App\Repository\EntidadFiscalRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: EntidadFiscalRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/entidades-fiscales'),
    new Get(uriTemplate: '/entidades-fiscales/{id}'),
    new Patch(uriTemplate: '/entidades-fiscales/{id}'),
])]
class EntidadFiscal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: TipoEntidadFiscal::class)]
    #[Assert\NotNull(message: 'El tipo de entidad fiscal es obligatorio.')]
    private ?TipoEntidadFiscal $tipo = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150, maxMessage: 'El campo nombre comercial no puede superar {{ limit }} caracteres.')]
    private ?string $nombreComercial = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200, maxMessage: 'El campo razón social no puede superar {{ limit }} caracteres.')]
    private ?string $razonSocial = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'El campo nombre no puede superar {{ limit }} caracteres.')]
    private ?string $nombre = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150, maxMessage: 'El campo apellidos no puede superar {{ limit }} caracteres.')]
    private ?string $apellidos = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank(message: 'El campo NIF es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 20, maxMessage: 'El campo NIF no puede superar {{ limit }} caracteres.')]
    private string $nif = '';

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

    #[ORM\Column(options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Establecimiento> */
    #[ORM\OneToMany(mappedBy: 'entidadFiscal', targetEntity: Establecimiento::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $establecimientos;

    #[ORM\OneToOne(mappedBy: 'entidadFiscal', targetEntity: ConfiguracionEntidadFiscal::class)]
    #[ApiProperty(readable: false, writable: false)]
    private ?ConfiguracionEntidadFiscal $configuracion = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->establecimientos = new ArrayCollection();
    }

    public function getConfiguracion(): ?ConfiguracionEntidadFiscal
    {
        return $this->configuracion;
    }

    public function setConfiguracion(?ConfiguracionEntidadFiscal $configuracion): static
    {
        if ($this->configuracion === $configuracion) {
            return $this;
        }

        $previous = $this->configuracion;
        $this->configuracion = $configuracion;

        if ($previous !== null && $previous->getEntidadFiscal() === $this) {
            $previous->setEntidadFiscal(null);
        }

        if ($configuracion !== null && $configuracion->getEntidadFiscal() !== $this) {
            $configuracion->setEntidadFiscal($this);
        }

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTipo(): ?TipoEntidadFiscal
    {
        return $this->tipo;
    }

    public function setTipo(?TipoEntidadFiscal $tipo): static
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getNombreComercial(): ?string
    {
        return $this->nombreComercial;
    }

    public function setNombreComercial(?string $nombreComercial): static
    {
        $this->nombreComercial = $nombreComercial;

        return $this;
    }

    public function getRazonSocial(): ?string
    {
        return $this->razonSocial;
    }

    public function setRazonSocial(?string $razonSocial): static
    {
        $this->razonSocial = $razonSocial;

        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): static
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getApellidos(): ?string
    {
        return $this->apellidos;
    }

    public function setApellidos(?string $apellidos): static
    {
        $this->apellidos = $apellidos;

        return $this;
    }

    public function getNif(): string
    {
        return $this->nif;
    }

    public function setNif(string $nif): static
    {
        $this->nif = strtoupper(trim($nif));

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

    /** @return Collection<int, Establecimiento> */
    public function getEstablecimientos(): Collection
    {
        return $this->establecimientos;
    }

    public function addEstablecimiento(Establecimiento $item): static
    {
        if (!$this->establecimientos->contains($item)) {
            $this->establecimientos->add($item);
            $item->setEntidadFiscal($this);
        }

        return $this;
    }

    public function removeEstablecimiento(Establecimiento $item): static
    {
        if ($this->establecimientos->removeElement($item) && $item->getEntidadFiscal() === $this) {
            $item->setEntidadFiscal(null);
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context, mixed $payload): void
    {
        if ($this->tipo === TipoEntidadFiscal::EMPRESA && trim($this->razonSocial ?? '') === '') {
            $context->buildViolation('La razón social es obligatoria para una empresa.')
                ->atPath('razonSocial')
                ->addViolation();
        }

        if ($this->tipo === TipoEntidadFiscal::AUTONOMO) {
            if (trim($this->nombre ?? '') === '') {
                $context->buildViolation('El nombre es obligatorio para un autónomo.')
                    ->atPath('nombre')
                    ->addViolation();
            }

            if (trim($this->apellidos ?? '') === '') {
                $context->buildViolation('Los apellidos son obligatorios para un autónomo.')
                    ->atPath('apellidos')
                    ->addViolation();
            }
        }
    }
}
