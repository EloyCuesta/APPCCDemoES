<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Establecimiento, TareaAPPCC, TareaProgramada, Usuario};
use App\Enum\{EstadoTareaProgramada, FrecuenciaTarea};
use App\Exception\BusinessRuleException;
use App\Repository\TareaProgramadaRepository;
use App\Service\Support\{CalendarioAPPCC, ContextoAPPCC, TransaccionAPPCC, ValidacionDominio};
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Único punto de escritura de ejecuciones; la recurrencia reutiliza programarConResultado. */
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

    public function programarManual(int $tareaId, \DateTimeImmutable $fecha, ?Usuario $asignadoA = null, ?\DateTimeImmutable $limite = null): TareaProgramada
    {
        $local = $this->current->establecimiento();
        $tarea = $this->em->getRepository(TareaAPPCC::class)->findOneBy(['id' => $tareaId, 'establecimiento' => $local])
            ?? throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Tarea no encontrada.');
        $this->authorization->assertWrite($tarea);
        return $this->transaccion->ejecutar(function () use ($tarea, $local, $fecha, $asignadoA, $limite): TareaProgramada {
            $this->bloqueo->bloquear($tarea, false);
            if (!$this->bloqueo->contextoActivo($tarea) || $tarea->getFrecuencia() !== FrecuenciaTarea::BAJO_DEMANDA) {
                throw new BusinessRuleException('Solo se pueden crear ejecuciones manuales de tareas BAJO_DEMANDA con contexto activo.');
            }
            if ($limite !== null && $limite < $fecha) {
                throw new BusinessRuleException('La fecha límite debe ser igual o posterior a la fecha programada.');
            }
            $asignadoA = $asignadoA === null ? null : $this->usuarioBajoBloqueo($asignadoA, $local);
            [$programada, $creada] = $this->programarConResultado($tarea, $local, $fecha, $asignadoA, $limite);
            if (!$creada) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Ya existe una ejecución de esta tarea en esa fecha; consulta la agenda.');
            }
            return $programada;
        });
    }

    public function buscarEnEstablecimiento(int $id): TareaProgramada
    {
        return $this->programadas->findOneBy(['id' => $id, 'establecimiento' => $this->current->establecimiento()])
            ?? throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Ejecución no encontrada.');
    }

    public function asignar(TareaProgramada $programada, ?Usuario $usuario): TareaProgramada
    {
        $this->authorization->assertGestionEjecucion($programada);
        return $this->transaccion->ejecutar(function () use ($programada, $usuario): TareaProgramada {
            $this->bloquearEjecucion($programada);
            $programada->assertAbierta();
            $local = $this->contexto->establecimiento($programada->getEstablecimiento());
            $usuario = $usuario === null ? null : $this->usuarioBajoBloqueo($usuario, $local);
            $programada->setAsignadoA($usuario);
            $this->authorization->assertGestionEjecucion($programada);
            $this->validacion->validar($programada);
            return $programada;
        });
    }

    public function omitir(TareaProgramada $programada, string $motivo): TareaProgramada
    {
        $this->authorization->assertGestionEjecucion($programada);
        // Nunca se acepta el autor o la hora como parámetros de este caso de uso.
        $autor = $this->current->usuario();
        return $this->transaccion->ejecutar(function () use ($programada, $motivo, $autor): TareaProgramada {
            $this->bloquearEjecucion($programada);
            $local = $this->contexto->establecimiento($programada->getEstablecimiento());
            $autor = $this->usuarioBajoBloqueo($autor, $local);
            $programada->omitir($motivo, $autor, $this->calendario->paraPersistir($this->clock->now()));
            $this->authorization->assertGestionEjecucion($programada);
            $this->validacion->validar($programada);
            return $programada;
        });
    }

    private function usuarioBajoBloqueo(Usuario $usuario, Establecimiento $local): Usuario
    {
        $db = $this->em->getConnection();
        if ($usuario->getId() === null || $db->fetchOne('SELECT id FROM usuario WHERE id = ? FOR SHARE', [$usuario->getId()]) === false) {
            throw new BusinessRuleException('El usuario no existe o no está disponible.');
        }
        $this->em->refresh($usuario);
        $id = $db->fetchOne('SELECT id FROM usuario_establecimiento WHERE usuario_id = ? AND establecimiento_id = ? FOR SHARE', [$usuario->getId(), $local->getId()]);
        if ($id !== false) {
            $this->em->refresh($this->em->find(\App\Entity\UsuarioEstablecimiento::class, $id));
        }
        return $this->contexto->usuario($usuario, $local);
    }

    private function bloquearEjecucion(TareaProgramada $programada): void
    {
        if ($programada->getId() === null) { throw new BusinessRuleException('La ejecución debe existir.'); }
        if ($this->em->getConnection()->fetchOne('SELECT id FROM tarea_programada WHERE id = ? FOR UPDATE', [$programada->getId()]) === false) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('La ejecución cambió durante la operación. Recarga la agenda.');
        }
        $this->em->refresh($programada);
        $this->authorization->assertGestionEjecucion($programada);
    }

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
            $this->bloquearEjecucion($programada);
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
        $this->authorization->assertWrite($local);
        $ahora = $this->calendario->paraPersistir($this->clock->now());
        $db = $this->em->getConnection();
        $count = 0;
        do {
            try {
                $procesadas = $this->transaccion->ejecutar(function () use ($local, $ahora, $db): int {
                    // Mantener activo el ámbito durante el lote; orden igual al calendario.
                    foreach ([['entidad_fiscal', $local->getEntidadFiscal()], ['establecimiento', $local]] as [$tabla, $entidad]) {
                        if ($entidad === null || !$db->fetchOne('SELECT activo FROM '.$tabla.' WHERE id = ? FOR SHARE', [$entidad->getId()])) { return 0; }
                    }
                    // Actualización condicional atómica: funciona también dentro de una transacción exterior,
                    // sin repetir filas pendientes de flush ni hidratar toda la agenda.
                    $ids = $db->fetchFirstColumn("WITH candidatas AS (SELECT id FROM tarea_programada WHERE establecimiento_id = :local AND estado = 'pendiente' AND fecha_limite < :ahora ORDER BY fecha_limite, id LIMIT 100 FOR UPDATE) UPDATE tarea_programada p SET estado = 'vencida', updated_at = :ahora FROM candidatas c WHERE p.id = c.id AND p.estado = 'pendiente' AND p.fecha_limite < :ahora RETURNING p.id", ['local' => $local->getId(), 'ahora' => $ahora->format('Y-m-d H:i:s.u')]);
                    $conocidas = $this->em->getUnitOfWork()->getIdentityMap()[TareaProgramada::class] ?? [];
                    foreach ($ids as $id) {
                        if (isset($conocidas[$id])) { $this->em->refresh($conocidas[$id]); }
                    }
                    return count($ids);
                });
            } catch (\Throwable $e) {
                throw new \App\Exception\DeteccionVencidasException($db->isTransactionActive() ? 0 : $count, $e);
            }
            $count += $procesadas;
        } while ($procesadas === 100);
        return $count;
    }
}
