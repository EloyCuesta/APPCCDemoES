<?php

declare(strict_types=1);

namespace App\Service\Support;

use App\Entity\TareaAPPCC;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Todas las operaciones de calendario toman primero la fila de la tarea. */
final readonly class BloqueoCalendarioAPPCC
{
    public function __construct(private EntityManagerInterface $em) {}

    public function bloquear(TareaAPPCC $tarea, bool $recargar): void
    {
        $version = $this->em->getConnection()->fetchOne('SELECT version FROM tarea_appcc WHERE id = ? FOR UPDATE', [$tarea->getId()]);
        if ($version === false || (!$recargar && (int) $version !== $tarea->getVersion())) {
            throw new ConflictHttpException('La tarea ha cambiado. Recarga su configuración antes de guardar.');
        }
        if ($recargar) {
            $original = $this->em->getUnitOfWork()->getOriginalEntityData($tarea);
            $metadata = $this->em->getClassMetadata(TareaAPPCC::class);
            foreach ($metadata->getFieldNames() as $campo) {
                if (in_array($campo, ['id', 'version'], true)) { continue; }
                $antes = $original[$campo] ?? null;
                $actual = $metadata->getFieldValue($tarea, $campo);
                $antes = $antes instanceof \BackedEnum ? $antes->value : $antes;
                $actual = $actual instanceof \BackedEnum ? $actual->value : $actual;
                if ($antes != $actual) {
                    throw new ConflictHttpException('Guarda los cambios de la tarea antes de generar sus ejecuciones.');
                }
            }
            $this->em->refresh($tarea);
        }
    }

    public function contextoActivo(TareaAPPCC $tarea): bool
    {
        $local = $tarea->getEstablecimiento();
        $plan = $tarea->getPlanControl();
        $fiscal = $local?->getEntidadFiscal();
        if (!$tarea->isActiva() || $local === null || $plan === null || $fiscal === null) {
            return false;
        }
        // Bloqueos compartidos: las desactivaciones esperan hasta terminar la generación.
        foreach ([['entidad_fiscal', $fiscal], ['establecimiento', $local], ['plan_control', $plan], ['punto_control', $tarea->getPuntoControl()]] as [$tabla, $entidad]) {
            if ($entidad === null) { continue; }
            $this->em->getConnection()->fetchOne('SELECT id FROM '.$tabla.' WHERE id = ? FOR SHARE', [$entidad->getId()]);
            $this->em->refresh($entidad);
            if (!$entidad->isActivo()) { return false; }
        }
        $config = $fiscal->getConfiguracion();
        if ($config !== null) {
            $this->em->getConnection()->fetchOne('SELECT id FROM configuracion_entidad_fiscal WHERE id = ? FOR SHARE', [$config->getId()]);
            $this->em->refresh($config);
        }

        return $plan->getEstablecimiento()?->getId() === $local->getId()
            && ($tarea->getPuntoControl() === null || $tarea->getPuntoControl()->getEstablecimiento()?->getId() === $local->getId());
    }
}
