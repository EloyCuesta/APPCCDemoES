<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\{EntidadFiscal, Establecimiento, PlanControl, TareaAPPCC, TareaProgramada, Usuario, UsuarioEstablecimiento};
use App\Enum\{EstadoTareaProgramada, FrecuenciaTarea, RolEstablecimiento, TipoActividad, TipoEntidadFiscal};
use App\Service\{GeneradorTareasProgramadasService, OnboardingService, PlantillaAPPCCService, TareaAPPCCService, TareaProgramadaService};
use App\Service\Support\{CalendarioAPPCC, TransaccionAPPCC, ValidacionDominio};
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:demo:seed', description: 'Preparar una demo local reproducible (solo dev/test).')]
final class DemoSeedCommand extends Command
{
    public const PASSWORD = 'AppccDemo2026!';
    public const NIF = 'B00000000';
    public const NOMBRE = 'Restaurante APPCC Demo';

    public function __construct(
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        private readonly EntityManagerInterface $em,
        private readonly OnboardingService $onboarding,
        private readonly PlantillaAPPCCService $plantillas,
        private readonly TareaAPPCCService $tareas,
        private readonly GeneradorTareasProgramadasService $generador,
        private readonly TareaProgramadaService $programadas,
        private readonly CalendarioAPPCC $calendario,
        private readonly TransaccionAPPCC $transaccion,
        private readonly ValidacionDominio $validacion,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ClockInterface $clock,
    ) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Antes incluso de consultar la BD o cargar el catálogo.
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            $io->error('app:demo:seed está prohibido en prod y solo admite dev o test.');
            return Command::FAILURE;
        }

        $catalogo = $this->plantillas->cargarIniciales();
        $plantilla = array_values(array_filter($catalogo, static fn ($p) => $p->getCodigo() === 'restaurante-v1'))[0];
        [$local, $creado] = $this->transaccion->ejecutar(function () use ($plantilla): array {
            // Serializa semillas simultáneas sin INSERT SQL ni atajos de dominio.
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(20260923, 1)');
            $usuarios = [];
            foreach (RolEstablecimiento::cases() as $rol) {
                $email = $rol->value.'@appccdemo.local';
                $usuario = $this->em->getRepository(Usuario::class)->findOneBy(['email' => $email])
                    ?? (new Usuario())->setEmail($email)->setNombre(ucfirst($rol->value))->setApellidos('Demo');
                $usuario->setActivo(true);
                if (!$this->hasher->isPasswordValid($usuario, self::PASSWORD)) {
                    $usuario->setPassword($this->hasher->hashPassword($usuario, self::PASSWORD));
                }
                $this->validacion->validar($usuario);
                $this->em->persist($usuario);
                $usuarios[$rol->value] = $usuario;
            }
            $fiscal = $this->em->getRepository(EntidadFiscal::class)->findOneBy(['nif' => self::NIF])
                ?? (new EntidadFiscal())->setNif(self::NIF)->setTipo(TipoEntidadFiscal::EMPRESA)
                    ->setRazonSocial('APPCC Demo local')->setDireccion('Calle Demo 1')
                    ->setCodigoPostal('28001')->setLocalidad('Madrid')->setProvincia('Madrid');
            $fiscal->setActivo(true);
            $local = $fiscal->getId() === null ? null : $this->em->getRepository(Establecimiento::class)
                ->findOneBy(['entidadFiscal' => $fiscal, 'nombre' => self::NOMBRE]);
            $creado = $local === null;
            if ($local === null) {
                $local = $this->onboarding->crearOnboarding($fiscal,
                    (new Establecimiento())->setNombre(self::NOMBRE)->setTipoActividad(TipoActividad::RESTAURANTE)
                        ->setDireccion('Calle Demo 1')->setCodigoPostal('28001')->setLocalidad('Madrid')->setProvincia('Madrid'),
                    $usuarios['admin']);
            }
            $local->setActivo(true);
            $config = $local->getConfiguracion();
            $config->setPermiteRegistrosAtrasados(true)->setMaximoMinutosRegistroAtrasado(null)
                ->setRequiereFirmaRegistro(true)->setGeneraIncidenciaAutomatica(true)->setRequiereObservacionNoConforme(true);
            $this->validacion->validar($config);
            // Obtener IDs dentro de la misma transacción permite aplicar la plantilla y validar membresías.
            $this->em->flush();
            foreach (RolEstablecimiento::cases() as $rol) {
                $usuario = $usuarios[$rol->value];
                $member = $this->em->getRepository(UsuarioEstablecimiento::class)
                    ->findOneBy(['usuario' => $usuario, 'establecimiento' => $local])
                    ?? (new UsuarioEstablecimiento())->setUsuario($usuario)->setEstablecimiento($local);
                $member->setRol($rol)->setActivo(true);
                $this->validacion->validar($member);
                $this->em->persist($member);
            }
            $resultado = $this->plantillas->aplicar($plantilla, $local);
            $this->em->flush();
            // Solo adaptar la aplicación inicial: repetir seed conserva tareas e históricos.
            foreach ($resultado['tareas'] as $tarea) {
                // La demo comienza ayer para ofrecer controles recurrentes ya ejecutables a cualquier hora.
                $tarea->setCreatedAt($this->clock->now()->setTimezone($this->calendario->zonaHoraria($local))->modify('-1 day')->setTime(0, 0));
                if (($tarea->getConfiguracion()['tipoRespuesta'] ?? null) === 'numero') {
                    $coccion = $tarea->getFrecuencia() === FrecuenciaTarea::BAJO_DEMANDA;
                    $tarea->setLimiteMinimo($coccion ? '70' : '0')->setLimiteMaximo($coccion ? '100' : '5')
                        ->setInstrucciones('DEMO LOCAL: valores simulados para probar el flujo; no son límites validados para un establecimiento real. Introduzca la lectura del control.');
                }
                $tarea->setActiva(true);
                $this->tareas->guardarCambios($tarea);
            }
            return [$local, $creado];
        });

        $hoy = $this->clock->now()->setTimezone($this->calendario->zonaHoraria($local))->setTime(0, 0);
        $tareas = $this->em->getRepository(TareaAPPCC::class)->findBy(['establecimiento' => $local, 'activa' => true]);
        foreach ($tareas as $tarea) {
            if ($tarea->getFrecuencia() === FrecuenciaTarea::BAJO_DEMANDA) {
                // Una ejecución diaria de demostración, estable al repetir el comando ese día.
                $this->programadas->programar($tarea, $local, $hoy);
            } else {
                // El mismo generador del ciclo app:tareas:procesar, acotado al tenant demo.
                $this->generador->generar($hoy->modify('-1 day'), $hoy->modify('+7 days')->setTime(23, 59, 59), false, $tarea->getId());
            }
        }
        $this->programadas->detectarVencidas($local);
        $io->success(sprintf('Establecimiento %s: %s (ID %d).', $creado ? 'creado' : 'reutilizado', $local->getNombre(), $local->getId()));
        $io->warning('Credenciales exclusivas de desarrollo local. Nunca utilizar en producción.');
        $io->table(['Usuario', 'Rol', 'Contraseña de desarrollo'], array_map(
            static fn ($rol) => [$rol->value.'@appccdemo.local', $rol->value, self::PASSWORD], RolEstablecimiento::cases()));
        $io->table(['Recurso', 'Total'], [
            ['Planes', $this->em->getRepository(PlanControl::class)->count(['establecimiento' => $local])],
            ['Tareas', $this->em->getRepository(TareaAPPCC::class)->count(['establecimiento' => $local])],
            ['Ejecuciones pendientes/vencidas', $this->em->getRepository(TareaProgramada::class)->count([
                'establecimiento' => $local, 'estado' => [EstadoTareaProgramada::PENDIENTE, EstadoTareaProgramada::VENCIDA],
            ])],
        ]);
        return Command::SUCCESS;
    }
}
