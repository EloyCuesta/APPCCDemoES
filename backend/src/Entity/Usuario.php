<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\UsuarioRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UsuarioRepository::class)]
#[UniqueEntity(fields: ['email'], message: 'Ya existe un usuario con este correo electrónico.')]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/usuarios'),
    new Get(uriTemplate: '/usuarios/{id}'),
])]
class Usuario implements \Symfony\Component\Security\Core\User\UserInterface, \Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface
{
    #[ORM\Column(length: 255, nullable: true)]
    #[\Symfony\Component\Serializer\Attribute\Ignore]
    #[ApiProperty(readable: false, writable: false)]
    private ?string $password = null;

    /** @var list<string> Roles globales Symfony; funciones empresariales en UsuarioEstablecimiento. */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    #[ApiProperty(readable: false, writable: false)]
    private array $roles = [];

    public function getUserIdentifier(): string { return $this->email; }

    #[\Symfony\Component\Serializer\Attribute\Ignore]
    public function getPassword(): ?string { return $this->password; }

    /** Recibe exclusivamente un hash creado por UserPasswordHasherInterface. */
    public function setPassword(?string $hash): static { $this->password = $hash; return $this; }

    /** @return list<string> */
    public function getRoles(): array { return array_values(array_unique([...$this->roles, ...($this->activo ? ['ROLE_USER'] : [])])); }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        foreach ($roles as $role) {
            if (!in_array($role, ['ROLE_USER', 'ROLE_PLATFORM_ADMIN'], true)) { throw new \InvalidArgumentException('Rol global no permitido.'); }
        }
        $this->roles = array_values(array_unique($roles));
        return $this;
    }

    public function eraseCredentials(): void {}

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'El campo nombre es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 100, maxMessage: 'El campo nombre no puede superar {{ limit }} caracteres.')]
    private string $nombre = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'El campo apellidos es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 150, maxMessage: 'El campo apellidos no puede superar {{ limit }} caracteres.')]
    private string $apellidos = '';

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'El campo correo electrónico es obligatorio.', normalizer: 'trim')]
    #[Assert\Length(max: 180, maxMessage: 'El campo correo electrónico no puede superar {{ limit }} caracteres.')]
    #[Assert\Email(message: 'El correo electrónico no es válido.')]
    private string $email = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, UsuarioEstablecimiento> */
    #[ORM\OneToMany(mappedBy: 'usuario', targetEntity: UsuarioEstablecimiento::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $usuariosEstablecimiento;

    /** @var Collection<int, RegistroAPPCC> */
    #[ORM\OneToMany(mappedBy: 'usuario', targetEntity: RegistroAPPCC::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $registros;

    /** @var Collection<int, AccionCorrectiva> */
    #[ORM\OneToMany(mappedBy: 'usuario', targetEntity: AccionCorrectiva::class)]
    #[ApiProperty(readable: false, writable: false)]
    private Collection $accionesCorrectivas;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->usuariosEstablecimiento = new ArrayCollection();
        $this->registros = new ArrayCollection();
        $this->accionesCorrectivas = new ArrayCollection();
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

    public function getApellidos(): string
    {
        return $this->apellidos;
    }

    public function setApellidos(string $apellidos): static
    {
        $this->apellidos = $apellidos;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

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

    /** @return Collection<int, UsuarioEstablecimiento> */
    public function getUsuariosEstablecimiento(): Collection
    {
        return $this->usuariosEstablecimiento;
    }

    public function addUsuarioEstablecimiento(UsuarioEstablecimiento $item): static
    {
        if (!$this->usuariosEstablecimiento->contains($item)) {
            $this->usuariosEstablecimiento->add($item);
            $item->setUsuario($this);
        }

        return $this;
    }

    public function removeUsuarioEstablecimiento(UsuarioEstablecimiento $item): static
    {
        if ($this->usuariosEstablecimiento->removeElement($item) && $item->getUsuario() === $this) {
            $item->setUsuario(null);
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
            $item->setUsuario($this);
        }

        return $this;
    }

    public function removeRegistro(RegistroAPPCC $item): static
    {
        if ($this->registros->removeElement($item) && $item->getUsuario() === $this) {
            $item->setUsuario(null);
        }

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
            $item->setUsuario($this);
        }

        return $this;
    }

    public function removeAccionCorrectiva(AccionCorrectiva $item): static
    {
        if ($this->accionesCorrectivas->removeElement($item) && $item->getUsuario() === $this) {
            $item->setUsuario(null);
        }

        return $this;
    }
}
