<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConfiguracionEstablecimiento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ConfiguracionEstablecimiento> */
class ConfiguracionEstablecimientoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracionEstablecimiento::class);
    }
}
