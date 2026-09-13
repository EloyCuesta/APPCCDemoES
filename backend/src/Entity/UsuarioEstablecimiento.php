<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\RolEstablecimiento;
use App\Repository\UsuarioEstablecimientoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Index(name: 'idx_membresia_local_activo_usuario', columns: ['establecimiento_id', 'activo', 'usuario_id'])]
#[ORM\Entity(repositoryClass: UsuarioEstablecimientoRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_usuario_establecimiento', fields: ['usuario', 'establecimiento'])]
#[UniqueEntity(fields: ['usuario', 'establecimiento'], message: 'El usuario ya pertenece a este establecimiento.')]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/usuarios-establecimientos'),
    new Get(uriTemplate: '/usuarios-establecimientos/{id}'),
    new Patch(uriTemplate: '/usuarios-establecimientos/{id}', input: \App\Dto\CambiarRolInput::class,
        read: false, securityPostDenormalize: "is_granted('ROLE_USER')", processor: \App\State\Processor\MembresiaProcessor::class),
    new Post(uriTemplate: '/usuarios-establecimientos/{id}/baja', status: 200, input: false, deserialize: false,
        read: false, securityPostDenormalize: "is_granted('ROLE_USER')", processor: \App\State\Processor\MembresiaProcessor::class, name: 'baja_membresia'),
    new Post(uriTemplate: '/usuarios-establecimientos/{id}/reactivar', status: 200, input: false, deserialize: false,
        read: false, securityPostDenormalize: "is_granted('ROLE_USER')", processor: \App\State\Processor\MembresiaProcessor::class, name: 'reactivar_membresia'),
], denormalizationContext: ['allow_extra_attributes' => false])]
class UsuarioEstablecimiento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'usuariosEstablecimiento')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo usuario es obligatorio.')]
    private ?Usuario $usuario = null;

    #[ORM\ManyToOne(inversedBy: 'usuariosEstablecimiento')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull(message: 'El campo establecimiento es obligatorio.')]
    private ?Establecimiento $establecimiento = null;

    #[ORM\Column(enumType: RolEstablecimiento::class)]
    #[Assert\NotNull(message: 'El campo rol es obligatorio.')]
    private ?RolEstablecimiento $rol = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $activo = true;

    public function getId(): ?int
    {
        return $this->id;
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
        $previous?->removeUsuarioEstablecimiento($this);
        $usuario?->addUsuarioEstablecimiento($this);

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
        $previous?->removeUsuarioEstablecimiento($this);
        $establecimiento?->addUsuarioEstablecimiento($this);

        return $this;
    }

    public function getRol(): ?RolEstablecimiento
    {
        return $this->rol;
    }

    public function setRol(?RolEstablecimiento $rol): static
    {
        $this->rol = $rol;

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
}
