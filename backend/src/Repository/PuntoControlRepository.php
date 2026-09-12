<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PuntoControl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PuntoControl> */
class PuntoControlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PuntoControl::class);
    }
}
