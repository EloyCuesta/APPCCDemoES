<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Establecimiento, TareaProgramada};
use App\Enum\EstadoTareaProgramada;
use App\Repository\TareaProgramadaRepository;
use App\Service\Support\{CalendarioAPPCC, ContextoAPPCC};
use Psr\Clock\ClockInterface;

final readonly class TareasPendientesService
{
    public function __construct(private TareaProgramadaRepository $tareas, private ContextoAPPCC $contexto, private CalendarioAPPCC $calendario, private ClockInterface $clock) {}

    /** @return list<TareaProgramada> Ejecuciones pendientes o vencidas hasta la fecha indicada. */
    public function obtenerPendientes(Establecimiento $establecimiento, ?\DateTimeImmutable $fecha = null): array
    {
        return $this->tareas->createQueryBuilder('p')
            ->andWhere('p.establecimiento = :local AND p.estado IN (:estados) AND p.fechaProgramada <= :fecha')
            ->setParameter('local', $this->contexto->establecimiento($establecimiento))
            ->setParameter('estados', [EstadoTareaProgramada::PENDIENTE->value, EstadoTareaProgramada::VENCIDA->value])
            ->setParameter('fecha', $this->calendario->paraPersistir($fecha ?? $this->clock->now()), 'datetime_immutable')
            ->orderBy('p.fechaProgramada', 'ASC')->addOrderBy('p.id', 'ASC')->getQuery()->getResult();
    }
}
