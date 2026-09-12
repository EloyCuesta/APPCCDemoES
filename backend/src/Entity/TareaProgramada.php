<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\{ApiProperty, ApiResource, Get, GetCollection};
use App\Enum\EstadoTareaProgramada;
use App\Repository\TareaProgramadaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: TareaProgramadaRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(operations: [new GetCollection(uriTemplate: '/tareas-programadas'), new Get(uriTemplate: '/tareas-programadas/{id}')])]
#[ORM\UniqueConstraint(name: 'uniq_programada_tarea_fecha', columns: ['tarea_id', 'fecha_programada'])]
#[ORM\Index(name: 'idx_programada_agenda', columns: ['establecimiento_id', 'estado', 'fecha_programada'])]
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

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PrePersist]
    public function initializeTimestamp(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getRegistro(): ?RegistroAPPCC { return $this->registro; }

    public function setRegistro(?RegistroAPPCC $registro): static
    {
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
        if ($estado === $this->estado) { return; }
        if ($this->estado === EstadoTareaProgramada::COMPLETADA || $estado === EstadoTareaProgramada::COMPLETADA) {
            throw new \App\Exception\BusinessRuleException('La transición requiere registrar el control y una ejecución completada es definitiva.');
        }
        $this->estado = $estado;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->tarea !== null && $this->establecimiento !== null && $this->tarea->getEstablecimiento() !== $this->establecimiento) {
            $context->buildViolation('La definición debe pertenecer al mismo establecimiento.')->atPath('tarea')->addViolation();
        }
        if ($this->completadaAt !== null && $this->estado !== EstadoTareaProgramada::COMPLETADA) {
            $context->buildViolation('Solo una ejecución completada puede tener fecha de finalización.')->atPath('completadaAt')->addViolation();
        }
    }
}
