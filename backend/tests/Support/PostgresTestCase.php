<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\{EntidadFiscal, Establecimiento, Usuario, PlanControl, TareaAPPCC, TareaProgramada, RegistroAPPCC};
use App\Enum\{TipoEntidadFiscal, TipoActividad, TipoPlanControl, FrecuenciaTarea};
use App\Service\{OnboardingService, TareaProgramadaService, RegistroAPPCCService};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\{Clock, MockClock};

abstract class PostgresTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected Establecimiento $local;
    protected Establecimiento $otroLocal;
    protected Usuario $usuario;
    protected Usuario $otroUsuario;
    protected TareaAPPCC $tarea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-09-12T10:00:00+00:00');
        Clock::set($this->clock);
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        PostgresSafety::truncate($this->em->getConnection());
        $this->usuario = (new Usuario())->setNombre('Ana')->setApellidos('García')->setEmail('ANA@example.com');
        $this->otroUsuario = (new Usuario())->setNombre('Luis')->setApellidos('Pérez')->setEmail('luis@example.com');
        $onboarding = self::getContainer()->get(OnboardingService::class);
        $this->local = $onboarding->crearOnboarding($this->fiscal('B12345678'), $this->establecimiento('Obrador'), $this->usuario);
        $this->otroLocal = $onboarding->crearOnboarding($this->fiscal('B87654321'), $this->establecimiento('Catering'), $this->otroUsuario);
        $plan = (new PlanControl())->setEstablecimiento($this->local)->setTipo(TipoPlanControl::TEMPERATURAS)->setNombre('Temperaturas');
        $this->tarea = (new TareaAPPCC())->setEstablecimiento($this->local)->setPlanControl($plan)->setNombre('Cámara')
            ->setFrecuencia(FrecuenciaTarea::DIARIA)->setHoraPrevista(new \DateTimeImmutable('09:00:00'))
            ->setLimiteMinimo('0')->setLimiteMaximo('5')->setConfiguracion(['tipoRespuesta' => 'numero']);
        $this->em->persist($plan);
        $this->em->persist($this->tarea);
        $this->em->flush();
    }

    protected function fiscal(string $nif): EntidadFiscal
    {
        return (new EntidadFiscal())->setTipo(TipoEntidadFiscal::EMPRESA)->setRazonSocial('Empresa prueba')->setNif($nif)
            ->setDireccion('Mayor 1')->setCodigoPostal('28001')->setLocalidad('Madrid')->setProvincia('Madrid');
    }

    protected function establecimiento(string $nombre): Establecimiento
    {
        return (new Establecimiento())->setNombre($nombre)->setTipoActividad(TipoActividad::OBRADOR)
            ->setDireccion('Mayor 1')->setCodigoPostal('28001')->setLocalidad('Madrid')->setProvincia('Madrid');
    }

    protected function programar(string $when = '-1 minute', ?\DateTimeImmutable $limite = null): TareaProgramada
    {
        return self::getContainer()->get(TareaProgramadaService::class)->programar($this->tarea, $this->local, $this->clock->now()->modify($when), $this->usuario, $limite);
    }

    protected function registro(TareaProgramada $p, string $valor = '3'): RegistroAPPCC
    {
        return (new RegistroAPPCC())->setTareaProgramada($p)->setEstablecimiento($this->local)->setUsuario($this->usuario)
            ->setFechaHora($this->clock->now())->setValorNumerico($valor)->setObservaciones('Control de prueba.');
    }

    protected function registrar(string $valor = '3', string $when = '-1 minute'): RegistroAPPCC
    {
        return self::getContainer()->get(RegistroAPPCCService::class)->registrar($this->registro($this->programar($when), $valor));
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) { $this->em->getConnection()->close(); }
        parent::tearDown();
    }
}
