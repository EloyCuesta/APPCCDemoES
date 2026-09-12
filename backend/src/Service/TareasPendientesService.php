<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Establecimiento;
use App\Entity\TareaAPPCC;
use App\Repository\RegistroAPPCCRepository;
use App\Repository\TareaAPPCCRepository;
use App\Service\Support\CalendarioAPPCC;
use App\Service\Support\ContextoAPPCC;

final readonly class TareasPendientesService
{
    public function __construct(
        private TareaAPPCCRepository $tareas,
        private RegistroAPPCCRepository $registros,
        private CalendarioAPPCC $calendario,
        private ContextoAPPCC $contexto,
    ) {
    }

    /** @return list<TareaAPPCC> */
    public function obtenerPendientes(Establecimiento $establecimiento, ?\DateTimeImmutable $fecha = null): array
    {
        $local = $this->contexto->establecimiento($establecimiento);
        $pendientes = [];
        foreach ($this->tareas->findActiveByEstablecimiento($local) as $tarea) {
            $this->contexto->validarRelacionesTarea($tarea, $local);
            $periodo = $this->calendario->periodo($tarea, $local, $fecha);
            if ($periodo !== null && !$this->registros->existsForTaskBetween($tarea, $periodo[0], $periodo[1])) {
                $pendientes[] = $tarea;
            }
        }

        return $pendientes;
    }
}
