<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ConfiguracionEntidadFiscal;
use App\Entity\ConfiguracionEstablecimiento;
use App\Entity\EntidadFiscal;
use App\Entity\Establecimiento;
use App\Entity\PlantillaAPPCC;
use App\Entity\Usuario;
use App\Entity\UsuarioEstablecimiento;
use App\Enum\RolEstablecimiento;
use App\Exception\BusinessRuleException;
use App\Repository\ConfiguracionEntidadFiscalRepository;
use App\Repository\EntidadFiscalRepository;
use App\Repository\UsuarioRepository;
use App\Service\Support\TransaccionAPPCC;
use App\Service\Support\ValidacionDominio;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class OnboardingService
{
    public function __construct(
        private EntityManagerInterface $em,
        private EntidadFiscalRepository $entidades,
        private UsuarioRepository $usuarios,
        private ConfiguracionEntidadFiscalRepository $configuraciones,
        private PlantillaAPPCCService $plantillas,
        private ValidacionDominio $validacion,
        private TransaccionAPPCC $transaccion,
        private ClockInterface $clock,
    ) {
    }

    public function crearOnboarding(EntidadFiscal $titular, Establecimiento $nuevoLocal, Usuario $administrador, ?PlantillaAPPCC $plantilla = null): Establecimiento
    {
        if ($nuevoLocal->getId() !== null || $nuevoLocal->getConfiguracion() !== null) {
            throw new BusinessRuleException('El onboarding necesita un establecimiento nuevo sin configuración.');
        }

        return $this->transaccion->ejecutar(function () use ($titular, $nuevoLocal, $administrador, $plantilla): Establecimiento {
            if ($titular->getId() !== null) {
                $titular = $this->entidades->find($titular->getId()) ?? throw new BusinessRuleException('La entidad fiscal no existe.');
            }
            if (!$titular->isActivo() || !$nuevoLocal->isActivo()) {
                throw new BusinessRuleException('El titular y el establecimiento deben estar activos.');
            }
            $this->validacion->validar($titular);
            $this->em->persist($titular);
            $configFiscal = $titular->getId() === null ? $titular->getConfiguracion() : $this->configuraciones->findOneBy(['entidadFiscal' => $titular]);
            if ($configFiscal === null) {
                $configFiscal = (new ConfiguracionEntidadFiscal())->setEntidadFiscal($titular)->setCreatedAt($this->clock->now());
                $this->validacion->validar($configFiscal);
                $this->em->persist($configFiscal);
            }
            $nuevoLocal->setEntidadFiscal($titular)->setCreatedAt($this->clock->now());
            $this->validacion->validar($nuevoLocal);
            $this->em->persist($nuevoLocal);
            $configLocal = (new ConfiguracionEstablecimiento())->setEstablecimiento($nuevoLocal)->setCreatedAt($this->clock->now());
            $this->validacion->validar($configLocal);
            $this->em->persist($configLocal);

            if ($administrador->getId() !== null) {
                $administrador = $this->usuarios->find($administrador->getId()) ?? throw new BusinessRuleException('El administrador no existe.');
            } else {
                $administrador = $this->usuarios->findOneBy(['email' => $administrador->getEmail()]) ?? $administrador;
            }
            if (!$administrador->isActivo()) {
                throw new BusinessRuleException('El administrador está inactivo.');
            }
            $this->validacion->validar($administrador);
            if ($administrador->getId() === null) {
                $administrador->setCreatedAt($this->clock->now());
                $this->em->persist($administrador);
            }
            $membresia = (new UsuarioEstablecimiento())->setUsuario($administrador)->setEstablecimiento($nuevoLocal)->setRol(RolEstablecimiento::ADMIN);
            $this->validacion->validar($membresia);
            $this->em->persist($membresia);
            if ($plantilla !== null) {
                $this->plantillas->aplicar($plantilla, $nuevoLocal);
            }

            return $nuevoLocal;
        });
    }
}
