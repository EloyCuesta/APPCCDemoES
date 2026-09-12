<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Evidencia;
use App\Security\{CurrentEstablecimientoContext, TenantAuthorization};
use App\Service\Support\{ContextoAPPCC, TransaccionAPPCC, ValidacionDominio};
use Doctrine\ORM\EntityManagerInterface;

final readonly class EvidenciaService
{
    public function __construct(private EntityManagerInterface $em, private CurrentEstablecimientoContext $current, private TenantAuthorization $authorization, private ContextoAPPCC $contexto, private ValidacionDominio $validacion, private TransaccionAPPCC $transaccion) {}
    public function anadir(Evidencia $evidencia): Evidencia
    {
        if ($evidencia->getId() !== null) { throw new \App\Exception\BusinessRuleException('La evidencia histórica no puede modificarse.'); }
        if ($this->current->isApiRequest()) { $evidencia->setSubidaPor($this->current->usuario()); }
        $this->authorization->assertWrite($evidencia);
        $this->validacion->validar($evidencia);
        $parent = $evidencia->getRegistro() ?? $evidencia->getIncidencia();
        if ($parent?->getId() === null) { throw new \App\Exception\BusinessRuleException('El padre de la evidencia debe existir.'); }
        $local = $this->contexto->establecimiento($parent->getEstablecimiento());
        $evidencia->setSubidaPor($this->contexto->usuario($evidencia->getSubidaPor(), $local));
        return $this->transaccion->ejecutar(function () use ($evidencia): Evidencia { $this->em->persist($evidencia); return $evidencia; });
    }
}
