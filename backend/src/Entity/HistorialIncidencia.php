<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\{ApiProperty, ApiResource, Get, Link};
use App\Api\{ConsultaCollection, IriConsulta, OrdenConsulta};
use App\Enum\EstadoIncidencia;
use App\Repository\HistorialIncidenciaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Append-only: se construye en el servicio y no ofrece mutadores ni escritura API. */
#[ORM\Entity(repositoryClass: HistorialIncidenciaRepository::class)]
#[ApiResource(operations: [
    new ConsultaCollection(uriTemplate: '/historiales-incidencia', parameters: [
        'incidencia' => new IriConsulta('incidencia', '/api/incidencias'),
        'order[createdAt]' => new OrdenConsulta('createdAt'),
    ], order: ['createdAt' => 'ASC', 'id' => 'ASC']),
    new ConsultaCollection(uriTemplate: '/incidencias/{id}/historial',
        uriVariables: ['id' => new Link(fromClass: Incidencia::class, toProperty: 'incidencia')], parameters: [
            'order[createdAt]' => new OrdenConsulta('createdAt'),
        ], order: ['createdAt' => 'ASC', 'id' => 'ASC']),
    new Get(uriTemplate: '/historiales-incidencia/{id}'),
])]
class HistorialIncidencia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'historial')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Incidencia $incidencia,
        #[ORM\Column(length: 255, enumType: EstadoIncidencia::class, nullable: true)]
        private ?EstadoIncidencia $estadoAnterior,
        #[ORM\Column(length: 255, enumType: EstadoIncidencia::class)]
        private EstadoIncidencia $estadoNuevo,
        // NULL se reserva para migraciones/acciones internas sin autor conocido.
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        #[ApiProperty(writable: false)]
        private ?Usuario $cambiadoPor,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $comentario = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $incidencia->addHistorial($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getIncidencia(): Incidencia { return $this->incidencia; }
    public function getEstadoAnterior(): ?EstadoIncidencia { return $this->estadoAnterior; }
    public function getEstadoNuevo(): EstadoIncidencia { return $this->estadoNuevo; }
    public function getCambiadoPor(): ?Usuario { return $this->cambiadoPor; }
    public function getComentario(): ?string { return $this->comentario; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
