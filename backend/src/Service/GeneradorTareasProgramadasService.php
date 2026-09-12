<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TareaAPPCC;
use App\Enum\FrecuenciaTarea;
use App\Repository\TareaAPPCCRepository;
use App\Service\Support\CalendarioAPPCC;

final readonly class GeneradorTareasProgramadasService
{
    public function __construct(
        private TareaAPPCCRepository $tareas,
        private TareaProgramadaService $programadas,
        private CalendarioAPPCC $calendario,
        private \App\Service\Support\TransaccionAPPCC $transaccion,
        private \App\Service\Support\BloqueoCalendarioAPPCC $bloqueo,
    ) {
    }

    public function generar(\DateTimeImmutable $desde, \DateTimeImmutable $hasta, bool $fechasCalendario = false): \App\Service\ResultadoGeneracion
    {
        if ($hasta < $desde) {
            throw new \InvalidArgumentException('El intervalo de generación no es válido.');
        }

        $analizadas = 0;
        $calculadas = 0;
        $creadas = 0;
        $existentes = 0;
        $ignoradas = 0;
        $detalle = [];

        foreach ($this->tareas->findActiveForGeneration() as $tarea) {
            ++$analizadas;
            $this->transaccion->ejecutar(function () use ($tarea, $desde, $hasta, $fechasCalendario, &$calculadas, &$creadas, &$existentes, &$ignoradas, &$detalle): void {
                $this->bloqueo->bloquear($tarea, true);
                if (!$this->bloqueo->contextoActivo($tarea) || !in_array($tarea->getFrecuencia(), [FrecuenciaTarea::DIARIA, FrecuenciaTarea::SEMANAL, FrecuenciaTarea::MENSUAL], true)) {
                    ++$ignoradas;
                    $detalle[] = sprintf('Tarea %d: el contexto o la frecuencia ya no permite generación automática.', $tarea->getId());
                    return;
                }
                $frecuencia = $tarea->getFrecuencia();
                $local = $tarea->getEstablecimiento();
                if ($local === null || $frecuencia === null) {
                    ++$ignoradas;
                    $detalle[] = sprintf('Tarea %s: falta el establecimiento o la frecuencia.', $tarea->getId() ?? 'nueva');
                    return;
                }
                $motivo = $this->motivoNoProgramable($tarea, $frecuencia);
                if ($motivo !== null) {
                    ++$ignoradas;
                    $detalle[] = sprintf('Tarea %d (%s): %s', $tarea->getId(), $tarea->getNombre(), $motivo);
                    return;
                }

                $ventanaDesde = $desde;
                $ventanaHasta = $hasta;
                if ($fechasCalendario) {
                    $zona = $this->calendario->zonaHoraria($local);
                    $ventanaDesde = new \DateTimeImmutable($desde->format('Y-m-d').' 00:00:00', $zona);
                    $ventanaHasta = new \DateTimeImmutable($hasta->format('Y-m-d').' 23:59:59', $zona);
                }
                foreach ($this->calendario->ocurrencias($tarea, $local, $ventanaDesde, $ventanaHasta) as $ocurrencia) {
                    ++$calculadas;
                    $limite = $tarea->getPlazoMinutos() === null
                        ? null
                        : $ocurrencia->setTimezone(new \DateTimeZone('UTC'))->add(new \DateInterval(sprintf('PT%dM', $tarea->getPlazoMinutos())));
                    [, $creada] = $this->programadas->programarConResultado($tarea, $local, $ocurrencia, null, $limite);
                    if ($creada) {
                        ++$creadas;
                    } else {
                        ++$existentes;
                    }
                }
            });
        }

        return new ResultadoGeneracion($analizadas, $calculadas, $creadas, $existentes, $ignoradas, $detalle);
    }

    private function motivoNoProgramable(TareaAPPCC $tarea, FrecuenciaTarea $frecuencia): ?string
    {
        if ($tarea->getHoraPrevista() === null) {
            return 'no tiene hora prevista configurada';
        }
        if ($frecuencia === FrecuenciaTarea::SEMANAL && $tarea->getDiaSemana() === null) {
            return 'no tiene día de semana configurado';
        }
        if ($frecuencia === FrecuenciaTarea::SEMANAL && ($tarea->getDiaSemana() < 1 || $tarea->getDiaSemana() > 7)) {
            return 'tiene un día de semana inválido';
        }
        if ($frecuencia === FrecuenciaTarea::MENSUAL && $tarea->getDiaMes() === null) {
            return 'no tiene día de mes configurado';
        }
        if ($frecuencia === FrecuenciaTarea::MENSUAL && ($tarea->getDiaMes() < 1 || $tarea->getDiaMes() > 31)) {
            return 'tiene un día de mes inválido';
        }
        if ($tarea->getPlazoMinutos() !== null && $tarea->getPlazoMinutos() < 1) {
            return 'tiene un plazo en minutos inválido';
        }

        return null;
    }
}
