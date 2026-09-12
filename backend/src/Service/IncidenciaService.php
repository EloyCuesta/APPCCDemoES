<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Incidencia;
use App\Entity\RegistroAPPCC;
use App\Enum\EstadoIncidencia;
use App\Enum\GravedadIncidencia;
use App\Exception\BusinessRuleException;
use App\Repository\AccionCorrectivaRepository;
use App\Repository\IncidenciaRepository;
use App\Repository\RegistroAPPCCRepository;
use App\Service\Support\CalendarioAPPCC;
use App\Service\Support\ContextoAPPCC;
use App\Service\Support\TransaccionAPPCC;
use App\Service\Support\ValidacionDominio;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class IncidenciaService
{
    public function __construct(
        private EntityManagerInterface $em,
        private IncidenciaRepository $incidencias,
        private RegistroAPPCCRepository $registros,
        private AccionCorrectivaRepository $acciones,
        private ContextoAPPCC $contexto,
        private ValidacionDominio $validacion,
        private TransaccionAPPCC $transaccion,
        private ClockInterface $clock,
        private CalendarioAPPCC $calendario,
    ) {
    }

    public function crearDesdeRegistro(RegistroAPPCC $registro, GravedadIncidencia $gravedad = GravedadIncidencia::MEDIA): Incidencia
    {
        if ($registro->getId() !== null) {
            $registro = $this->registros->find($registro->getId()) ?? throw new BusinessRuleException('El registro no existe.');
        } elseif (!$this->em->getUnitOfWork()->isScheduledForInsert($registro)) {
            throw new BusinessRuleException('El registro debe persistirse dentro del caso de uso antes de generar una incidencia.');
        }
        if ($registro->isConforme() !== false) {
            throw new BusinessRuleException('No se puede generar una incidencia de no conformidad desde un registro conforme.');
        }
        $local = $this->contexto->establecimiento($registro->getEstablecimiento());

        return $this->transaccion->ejecutar(function () use ($registro, $gravedad, $local): Incidencia {
            // Reutilizar cualquier incidencia del registro, incluso resuelta.
            $existente = $registro->getId() === null ? null : $this->incidencias->findOneBy(['registro' => $registro]);
            if ($existente !== null) {
                return $existente;
            }
            foreach ($registro->getIncidencias() as $pendiente) {
                if ($this->em->getUnitOfWork()->isScheduledForInsert($pendiente)) {
                    return $pendiente;
                }
            }
            $incidencia = (new Incidencia())->setEstablecimiento($local)->setRegistro($registro)
                ->setTitulo(mb_substr('No conformidad: '.$registro->getTarea()?->getNombre(), 0, 180))
                ->setDescripcion($registro->getObservaciones() ?: 'Control registrado como no conforme.')
                ->setGravedad($gravedad);
            $this->prepararNueva($incidencia);
            $this->em->persist($incidencia);

            return $incidencia;
        });
    }

    public function crearManual(Incidencia $incidencia): Incidencia
    {
        if ($incidencia->getId() !== null || $incidencia->getEstado() !== EstadoIncidencia::ABIERTA) {
            throw new BusinessRuleException('Una incidencia nueva debe estar abierta y no tener identificador.');
        }
        $incidencia->setEstablecimiento($this->contexto->establecimiento($incidencia->getEstablecimiento()));
        if ($incidencia->getRegistro() !== null) {
            $id = $incidencia->getRegistro()->getId();
            $registro = $id === null ? null : $this->registros->find($id);
            if ($registro === null || $registro->isConforme() !== false
                || $registro->getEstablecimiento()?->getId() !== $incidencia->getEstablecimiento()->getId()
            ) {
                throw new BusinessRuleException('El registro debe ser no conforme y pertenecer al establecimiento de la incidencia.');
            }
            $incidencia->setRegistro($registro);
        }
        $this->prepararNueva($incidencia);

        return $this->transaccion->ejecutar(function () use ($incidencia): Incidencia {
            $this->em->persist($incidencia);

            return $incidencia;
        });
    }

    public function ponerEnProceso(int $id): Incidencia
    {
        return $this->cambiarEstado($this->buscar($id), EstadoIncidencia::EN_PROCESO);
    }

    public function resolver(int $id): Incidencia
    {
        return $this->cambiarEstado($this->buscar($id), EstadoIncidencia::RESUELTA);
    }

    public function guardarCambios(Incidencia $incidencia): Incidencia
    {
        if ($incidencia->getId() === null || $this->buscar($incidencia->getId()) !== $incidencia || $incidencia->getEstado() === null) {
            throw new BusinessRuleException('La incidencia debe existir y tener un estado válido.');
        }

        return $this->cambiarEstado($incidencia, $incidencia->getEstado());
    }

    public function validarAdmiteAcciones(Incidencia $incidencia): void
    {
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($incidencia);
        if (($original['estado'] ?? $incidencia->getEstado()) === EstadoIncidencia::RESUELTA) {
            throw new BusinessRuleException('No se pueden añadir acciones a una incidencia resuelta.');
        }
    }

    private function cambiarEstado(Incidencia $incidencia, EstadoIncidencia $destino): Incidencia
    {
        $local = $this->contexto->establecimiento($incidencia->getEstablecimiento());
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($incidencia);
        $origen = $original['estado'] ?? $incidencia->getEstado();
        $incidencia->setEstado($origen)->setFechaCierre($original['fechaCierre'] ?? null);
        if (($original['establecimiento'] ?? null) !== $incidencia->getEstablecimiento()
            || ($original['registro'] ?? null) !== $incidencia->getRegistro()
        ) {
            throw new BusinessRuleException('No se puede cambiar el establecimiento ni el registro de origen de una incidencia.');
        }
        if ($origen === EstadoIncidencia::RESUELTA && $destino !== $origen) {
            throw new BusinessRuleException('Una incidencia resuelta no puede reabrirse mediante una actualización genérica.');
        }
        if ($origen === EstadoIncidencia::EN_PROCESO && $destino === EstadoIncidencia::ABIERTA) {
            throw new BusinessRuleException('Una incidencia en proceso no puede volver a abierta.');
        }
        if ($destino === EstadoIncidencia::RESUELTA && $origen !== $destino) {
            if (!$this->contexto->configuracion($local)->isPermitirCerrarIncidenciaSinAccion()
                && !$this->acciones->existsByIncidencia($incidencia)
            ) {
                throw new BusinessRuleException('La incidencia necesita al menos una acción correctiva antes de poder cerrarse.');
            }
            $incidencia->setFechaCierre($this->calendario->paraPersistir($this->clock->now()));
        }
        $incidencia->setEstado($destino);
        $this->validacion->validar($incidencia);

        return $this->transaccion->ejecutar(static fn (): Incidencia => $incidencia);
    }

    private function buscar(int $id): Incidencia
    {
        return $this->incidencias->find($id) ?? throw new BusinessRuleException('La incidencia no existe.');
    }

    private function prepararNueva(Incidencia $incidencia): void
    {
        $ahora = $this->calendario->paraPersistir($this->clock->now());
        $incidencia->setEstado(EstadoIncidencia::ABIERTA)->setFechaApertura($ahora)->setCreatedAt($ahora)->setFechaCierre(null);
        $incidencia->setGravedad($incidencia->getGravedad() ?? GravedadIncidencia::MEDIA);
        $this->validacion->validar($incidencia);
    }
}
