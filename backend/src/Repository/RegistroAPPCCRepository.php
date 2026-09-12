<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RegistroAPPCC;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RegistroAPPCC> */
class RegistroAPPCCRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistroAPPCC::class);
    }

    public function existsForTaskBetween(\App\Entity\TareaAPPCC $tarea, \DateTimeImmutable $inicio, \DateTimeImmutable $fin): bool
    {
        return $this->createQueryBuilder('r')->select('r.id')
            ->andWhere('r.tarea = :tarea')->andWhere('r.fechaHora >= :inicio')->andWhere('r.fechaHora < :fin')
            ->setParameter('tarea', $tarea)
            ->setParameter('inicio', $inicio, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('fin', $fin, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    public function existsForTask(\App\Entity\TareaAPPCC $tarea): bool
    {
        return $this->count(['tarea' => $tarea]) > 0;
    }
}
