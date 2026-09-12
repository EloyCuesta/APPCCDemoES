<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TareaAPPCC;
use App\Exception\BusinessRuleException;
use App\Repository\RegistroAPPCCRepository;
use App\Repository\TareaAPPCCRepository;
use App\Service\Support\ContextoAPPCC;
use App\Service\Support\TransaccionAPPCC;
use App\Service\Support\ValidacionDominio;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TareaAPPCCService
{
    public function __construct(
        private EntityManagerInterface $em,
        private \App\Security\TenantAuthorization $authorization,
        private \App\Security\CurrentEstablecimientoContext $current,
        private TareaAPPCCRepository $tareas,
        private RegistroAPPCCRepository $registros,
        private ContextoAPPCC $contexto,
        private ValidacionDominio $validacion,
        private TransaccionAPPCC $transaccion,
        private \App\Service\Support\BloqueoCalendarioAPPCC $bloqueo,
        private \App\Service\Support\CalendarioAPPCC $calendario,
        private \Psr\Clock\ClockInterface $clock,
    ) {
    }

    public function activar(int $id): TareaAPPCC
    {
        return $this->guardarCambios($this->buscar($id)->setActiva(true));
    }

    public function desactivar(int $id): TareaAPPCC
    {
        return $this->guardarCambios($this->buscar($id)->setActiva(false));
    }

    public function guardarCambios(TareaAPPCC $tarea): TareaAPPCC
    {
        $this->authorization->assertWrite($tarea);
        if ($tarea->getId() === null || $this->buscar($tarea->getId()) !== $tarea) {
            throw new BusinessRuleException('La tarea debe existir antes de cambiar su configuración.');
        }
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($tarea);
        if (($original['establecimiento'] ?? null) !== $tarea->getEstablecimiento() && $this->registros->existsForTask($tarea)) {
            throw new BusinessRuleException('No se puede trasladar una tarea con registros históricos.');
        }
        if ($tarea->isActiva()) {
            $local = $this->contexto->establecimiento($tarea->getEstablecimiento());
            $this->contexto->validarRelacionesTarea($tarea, $local);
        }
        $this->validacion->validar($tarea);
        return $this->transaccion->ejecutar(function () use ($tarea, $original): TareaAPPCC {
            $this->bloqueo->bloquear($tarea, false);
            $actual = [
                'frecuencia' => $tarea->getFrecuencia(), 'horaPrevista' => $tarea->getHoraPrevista(),
                'diaSemana' => $tarea->getDiaSemana(), 'diaMes' => $tarea->getDiaMes(), 'plazoMinutos' => $tarea->getPlazoMinutos(),
            ];
            foreach ($actual as $campo => $valor) {
                $antes = $original[$campo] ?? null;
                $antes = $antes instanceof \BackedEnum ? $antes->value : $antes;
                $valor = $valor instanceof \BackedEnum ? $valor->value : $valor;
                $cambio = $campo === 'horaPrevista' ? $antes?->format('H:i:s') !== $valor?->format('H:i:s') : $antes !== $valor;
                if ($cambio) {
                    $this->retirarFuturas($tarea);
                    break;
                }
            }
            return $tarea;
        });
    }

    private function retirarFuturas(TareaAPPCC $tarea): void
    {
        $db = $this->em->getConnection();
        $ids = $db->fetchFirstColumn("SELECT id FROM tarea_programada WHERE tarea_id = ? AND establecimiento_id = ? AND estado = 'pendiente' AND fecha_programada > ? ORDER BY id FOR UPDATE", [
            $tarea->getId(), $tarea->getEstablecimiento()->getId(), $this->calendario->paraPersistir($this->clock->now())->format('Y-m-d H:i:s.u'),
        ]);
        foreach ($ids as $id) {
            // Consulta posterior al bloqueo: observa registros que terminaron mientras esperábamos.
            if ($db->fetchOne('SELECT 1 FROM registro_appcc WHERE tarea_programada_id = ?', [$id]) !== false) { continue; }
            $programada = $this->em->find(\App\Entity\TareaProgramada::class, $id);
            $this->em->refresh($programada);
            if ($programada->getEstado() !== \App\Enum\EstadoTareaProgramada::PENDIENTE || $programada->getRegistro() !== null
                || $programada->getFechaProgramada() <= $this->clock->now()) { continue; }
            $this->em->remove($programada);
            // Sin setTarea(null): la asociación histórica nunca se reasigna.
            $tarea->getProgramaciones()->removeElement($programada);
        }
    }

    private function buscar(int $id): TareaAPPCC
    {
        $criteria = ['id' => $id];
        if ($this->current->isApiRequest()) { $criteria['establecimiento'] = $this->current->establecimiento(); }
        return $this->tareas->findOneBy($criteria) ?? throw new BusinessRuleException('La tarea no existe.');
    }
}
