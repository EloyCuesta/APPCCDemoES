<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccionCorrectiva;
use App\Exception\BusinessRuleException;
use App\Repository\IncidenciaRepository;
use App\Service\Support\CalendarioAPPCC;
use App\Service\Support\ContextoAPPCC;
use App\Service\Support\TransaccionAPPCC;
use App\Service\Support\ValidacionDominio;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class AccionCorrectivaService
{
    public function __construct(
        private EntityManagerInterface $em,
        private \App\Security\TenantAuthorization $authorization,
        private \App\Security\CurrentEstablecimientoContext $current,
        private IncidenciaRepository $incidencias,
        private IncidenciaService $incidenciaService,
        private ContextoAPPCC $contexto,
        private CalendarioAPPCC $calendario,
        private ValidacionDominio $validacion,
        private TransaccionAPPCC $transaccion,
        private ClockInterface $clock,
    ) {
    }

    public function anadir(AccionCorrectiva $accion): AccionCorrectiva
    {
        if ($accion->getId() !== null) {
            throw new BusinessRuleException('Una acción correctiva histórica no puede modificarse.');
        }
        if ($this->current->isApiRequest()) { $accion->setUsuario($this->current->usuario()); }
        $this->authorization->assertWrite($accion);
        $id = $accion->getIncidencia()?->getId();
        $criteria = ['id' => $id];
        if ($this->current->isApiRequest()) { $criteria['establecimiento'] = $this->current->establecimiento(); }
        $incidencia = $id === null ? null : $this->incidencias->findOneBy($criteria);
        if ($incidencia === null) {
            throw new BusinessRuleException('La incidencia no existe.');
        }
        $local = $this->contexto->establecimiento($incidencia->getEstablecimiento());
        $usuario = $this->contexto->usuario($accion->getUsuario(), $local);
        $this->incidenciaService->validarAdmiteAcciones($incidencia);
        $accion->setIncidencia($incidencia)->setUsuario($usuario);
        $ahora = $this->calendario->paraPersistir($this->clock->now());
        $accion->setFechaHora($ahora)->setCreatedAt($ahora);
        $this->validacion->validar($accion);

        return $this->transaccion->ejecutar(function () use ($accion): AccionCorrectiva {
            $this->em->persist($accion);

            return $accion;
        });
    }
}
