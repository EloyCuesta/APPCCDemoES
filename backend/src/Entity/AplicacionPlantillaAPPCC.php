<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Recibo interno e inmutable de la aplicación; no es un recurso editable por API. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_plantilla_local', columns: ['plantilla_id', 'establecimiento_id'])]
#[ORM\Index(name: 'idx_aplicacion_plantilla', columns: ['plantilla_id'])]
#[ORM\Index(name: 'idx_aplicacion_local', columns: ['establecimiento_id'])]
class AplicacionPlantillaAPPCC
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private PlantillaAPPCC $plantilla,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Establecimiento $establecimiento,
        #[ORM\Column(type: Types::JSON)]
        private array $resultado,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {}

    public function getId(): ?int { return $this->id; }
    public function getResultado(): array { return $this->resultado; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
