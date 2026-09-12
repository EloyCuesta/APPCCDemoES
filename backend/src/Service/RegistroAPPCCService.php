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
        $local = $this->contexto->establecimiento($registro->getEstablecimiento());
        $usuario = $this->contexto->usuario($registro->getUsuario(), $local);
        $tarea = $this->contexto->tarea($registro->getTarea(), $local);
        $registro->setEstablecimiento($local)->setUsuario($usuario)->setTarea($tarea);
        $config = $this->contexto->configuracion($local);
        $this->calendario->validarFecha($tarea, $local, $config, $registro->getFechaHora());
        $registro->setConforme($this->determinarConformidad($registro));
        if (!$registro->isConforme() && $config->isRequiereObservacionNoConforme() && trim($registro->getObservaciones() ?? '') === '') {
            throw new BusinessRuleException('Debe indicar una observación para un registro no conforme.');
        }
        // TODO de dominio: requiereFotoNoConforme necesita un modelo real de adjuntos.
        $registro->setFechaHora($this->calendario->paraPersistir($registro->getFechaHora()));
        $registro->setCreatedAt($this->calendario->paraPersistir($this->clock->now()));
        $this->validacion->validar($registro);

        return $this->transaccion->ejecutar(function () use ($registro, $config): RegistroAPPCC {
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
