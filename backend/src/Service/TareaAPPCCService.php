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
        // No hay remove ni actualización de registros: cambiar límites afecta a ejecuciones futuras.
        return $this->transaccion->ejecutar(static fn (): TareaAPPCC => $tarea);
    }

    private function buscar(int $id): TareaAPPCC
    {
        $criteria = ['id' => $id];
        if ($this->current->isApiRequest()) { $criteria['establecimiento'] = $this->current->establecimiento(); }
        return $this->tareas->findOneBy($criteria) ?? throw new BusinessRuleException('La tarea no existe.');
    }
}
