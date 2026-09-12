<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Establecimiento, TareaAPPCC, TareaProgramada, Usuario};
use App\Enum\EstadoTareaProgramada;
use App\Exception\BusinessRuleException;
use App\Repository\TareaProgramadaRepository;
use App\Service\Support\{CalendarioAPPCC, ContextoAPPCC, TransaccionAPPCC, ValidacionDominio};
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Programación explícita. No genera ocurrencias por frecuencia ni ejecuta trabajos. */
final readonly class TareaProgramadaService
{
    public function __construct(
        private EntityManagerInterface $em,
        private \App\Security\TenantAuthorization $authorization,
        private \App\Security\CurrentEstablecimientoContext $current,
        private TareaProgramadaRepository $programadas,
        private ContextoAPPCC $contexto,
        private CalendarioAPPCC $calendario,
        private ValidacionDominio $validacion,
        private TransaccionAPPCC $transaccion,
        private ClockInterface $clock,
        private \App\Service\Support\BloqueoCalendarioAPPCC $bloqueo,
    ) {}

    public function programar(TareaAPPCC $tarea, Establecimiento $establecimiento, \DateTimeImmutable $fecha, ?Usuario $asignadoA = null, ?\DateTimeImmutable $limite = null): TareaProgramada
    {
        return $this->programarConResultado($tarea, $establecimiento, $fecha, $asignadoA, $limite)[0];
    }

    /** @return array{TareaProgramada, bool} Ejecución y si fue creada en esta llamada. */
    public function programarConResultado(TareaAPPCC $tarea, Establecimiento $establecimiento, \DateTimeImmutable $fecha, ?Usuario $asignadoA = null, ?\DateTimeImmutable $limite = null): array
    {
        $local = $this->contexto->establecimiento($establecimiento);
        $tarea = $this->contexto->tarea($tarea, $local);
        $asignadoA = $asignadoA === null ? null : $this->contexto->usuario($asignadoA, $local);
        $fecha = $this->calendario->paraPersistir($fecha);
        $fecha = $fecha->setTime((int) $fecha->format('H'), (int) $fecha->format('i'), (int) $fecha->format('s'));
        $limite = $limite === null ? null : $this->calendario->paraPersistir($limite);
        return $this->transaccion->ejecutar(function () use ($tarea, $local, $fecha, $limite, $asignadoA): array {
            $this->bloqueo->bloquear($tarea, false);
            foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pendiente) {
                if ($pendiente instanceof TareaProgramada && $pendiente->getTarea() === $tarea && $pendiente->getFechaProgramada() == $fecha) {
                    $this->authorization->assertWrite($pendiente);
                    return [$pendiente, false];
                }
            }
            $existente = $this->programadas->findOneBy(['tarea' => $tarea, 'fechaProgramada' => $fecha]);
            if ($existente !== null) { $this->authorization->assertWrite($existente); return [$existente, false]; }
            $programada = (new TareaProgramada())->setTarea($tarea)->setEstablecimiento($local)
                ->setFechaProgramada($fecha)->setFechaLimite($limite)->setAsignadoA($asignadoA);
            $this->authorization->assertWrite($programada);
            $this->validacion->validar($programada);
            $this->em->persist($programada);
            return [$programada, true];
        });
    }

    public function cambiarEstado(TareaProgramada $programada, EstadoTareaProgramada $estado): TareaProgramada
    {
        $this->authorization->assertWrite($programada);
        $this->contexto->establecimiento($programada->getEstablecimiento());
        if ($programada->getId() === null) { throw new BusinessRuleException('La ejecución debe existir.'); }
        return $this->transaccion->ejecutar(function () use ($programada, $estado): TareaProgramada {
            $this->em->getConnection()->fetchOne('SELECT id FROM tarea_programada WHERE id = ? FOR UPDATE', [$programada->getId()]);
            $this->em->refresh($programada);
            if ($estado === EstadoTareaProgramada::VENCIDA && ($programada->getFechaLimite() === null || $programada->getFechaLimite() >= $this->clock->now())) {
                throw new BusinessRuleException('La ejecución todavía no ha vencido.');
            }
            $programada->cambiarEstado($estado);
            $this->authorization->assertWrite($programada);
            $this->validacion->validar($programada);
            return $programada;
        });
    }

    public function detectarVencidas(Establecimiento $establecimiento): int
    {
        $local = $this->contexto->establecimiento($establecimiento);
        $candidatas = $this->programadas->createQueryBuilder('p')
            ->andWhere('p.establecimiento = :local AND p.estado = :estado AND p.fechaLimite < :ahora')
            ->setParameter('local', $local)->setParameter('estado', EstadoTareaProgramada::PENDIENTE)
            ->setParameter('ahora', $this->calendario->paraPersistir($this->clock->now()), 'datetime_immutable')->getQuery()->getResult();
        $count = 0;
        foreach ($candidatas as $programada) {
            $this->authorization->assertWrite($programada);
            $this->transaccion->ejecutar(function () use ($programada, &$count): void {
                $this->em->getConnection()->fetchOne('SELECT id FROM tarea_programada WHERE id = ? FOR UPDATE', [$programada->getId()]);
                $this->em->refresh($programada);
                if ($programada->getEstado() === EstadoTareaProgramada::PENDIENTE && $programada->getFechaLimite() < $this->clock->now()) {
                    $programada->cambiarEstado(EstadoTareaProgramada::VENCIDA);
                    ++$count;
                }
            });
        }
        return $count;
    }
}
