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
}
