<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Establecimiento;
use App\Security\CurrentEstablecimientoContext;
use App\Service\Support\{CalendarioAPPCC, FechaAPPCC};
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/** Caso de uso de mantenimiento global, exclusivamente fuera de una petición API. */
final readonly class CicloOperativoTareasService
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private GeneradorTareasProgramadasService $generador,
        private TareaProgramadaService $programadas,
        private CalendarioAPPCC $calendario,
        private ClockInterface $clock,
        private CurrentEstablecimientoContext $current,
        private \App\Repository\TareaAPPCCRepository $tareas,
    ) {}

    public function procesar(int $horizonteDias = 7, ?string $desde = null, ?string $hasta = null): ResultadoCicloOperativo
    {
        if ($this->current->isApiRequest() || $this->doctrine->getConnection()->isTransactionActive()) {
            throw new \LogicException('El ciclo global requiere un proceso sin petición API ni transacción exterior.');
        }
        if ($horizonteDias < 1 || $horizonteDias > 366) { throw new \InvalidArgumentException('El horizonte debe estar entre 1 y 366 días.'); }
        $inicio = $desde === null ? null : FechaAPPCC::parsear($desde);
        $fin = $hasta === null ? null : FechaAPPCC::parsear($hasta);
        if ($inicio !== null && $fin !== null && (strlen($desde) === 10) !== (strlen($hasta) === 10)) {
            throw new \InvalidArgumentException('No mezcles días de calendario con instantes ISO.');
        }
        if ($inicio !== null && $fin !== null && $fin < $inicio) { throw new \InvalidArgumentException('--hasta debe ser igual o posterior a --desde.'); }
        $dias = ($desde !== null && strlen($desde) === 10) || ($hasta !== null && strlen($hasta) === 10);
        $ahora = $this->clock->now();
        $resultado = new ResultadoCicloOperativo();

        try {
            $ultimo = 0;
            do {
                // Paginación por clave, sin acumular entidades ni la agenda de todos los tenants.
                $filas = $this->tareas->findActiveGenerationIdsAfter($ultimo);
                foreach ($filas as $fila) {
                    $ultimo = (int) $fila['id'];
                    $localId = (int) $fila['establecimiento_id'];
                    ++$resultado->tareasAnalizadas;
                    try {
                        $local = $this->doctrine->getManager()->find(Establecimiento::class, $localId);
                        if ($inicio === null && $fin === null) {
                            $hoy = $ahora->setTimezone($this->calendario->zonaHoraria($local))->setTime(0, 0);
                            $ventanaInicio = $hoy->modify('-1 day');
                            $ventanaFin = $hoy->modify('+'.$horizonteDias.' days')->setTime(23, 59, 59);
                        } else {
                            $ventanaInicio = $inicio ?? $fin->modify('-'.$horizonteDias.' days');
                            $ventanaFin = $fin ?? $inicio->modify('+'.$horizonteDias.' days');
                        }
                        $r = $this->generador->generar($ventanaInicio, $ventanaFin, $dias, $ultimo);
                        // Contabilizar únicamente después del commit de la tarea.
                        $resultado->creadas += $r->creadas;
                        $resultado->existentes += $r->existentes;
                        $resultado->ignoradas += $r->ignoradas;
                        array_push($resultado->ignoradasDetalle, ...$r->ignoradasDetalle);
                    } catch (\Throwable $e) {
                        $resultado->error('generacion', $ultimo, $localId, $e);
                    } finally { $this->limpiarManager(); }
                }
            } while (count($filas) === 100);
        } catch (\Throwable $e) { $resultado->error('consulta_tareas', null, null, $e); $this->limpiarManager(); }

        try {
            $ultimo = 0;
            do {
                $ids = $this->doctrine->getConnection()->fetchFirstColumn('SELECT e.id FROM establecimiento e JOIN entidad_fiscal f ON f.id = e.entidad_fiscal_id WHERE e.id > ? AND e.activo AND f.activo ORDER BY e.id LIMIT 100', [$ultimo]);
                foreach ($ids as $id) {
                    $ultimo = (int) $id;
                    try {
                        $local = $this->doctrine->getManager()->find(Establecimiento::class, $ultimo);
                        $resultado->vencidas += $this->programadas->detectarVencidas($local);
                    } catch (\Throwable $e) {
                        if ($e instanceof \App\Exception\DeteccionVencidasException) { $resultado->vencidas += $e->confirmadas; }
                        $resultado->error('vencimiento', null, $ultimo, $e);
                    } finally { $this->limpiarManager(); }
                }
            } while (count($ids) === 100);
        } catch (\Throwable $e) { $resultado->error('consulta_establecimientos', null, null, $e); $this->limpiarManager(); }

        return $resultado;
    }

    private function limpiarManager(): void
    {
        $em = $this->doctrine->getManager();
        if ($em->isOpen()) { $em->clear(); }
        else { $this->doctrine->resetManager(); }
    }
}
