<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UsuarioEstablecimiento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UsuarioEstablecimiento> */
class UsuarioEstablecimientoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsuarioEstablecimiento::class);
    }

    public function findActiveMembership(\App\Entity\Usuario $usuario, \App\Entity\Establecimiento $establecimiento): ?UsuarioEstablecimiento
    {
        return $this->findOneBy(['usuario' => $usuario, 'establecimiento' => $establecimiento, 'activo' => true]);
    }

    /** @return list<UsuarioEstablecimiento> Contexto del propio usuario; no es una colección pública de tenants. */
    public function findValidForSession(\App\Entity\Usuario $usuario): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('e', 'f')
            ->innerJoin('m.establecimiento', 'e')
            ->innerJoin('e.entidadFiscal', 'f')
            ->andWhere('m.usuario = :usuario AND m.activo = true AND e.activo = true AND f.activo = true')
            ->setParameter('usuario', $usuario)
            ->orderBy('m.id', 'ASC')
            ->getQuery()->getResult();
    }
}
