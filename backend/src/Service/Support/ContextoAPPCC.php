<?php

declare(strict_types=1);

namespace App\Service\Support;

use App\Entity\ConfiguracionEstablecimiento;
use App\Entity\Establecimiento;
use App\Entity\TareaAPPCC;
use App\Entity\Usuario;
use App\Exception\BusinessRuleException;
use App\Repository\ConfiguracionEstablecimientoRepository;
use App\Repository\EstablecimientoRepository;
use App\Repository\TareaAPPCCRepository;
use App\Repository\UsuarioEstablecimientoRepository;
use App\Repository\UsuarioRepository;

final readonly class ContextoAPPCC
{
    public function __construct(
        private EstablecimientoRepository $establecimientos,
        private TareaAPPCCRepository $tareas,
        private UsuarioRepository $usuarios,
        private UsuarioEstablecimientoRepository $membresias,
        private ConfiguracionEstablecimientoRepository $configuraciones,
    ) {
    }

    public function establecimiento(?Establecimiento $input): Establecimiento
    {
        $local = $input?->getId() === null ? null : $this->establecimientos->find($input->getId());
        if ($local === null || !$local->isActivo()) {
            throw new BusinessRuleException('El establecimiento no existe o está inactivo.');
        }

        return $local;
    }

    public function usuario(?Usuario $input, Establecimiento $local): Usuario
    {
        $usuario = $input?->getId() === null ? null : $this->usuarios->find($input->getId());
        if ($usuario === null || !$usuario->isActivo()) {
            throw new BusinessRuleException('El usuario no existe o está inactivo.');
        }
        if ($this->membresias->findActiveMembership($usuario, $local) === null) {
            throw new BusinessRuleException('El usuario no pertenece al establecimiento o su pertenencia está inactiva.');
        }

        return $usuario;
    }

    public function tarea(?TareaAPPCC $input, Establecimiento $local): TareaAPPCC
    {
        $tarea = $input?->getId() === null ? null : $this->tareas->find($input->getId());
        if ($tarea === null || !$tarea->isActiva()) {
            throw new BusinessRuleException('La tarea no existe o está inactiva.');
        }
        $this->validarRelacionesTarea($tarea, $local);

        return $tarea;
    }

    public function validarRelacionesTarea(TareaAPPCC $tarea, Establecimiento $local): void
    {
        if ($tarea->getEstablecimiento()?->getId() !== $local->getId()) {
            throw new BusinessRuleException('La tarea no pertenece al establecimiento indicado.');
        }
        if ($tarea->getPlanControl() === null || $tarea->getPlanControl()->getEstablecimiento()?->getId() !== $local->getId()) {
            throw new BusinessRuleException('El plan de control no pertenece al establecimiento indicado.');
        }
        if (!$tarea->getPlanControl()->isActivo()) {
            throw new BusinessRuleException('El plan de control está inactivo.');
        }
        if ($tarea->getPuntoControl() !== null
            && ($tarea->getPuntoControl()->getEstablecimiento()?->getId() !== $local->getId() || !$tarea->getPuntoControl()->isActivo())
        ) {
            throw new BusinessRuleException('El punto de control no pertenece al establecimiento o está inactivo.');
        }
    }

    public function configuracion(Establecimiento $local): ConfiguracionEstablecimiento
    {
        // Valores por defecto sin insertar configuraciones como efecto lateral.
        return $this->configuraciones->findOneBy(['establecimiento' => $local]) ?? new ConfiguracionEstablecimiento();
    }
}
