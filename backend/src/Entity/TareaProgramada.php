<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\{ApiProperty, ApiResource, Get, GetCollection, Post, Link, QueryParameter};
use ApiPlatform\Doctrine\Orm\Filter\{ExactFilter, IriFilter};
use App\Dto\{CrearProgramacionInput, AsignarProgramacionInput, OmitirProgramacionInput};
use App\State\Processor\{CrearProgramacionProcessor, AsignarProgramacionProcessor, OmitirProgramacionProcessor};
use App\Enum\EstadoTareaProgramada;
use App\Repository\TareaProgramadaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: TareaProgramadaRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [
    new GetCollection(uriTemplate: '/tareas-programadas'),
    new GetCollection(uriTemplate: '/tareas-programadas/agenda', name: 'agenda_programaciones'),
    new Get(uriTemplate: '/tareas-programadas/{id}', requirements: ['id' => '\\d+']),
    new Post(uriTemplate: '/tareas/{id}/programaciones', uriVariables: ['id' => new Link(fromClass: TareaAPPCC::class, identifiers: ['id'])],
        requirements: ['id' => '[1-9][0-9]*'], status: 201, input: CrearProgramacionInput::class, read: false, securityPostDenormalize: "is_granted('ROLE_USER')", processor: CrearProgramacionProcessor::class),
    new Post(uriTemplate: '/tareas-programadas/{id}/asignar', status: 200, input: AsignarProgramacionInput::class,
        requirements: ['id' => '[1-9][0-9]*'], read: false, securityPostDenormalize: "is_granted('ROLE_USER')", processor: AsignarProgramacionProcessor::class),
    new Post(uriTemplate: '/tareas-programadas/{id}/omitir', status: 200, input: OmitirProgramacionInput::class,
        requirements: ['id' => '[1-9][0-9]*'], read: false, securityPostDenormalize: "is_granted('ROLE_USER')", processor: OmitirProgramacionProcessor::class),
], paginationEnabled: true, paginationItemsPerPage: 30, paginationClientItemsPerPage: true, paginationMaximumItemsPerPage: 100,
    parameters: [
        'estado' => new QueryParameter(property: 'estado', filter: new ExactFilter()),
        'tarea' => new QueryParameter(property: 'tarea', filter: new IriFilter()),
        'asignadoA' => new QueryParameter(property: 'asignadoA', filter: new IriFilter()),
        'fechaProgramada[after]' => new QueryParameter(property: 'fechaProgramada', filter: \App\Doctrine\Filter\FechaAgendaFilter::class, castToArray: false),
        'fechaProgramada[before]' => new QueryParameter(property: 'fechaProgramada', filter: \App\Doctrine\Filter\FechaAgendaFilter::class, castToArray: false),
        'fechaLimite[before]' => new QueryParameter(property: 'fechaLimite', filter: \App\Doctrine\Filter\FechaAgendaFilter::class, castToArray: false),
        'order[fechaProgramada]' => new QueryParameter(property: 'fechaProgramada', filter: \App\Doctrine\Filter\OrdenAgendaFilter::class, castToArray: false,
            schema: ['type' => 'string', 'enum' => ['asc', 'desc', 'ASC', 'DESC']]),
    ], order: ['fechaProgramada' => 'ASC', 'id' => 'ASC'])]
#[ORM\UniqueConstraint(name: 'uniq_programada_tarea_fecha', columns: ['tarea_id', 'fecha_programada'])]
#[ORM\Index(name: 'idx_programada_agenda', columns: ['establecimiento_id', 'estado', 'fecha_programada'])]
#[ORM\Index(name: 'idx_programada_vencimiento', columns: ['establecimiento_id', 'estado', 'fecha_limite', 'id'])]
#[ORM\Index(name: 'idx_programada_omitida_por', columns: ['omitida_por_id'])]
class TareaProgramada
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'programaciones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?TareaAPPCC $tarea = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Establecimiento $establecimiento = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Usuario $asignadoA = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $fechaProgramada = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'fechaProgramada')]
    private ?\DateTimeImmutable $fechaLimite = null;

    #[ORM\Column(length: 20, enumType: EstadoTareaProgramada::class, options: ['default' => 'pendiente'])]
    #[ApiProperty(writable: false)]
    private EstadoTareaProgramada $estado = EstadoTareaProgramada::PENDIENTE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $completadaAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?string $motivoOmision = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $omitidaAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    #[ApiProperty(writable: false, readableLink: false)]
    private ?Usuario $omitidaPor = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToOne(mappedBy: 'tareaProgramada', targetEntity: RegistroAPPCC::class)]
    #[ApiProperty(readable: false, writable: false)]
    private ?RegistroAPPCC $registro = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTarea(): ?TareaAPPCC { return $this->tarea; }

    public function setTarea(?TareaAPPCC $tarea): static
    {
        if ($this->registro !== null && $this->tarea !== $tarea) {
            throw new \App\Exception\BusinessRuleException('No se puede cambiar el origen de una ejecución registrada.');
        }
        if ($this->tarea === $tarea) { return $this; }
        $previous = $this->tarea;
        $this->tarea = $tarea;
        $previous?->removeProgramacion($this);
        $tarea?->addProgramacion($this);

        return $this;
    }

    public function getEstablecimiento(): ?Establecimiento { return $this->establecimiento; }

    public function setEstablecimiento(?Establecimiento $establecimiento): static
    {
        if ($this->registro !== null && $this->establecimiento !== $establecimiento) {
            throw new \App\Exception\BusinessRuleException('No se puede cambiar el origen de una ejecución registrada.');
        }
        $this->establecimiento = $establecimiento;

        return $this;
    }

    public function getAsignadoA(): ?Usuario { return $this->asignadoA; }

    public function setAsignadoA(?Usuario $asignadoA): static
    {
        $this->assertAbierta();
        $this->asignadoA = $asignadoA;

        return $this;
    }

    public function getFechaProgramada(): ?\DateTimeImmutable { return $this->fechaProgramada; }

    public function setFechaProgramada(?\DateTimeImmutable $fechaProgramada): static
    {
        $this->fechaProgramada = $fechaProgramada;

        return $this;
    }

    public function getFechaLimite(): ?\DateTimeImmutable { return $this->fechaLimite; }

    public function setFechaLimite(?\DateTimeImmutable $fechaLimite): static
    {
        $this->fechaLimite = $fechaLimite;

        return $this;
    }

    public function getEstado(): EstadoTareaProgramada { return $this->estado; }

    public function getCompletadaAt(): ?\DateTimeImmutable { return $this->completadaAt; }

    public function getMotivoOmision(): ?string { return $this->motivoOmision; }
    public function getOmitidaAt(): ?\DateTimeImmutable { return $this->omitidaAt; }
    public function getOmitidaPor(): ?Usuario { return $this->omitidaPor; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PrePersist]
    public function initializeTimestamp(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getRegistro(): ?RegistroAPPCC { return $this->registro; }

    public function setRegistro(?RegistroAPPCC $registro): static
    {
        if ($registro !== null && $this->estado === EstadoTareaProgramada::OMITIDA) {
            throw new \App\Exception\BusinessRuleException('Una ejecución omitida no puede registrar un control.');
        }
        if ($this->registro === $registro) { return $this; }
        if ($this->registro !== null) {
            throw new \App\Exception\BusinessRuleException('Una ejecución registrada no puede perder o reemplazar su registro.');
        }
        $this->registro = $registro;
        if ($registro?->getTareaProgramada() !== $this) { $registro?->setTareaProgramada($this); }
        return $this;
    }

    /** Solo el servicio puede completar una ejecución al persistir su registro. */
    public function completar(\DateTimeImmutable $fecha): void
    {
        if (!in_array($this->estado, [EstadoTareaProgramada::PENDIENTE, EstadoTareaProgramada::VENCIDA], true)) {
            throw new \App\Exception\BusinessRuleException('La ejecución no está pendiente o vencida.');
        }
        $this->estado = EstadoTareaProgramada::COMPLETADA;
        $this->completadaAt = $fecha;
    }

    public function cambiarEstado(EstadoTareaProgramada $estado): void
    {
        if ($this->estado !== EstadoTareaProgramada::PENDIENTE || $estado !== EstadoTareaProgramada::VENCIDA) {
            throw new \App\Exception\BusinessRuleException('Transición inválida: utiliza registrar u omitir para cerrar la ejecución.');
        }
        $this->estado = $estado;
    }

    public function omitir(string $motivo, Usuario $autor, \DateTimeImmutable $fecha): void
    {
        $this->assertAbierta();
        if ($this->registro !== null) { throw new \App\Exception\BusinessRuleException('Una ejecución con registro no puede omitirse.'); }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 2000) {
            throw new \App\Exception\BusinessRuleException('El motivo de omisión debe tener entre 3 y 2000 caracteres.');
        }
        if ($autor->getId() === null || !$autor->isActivo()) {
            throw new \App\Exception\BusinessRuleException('La omisión requiere un autor activo existente.');
        }
        $this->estado = EstadoTareaProgramada::OMITIDA;
        $this->motivoOmision = $motivo;
        $this->omitidaPor = $autor;
        $this->omitidaAt = $fecha;
    }

    public function assertAbierta(): void
    {
        if (!in_array($this->estado, [EstadoTareaProgramada::PENDIENTE, EstadoTareaProgramada::VENCIDA], true)) {
            throw new \App\Exception\BusinessRuleException('La ejecución no está pendiente o vencida.');
        }
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $auditada = $this->motivoOmision !== null && $this->omitidaAt !== null && $this->omitidaPor !== null
            && trim($this->motivoOmision) === $this->motivoOmision && mb_strlen($this->motivoOmision) >= 3 && mb_strlen($this->motivoOmision) <= 2000;
        if (($this->estado === EstadoTareaProgramada::OMITIDA && !$auditada)
            || ($this->estado !== EstadoTareaProgramada::OMITIDA && ($this->motivoOmision !== null || $this->omitidaAt !== null || $this->omitidaPor !== null))) {
            $context->buildViolation('El estado y la auditoría de omisión deben ser coherentes.')->atPath('motivoOmision')->addViolation();
        }
        if ($this->tarea !== null && $this->establecimiento !== null && $this->tarea->getEstablecimiento() !== $this->establecimiento) {
            $context->buildViolation('La definición debe pertenecer al mismo establecimiento.')->atPath('tarea')->addViolation();
        }
        if ($this->completadaAt !== null && $this->estado !== EstadoTareaProgramada::COMPLETADA) {
            $context->buildViolation('Solo una ejecución completada puede tener fecha de finalización.')->atPath('completadaAt')->addViolation();
        }
    }
}
