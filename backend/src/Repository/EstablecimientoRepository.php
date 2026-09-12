<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Establecimiento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Establecimiento> */
class EstablecimientoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Establecimiento::class);
    }
}
