<?php
declare(strict_types=1);
namespace App\Entity;

use ApiPlatform\Metadata\{ApiResource, Get, GetCollection, Post};
use App\Dto\CrearInvitacionInput;
use App\Dto\InvitacionCreadaOutput;
use App\Enum\{EstadoInvitacion, RolEstablecimiento};
use App\State\Processor\{CrearInvitacionProcessor, CancelarInvitacionProcessor};
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_invitacion_pendiente', columns: ['establecimiento_id', 'email'], options: ['where' => "((estado)::text = 'pendiente'::text)"])]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/invitaciones'),
    new Get(uriTemplate: '/invitaciones/{id}', requirements: ['id' => '[1-9][0-9]*']),
    new Post(uriTemplate: '/invitaciones', input: CrearInvitacionInput::class, output: InvitacionCreadaOutput::class,
        read: false, securityPostDenormalize: "is_granted('ROLE_USER')", processor: CrearInvitacionProcessor::class),
    new Post(uriTemplate: '/invitaciones/{id}/cancelar', status: 200, input: false, deserialize: false,
        read: false, securityPostDenormalize: "is_granted('ROLE_USER')", processor: CancelarInvitacionProcessor::class),
], denormalizationContext: ['allow_extra_attributes' => false], paginationItemsPerPage: 30)]
class InvitacionUsuario
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Establecimiento $establecimiento;
    #[ORM\Column(length: 180)]
    private string $email;
    #[ORM\Column(length: 20, enumType: RolEstablecimiento::class)]
    private RolEstablecimiento $rol;
    #[ORM\Column(length: 64, unique: true), Ignore]
    private string $tokenHash;
    #[ORM\Column(length: 20, enumType: EstadoInvitacion::class)]
    private EstadoInvitacion $estado = EstadoInvitacion::PENDIENTE;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Usuario $invitadaPor;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function __construct(Establecimiento $local, string $email, RolEstablecimiento $rol, string $hash, Usuario $autor, \DateTimeImmutable $ahora)
    {
        $this->establecimiento = $local;
        $this->email = \App\Service\Support\EmailUsuario::normalizar($email);
        $this->rol = $rol;
        $this->tokenHash = $hash;
        $this->invitadaPor = $autor;
        $this->createdAt = $ahora;
        $this->expiresAt = $ahora->modify('+7 days');
    }
    public function getId(): ?int { return $this->id; }
    public function getEstablecimiento(): Establecimiento { return $this->establecimiento; }
    public function getEmail(): string { return $this->email; }
    public function getRol(): RolEstablecimiento { return $this->rol; }
    public function getEstado(): EstadoInvitacion { return $this->estado; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getInvitadaPor(): Usuario { return $this->invitadaPor; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function expirar(): void { $this->estado = EstadoInvitacion::EXPIRADA; }
    public function aceptar(\DateTimeImmutable $ahora): void { $this->estado = EstadoInvitacion::ACEPTADA; $this->acceptedAt = $ahora; }
    public function cancelar(\DateTimeImmutable $ahora): void { $this->estado = EstadoInvitacion::CANCELADA; $this->cancelledAt = $ahora; }
}
