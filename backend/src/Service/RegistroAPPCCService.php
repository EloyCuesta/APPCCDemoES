<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RegistroAPPCC;
use App\Exception\BusinessRuleException;
use App\Repository\RegistroAPPCCRepository;
use App\Service\Support\CalendarioAPPCC;
use App\Service\Support\ContextoAPPCC;
use App\Service\Support\DecimalAPPCC;
use App\Service\Support\TransaccionAPPCC;
use App\Service\Support\ValidacionDominio;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class RegistroAPPCCService
{
    public function __construct(
        private EntityManagerInterface $em,
        private \App\Security\TenantAuthorization $authorization,
        private \App\Security\CurrentEstablecimientoContext $current,
        private RegistroAPPCCRepository $registros,
        private ContextoAPPCC $contexto,
        private CalendarioAPPCC $calendario,
        private ValidacionDominio $validacion,
        private IncidenciaService $incidencias,
        private TransaccionAPPCC $transaccion,
        private ClockInterface $clock,
    ) {
    }

    public function registrar(RegistroAPPCC $registro): RegistroAPPCC
    {
        if ($registro->getId() !== null) {
            $existing = $this->registros->find($registro->getId());
            throw new BusinessRuleException($existing !== null ? 'Un registro histórico no puede modificarse.' : 'Un registro nuevo no debe tener identificador.');
        }
        if ($this->current->isApiRequest()) { $registro->setUsuario($this->current->usuario()); }
        $this->authorization->assertWrite($registro);
        $local = $this->contexto->establecimiento($registro->getEstablecimiento());
        $usuario = $this->contexto->usuario($registro->getUsuario(), $local);
        $programada = $registro->getTareaProgramada();
        if ($programada?->getId() === null || $programada->getEstablecimiento()?->getId() !== $local->getId()) {
            throw new BusinessRuleException('La ejecución debe existir y pertenecer al establecimiento.');
        }
        $tarea = $this->contexto->tarea($programada->getTarea(), $local);
        $registro->setEstablecimiento($local)->setUsuario($usuario);
        $config = $this->contexto->configuracion($local);
        $this->calendario->validarFecha($tarea, $local, $config, $registro->getFechaHora());
        $registro->setConforme($this->determinarConformidad($registro));
        if (!$registro->isConforme() && $config->isRequiereObservacionNoConforme() && trim($registro->getObservaciones() ?? '') === '') {
            throw new BusinessRuleException('Debe indicar una observación para un registro no conforme.');
        }
        // requiereFotoNoConforme queda pendiente de un flujo de subida atómica.
        $registro->setFechaHora($this->calendario->paraPersistir($registro->getFechaHora()));
        $registro->setCreatedAt($this->calendario->paraPersistir($this->clock->now()));
        $this->validacion->validar($registro);

        return $this->transaccion->ejecutar(function () use ($registro, $config, $programada): RegistroAPPCC {
            $this->em->getConnection()->fetchOne('SELECT id FROM tarea_programada WHERE id = ? FOR UPDATE', [$programada->getId()]);
            $this->em->refresh($programada);
            if ($programada->getRegistro() !== null) {
                throw new BusinessRuleException('La ejecución ya tiene un registro.');
            }
            if (!in_array($programada->getEstado(), [\App\Enum\EstadoTareaProgramada::PENDIENTE, \App\Enum\EstadoTareaProgramada::VENCIDA], true)) {
                throw new BusinessRuleException('La ejecución no está pendiente o vencida.');
            }
            if ($programada->getFechaProgramada() > $this->clock->now() || $registro->getFechaHora() < $programada->getFechaProgramada()) {
                throw new BusinessRuleException('El registro no puede preceder a su ejecución programada.');
            }
            if (($programada->getEstado() === \App\Enum\EstadoTareaProgramada::VENCIDA
                || ($programada->getFechaLimite() !== null && $programada->getFechaLimite() < $this->clock->now()))
                && (!$config->isPermiteRegistrosAtrasados()
                    || ($config->getMaximoMinutosRegistroAtrasado() !== null && $programada->getFechaLimite() !== null
                        && $this->clock->now()->getTimestamp() - $programada->getFechaLimite()->getTimestamp() > $config->getMaximoMinutosRegistroAtrasado() * 60))) {
                throw new BusinessRuleException('El establecimiento no permite registrar esta ejecución fuera de plazo.');
            }
            $programada->completar($this->calendario->paraPersistir($this->clock->now()));
            $programada->setRegistro($registro);
            $this->em->persist($registro);
            if (!$registro->isConforme() && $config->isGeneraIncidenciaAutomatica()) {
                $this->incidencias->crearDesdeRegistro($registro);
            }

            return $registro;
        });
    }

    private function determinarConformidad(RegistroAPPCC $registro): bool
    {
        $tarea = $registro->getTarea();
        $config = $tarea->getConfiguracion() ?? [];
        $tipo = $config['tipoRespuesta'] ?? null;
        if ($tipo !== null && !in_array($tipo, ['numero', 'boolean'], true)) {
            throw new BusinessRuleException('El tipo de respuesta de la tarea no está soportado.');
        }
        $numerico = $tipo === 'numero' || $tarea->getLimiteMinimo() !== null || $tarea->getLimiteMaximo() !== null
            || ($tipo === null && !isset($config['campos']) && $registro->getValorNumerico() !== null);
        if ($numerico) {
            if ($tipo === 'boolean') {
                throw new BusinessRuleException('La tarea mezcla una respuesta booleana con límites numéricos.');
            }
            if ($registro->getValorNumerico() === null) {
                throw new BusinessRuleException('Debe indicar el valor numérico del control.');
            }
            $valor = DecimalAPPCC::unidades($registro->getValorNumerico());
            $minimo = $tarea->getLimiteMinimo();
            $maximo = $tarea->getLimiteMaximo();

            return ($minimo === null || $valor >= DecimalAPPCC::unidades($minimo))
                && ($maximo === null || $valor <= DecimalAPPCC::unidades($maximo));
        }
        $datos = $registro->getDatos() ?? [];
        if ($tipo === 'boolean' || ($tipo === null && !isset($config['campos']) && array_key_exists('resultado', $datos))) {
            if (!is_bool($datos['resultado'] ?? null)) {
                throw new BusinessRuleException('El resultado del control debe ser un booleano.');
            }

            return $datos['resultado'];
        }
        if (isset($config['campos']) && is_array($config['campos']) && $config['campos'] !== []) {
            foreach ($config['campos'] as $campo) {
                if (!is_string($campo) || !array_key_exists($campo, $datos) || $datos[$campo] === null
                    || (is_string($datos[$campo]) && trim($datos[$campo]) === '')
                ) {
                    throw new BusinessRuleException('Faltan datos requeridos por la configuración del control.');
                }
            }
            if ($registro->isConforme() === null) {
                throw new BusinessRuleException('Debe indicar la conformidad del control estructurado.');
            }

            return $registro->isConforme(); // Evaluación manual explícita; sin un motor universal de reglas JSON.
        }

        throw new BusinessRuleException('La tarea debe definir una respuesta numérica, booleana o campos de un control estructurado.');
    }
}
