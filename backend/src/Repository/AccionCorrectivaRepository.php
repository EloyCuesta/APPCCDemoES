<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccionCorrectiva;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccionCorrectiva> */
class AccionCorrectivaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccionCorrectiva::class);
    }

    public function existsByIncidencia(\App\Entity\Incidencia $incidencia): bool
    {
        return $this->count(['incidencia' => $incidencia]) > 0;
    }
}
