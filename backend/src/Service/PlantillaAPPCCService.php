<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Establecimiento;
use App\Entity\AplicacionPlantillaAPPCC;
use App\Entity\PlanControl;
use App\Entity\PlantillaAPPCC;
use App\Entity\PuntoControl;
use App\Entity\TareaAPPCC;
use App\Enum\FrecuenciaTarea;
use App\Enum\TipoActividad;
use App\Enum\TipoPlanControl;
use App\Enum\TipoPuntoControl;
use App\Exception\BusinessRuleException;
use App\Repository\EstablecimientoRepository;
use App\Repository\PlanControlRepository;
use App\Repository\PlantillaAPPCCRepository;
use App\Repository\PuntoControlRepository;
use App\Service\Support\TransaccionAPPCC;
use App\Service\Support\ValidacionDominio;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class PlantillaAPPCCService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlantillaAPPCCRepository $plantillas,
        private EstablecimientoRepository $establecimientos,
        private PlanControlRepository $planes,
        private PuntoControlRepository $puntos,
        private ValidacionDominio $validacion,
        private TransaccionAPPCC $transaccion,
        private ClockInterface $clock,
        private \App\Security\TenantAuthorization $authorization,
    ) {
    }

    /** Catálogo versionado: repetir la carga conserva IDs, configuración y activación existentes.
     * @return list<PlantillaAPPCC>
     */
    public function cargarIniciales(): array
    {
        return $this->transaccion->ejecutar(function (): array {
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(20260922, 1)');
            $resultado = [];
            foreach (['restaurante', 'obrador', 'catering'] as $actividad) {
                $input = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/plantillas/'.$actividad.'-v1.json'), true, flags: JSON_THROW_ON_ERROR);
                $plantilla = $this->plantillas->findOneBy(['codigo' => $input['codigo']]);
                if ($plantilla === null) {
                    $plantilla = (new PlantillaAPPCC())->setCodigo($input['codigo'])->setNombre($input['nombre'])
                        ->setTipoActividad(TipoActividad::from($actividad))->setDescripcion($input['descripcion'])
                        ->setConfiguracion($input['configuracion'])->setCreatedAt($this->clock->now());
                    $this->validacion->validar($plantilla);
                    $this->em->persist($plantilla);
                }
                $resultado[] = $plantilla;
            }
            return $resultado;
        });
    }

    /** @return array{planes: list<PlanControl>, puntos: list<PuntoControl>, tareas: list<TareaAPPCC>, aplicacion: AplicacionPlantillaAPPCC, yaAplicada: bool} */
    public function aplicar(PlantillaAPPCC $plantilla, Establecimiento $establecimiento): array
    {
        $plantilla = $plantilla->getId() === null ? null : $this->plantillas->find($plantilla->getId());
        if ($plantilla === null || !$plantilla->isActiva()) {
            throw new BusinessRuleException('La plantilla no existe o está inactiva.');
        }
        if ($establecimiento->getId() !== null) {
            $establecimiento = $this->establecimientos->find($establecimiento->getId()) ?? throw new BusinessRuleException('El establecimiento no existe.');
        } elseif (!$this->em->getUnitOfWork()->isScheduledForInsert($establecimiento)) {
            throw new BusinessRuleException('El establecimiento debe pertenecer al onboarding o estar persistido.');
        }
        return $this->transaccion->ejecutar(function () use ($plantilla, $establecimiento): array {
            if ($establecimiento->getId() !== null) {
                // Serializar aplicaciones concurrentes sobre el mismo local en PostgreSQL.
                $this->em->getConnection()->fetchOne('SELECT id FROM establecimiento WHERE id = ? FOR UPDATE', [$establecimiento->getId()]);
                $this->em->refresh($establecimiento);
                $this->authorization->assertAplicarPlantilla($establecimiento);
            }
            $this->em->refresh($plantilla, LockMode::PESSIMISTIC_READ);
            if (!$plantilla->isActiva() || !$establecimiento->isActivo()) {
                throw new BusinessRuleException('La plantilla o el establecimiento están inactivos.');
            }
            if ($plantilla->getTipoActividad() !== TipoActividad::OTRO && $plantilla->getTipoActividad() !== $establecimiento->getTipoActividad()) {
                throw new BusinessRuleException('La plantilla no es compatible con el tipo de actividad del establecimiento.');
            }
            if ($establecimiento->getId() !== null) {
                $previa = $this->em->getRepository(AplicacionPlantillaAPPCC::class)->findOneBy(['plantilla' => $plantilla, 'establecimiento' => $establecimiento]);
                if ($previa !== null) {
                    return ['planes' => [], 'puntos' => [], 'tareas' => [], 'aplicacion' => $previa, 'yaAplicada' => true];
                }
            }
            $config = $plantilla->getConfiguracion();
            $this->claves($config, ['planes', 'puntosControl']);
            $planesInput = $this->lista($config['planes'] ?? null, 'planes');
            $puntosInput = $this->lista($config['puntosControl'] ?? [], 'puntosControl');
            if ($planesInput === []) {
                throw new BusinessRuleException('La plantilla debe definir al menos un plan.');
            }
            $nombresPlanes = [];
            $nombresPuntos = [];
            if ($establecimiento->getId() !== null) {
                foreach ($this->planes->findBy(['establecimiento' => $establecimiento]) as $plan) {
                    $nombresPlanes[$this->claveNombre($plan->getNombre())] = true;
                }
                foreach ($this->puntos->findBy(['establecimiento' => $establecimiento]) as $punto) {
                    $nombresPuntos[$this->claveNombre($punto->getNombre())] = true;
                }
            }
            $resultado = ['planes' => [], 'puntos' => [], 'tareas' => []];
            $puntosPorClave = [];
            foreach ($puntosInput as $input) {
                $this->claves($input, ['clave', 'nombre', 'tipo', 'descripcion']);
                $clave = $this->texto($input, 'clave');
                $nombre = $this->texto($input, 'nombre');
                if (isset($puntosPorClave[$clave], $nombresPuntos[$this->claveNombre($nombre)])) {
                    throw new BusinessRuleException('La plantilla repite un punto de control.');
                }
                if (isset($puntosPorClave[$clave]) || isset($nombresPuntos[$this->claveNombre($nombre)])) {
                    throw new BusinessRuleException('Ya existe un punto de control con esa clave o nombre; no se aplicará la plantilla otra vez.');
                }
                $tipo = TipoPuntoControl::tryFrom($this->texto($input, 'tipo')) ?? throw new BusinessRuleException('Tipo de punto de control no válido.');
                $punto = (new PuntoControl())->setEstablecimiento($establecimiento)->setNombre($nombre)->setTipo($tipo)
                    ->setDescripcion($this->textoOpcional($input, 'descripcion'));
                $this->validacion->validar($punto);
                $this->em->persist($punto);
                $puntosPorClave[$clave] = $punto;
                $nombresPuntos[$this->claveNombre($nombre)] = true;
                $resultado['puntos'][] = $punto;
            }
            foreach ($planesInput as $input) {
                $this->claves($input, ['nombre', 'tipo', 'descripcion', 'tareas']);
                $nombre = $this->texto($input, 'nombre');
                if (isset($nombresPlanes[$this->claveNombre($nombre)])) {
                    throw new BusinessRuleException('Ya existe un plan con ese nombre; no se aplicará la plantilla otra vez.');
                }
                $tipo = TipoPlanControl::tryFrom($this->texto($input, 'tipo')) ?? throw new BusinessRuleException('Tipo de plan no válido.');
                $plan = (new PlanControl())->setEstablecimiento($establecimiento)->setNombre($nombre)->setTipo($tipo)
                    ->setDescripcion($this->textoOpcional($input, 'descripcion'))->setCreatedAt($this->clock->now());
                $this->validacion->validar($plan);
                $this->em->persist($plan);
                $nombresPlanes[$this->claveNombre($nombre)] = true;
                $resultado['planes'][] = $plan;
                $nombresTareas = [];
                foreach ($this->lista($input['tareas'] ?? [], 'tareas') as $taskInput) {
                    $tarea = $this->crearTarea($taskInput, $plan, $establecimiento, $puntosPorClave);
                    $clave = $this->claveNombre($tarea->getNombre());
                    if (isset($nombresTareas[$clave])) {
                        throw new BusinessRuleException('La plantilla repite una tarea dentro del mismo plan.');
                    }
                    $nombresTareas[$clave] = true;
                    $this->validacion->validar($tarea);
                    $this->em->persist($tarea);
                    $resultado['tareas'][] = $tarea;
                }
            }

            // Necesitamos los identificadores para el recibo. Este flush sigue dentro de
            // la transacción exterior (también en onboarding); no confirma nada por sí solo.
            $this->em->flush();
            $resumen = [];
            foreach (['planes' => '/api/planes-control/', 'puntos' => '/api/puntos-control/', 'tareas' => '/api/tareas/'] as $tipo => $prefijo) {
                $resumen[$tipo] = array_map(static function ($recurso) use ($prefijo): array {
                    $item = ['id' => $recurso->getId(), 'iri' => $prefijo.$recurso->getId(), 'nombre' => $recurso->getNombre()];
                    if ($recurso instanceof TareaAPPCC) {
                        $item += ['activa' => $recurso->isActiva(), 'configuracionPendiente' => $recurso->isConfiguracionPendiente(),
                            'planControl' => '/api/planes-control/'.$recurso->getPlanControl()->getId(),
                            'puntoControl' => $recurso->getPuntoControl() === null ? null : '/api/puntos-control/'.$recurso->getPuntoControl()->getId()];
                    }
                    return $item;
                }, $resultado[$tipo]);
            }
            $aplicacion = new AplicacionPlantillaAPPCC($plantilla, $establecimiento, $resumen, $this->clock->now());
            $this->em->persist($aplicacion);
            return $resultado + ['aplicacion' => $aplicacion, 'yaAplicada' => false];
        });
    }

    /** @param array<string, mixed> $input @param array<string, PuntoControl> $puntos */
    private function crearTarea(array $input, PlanControl $plan, Establecimiento $local, array $puntos): TareaAPPCC
    {
        $this->claves($input, ['nombre', 'frecuencia', 'puntoControl', 'descripcion', 'horaPrevista', 'diaSemana', 'diaMes', 'plazoMinutos', 'limiteMinimo', 'limiteMaximo', 'unidad', 'instrucciones', 'configuracion', 'obligatoria', 'requiereLimites']);
        $frecuencia = FrecuenciaTarea::tryFrom($this->texto($input, 'frecuencia')) ?? throw new BusinessRuleException('Frecuencia de tarea no válida.');
        $tarea = (new TareaAPPCC())->setEstablecimiento($local)->setPlanControl($plan)
            ->setNombre($this->texto($input, 'nombre'))->setFrecuencia($frecuencia)->setCreatedAt($this->clock->now());
        foreach (['descripcion', 'limiteMinimo', 'limiteMaximo', 'unidad', 'instrucciones'] as $campo) {
            $tarea->{'set'.ucfirst($campo)}($this->textoOpcional($input, $campo));
        }
        if (isset($input['puntoControl'])) {
            $clave = $this->texto($input, 'puntoControl');
            $tarea->setPuntoControl($puntos[$clave] ?? throw new BusinessRuleException('La tarea referencia un punto que no está definido en la plantilla.'));
        }
        if (isset($input['horaPrevista'])) {
            $hora = $this->texto($input, 'horaPrevista');
            $fecha = \DateTimeImmutable::createFromFormat('!H:i:s', $hora);
            if ($fecha === false || $fecha->format('H:i:s') !== $hora) {
                throw new BusinessRuleException('La hora prevista debe tener el formato HH:mm:ss.');
            }
            $tarea->setHoraPrevista($fecha);
        }
        $tarea->setDiaSemana($this->enteroOpcional($input, 'diaSemana'))
            ->setDiaMes($this->enteroOpcional($input, 'diaMes'))
            ->setPlazoMinutos($this->enteroOpcional($input, 'plazoMinutos'));
        if (isset($input['configuracion'])) {
            if (!is_array($input['configuracion'])) {
                throw new BusinessRuleException('La configuración de la tarea debe ser un objeto JSON.');
            }
            $tarea->setConfiguracion($input['configuracion']);
        }
        if (array_key_exists('obligatoria', $input)) {
            if (!is_bool($input['obligatoria'])) {
                throw new BusinessRuleException('El campo obligatoria debe ser booleano.');
            }
            $tarea->setObligatoria($input['obligatoria']);
        }
        if (array_key_exists('requiereLimites', $input)) {
            if (!is_bool($input['requiereLimites'])) { throw new BusinessRuleException('requiereLimites debe ser booleano.'); }
            if ($input['requiereLimites']) { $tarea->exigirLimites(); $tarea->setActiva(false); }
        }
        if (($tarea->getConfiguracion()['tipoRespuesta'] ?? null) === 'numero'
            && $tarea->getLimiteMinimo() === null && $tarea->getLimiteMaximo() === null) {
            $tarea->exigirLimites();
            $tarea->setActiva(false);
        }

        return $tarea;
    }

    /** @return list<array<string, mixed>> */
    private function lista(mixed $value, string $campo): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new BusinessRuleException('El campo '.$campo.' debe ser una lista.');
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new BusinessRuleException('Cada elemento de '.$campo.' debe ser un objeto.');
            }
        }

        return $value;
    }

    private function texto(array $input, string $campo): string
    {
        $value = $this->textoOpcional($input, $campo);
        if ($value === null || trim($value) === '') {
            throw new BusinessRuleException('La plantilla debe indicar '.$campo.'.');
        }

        return trim($value);
    }

    private function textoOpcional(array $input, string $campo): ?string
    {
        $value = $input[$campo] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BusinessRuleException('El campo '.$campo.' debe ser texto; los decimales se representan como strings.');
        }

        return $value;
    }

    private function enteroOpcional(array $input, string $campo): ?int
    {
        $value = $input[$campo] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new BusinessRuleException('El campo '.$campo.' debe ser un entero o null.');
        }

        return $value;
    }

    private function claves(array $input, array $permitidas): void
    {
        if (array_diff(array_keys($input), $permitidas) !== []) {
            throw new BusinessRuleException('La plantilla contiene campos no soportados en esta estructura.');
        }
    }

    private function claveNombre(string $nombre): string
    {
        return mb_strtolower(trim($nombre));
    }
}
