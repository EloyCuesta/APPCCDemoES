<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Evidencia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Evidencia> */
class EvidenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evidencia::class);
    }
}
