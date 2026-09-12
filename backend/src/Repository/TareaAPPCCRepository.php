<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TareaAPPCC;
use App\Enum\FrecuenciaTarea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TareaAPPCC> */
class TareaAPPCCRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TareaAPPCC::class);
    }

    /** @return list<TareaAPPCC> */
    public function findActiveByEstablecimiento(\App\Entity\Establecimiento $establecimiento): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.planControl', 'p')->leftJoin('t.puntoControl', 'pc')
            ->andWhere('t.establecimiento = :local')->andWhere('t.activa = true')
            ->andWhere('p.activo = true')->andWhere('pc.id IS NULL OR pc.activo = true')
            ->setParameter('local', $establecimiento)->orderBy('t.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<TareaAPPCC> */
    public function findActiveForGeneration(): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.planControl', 'p')
            ->join('t.establecimiento', 'e')
            ->join('e.entidadFiscal', 'f')
            ->leftJoin('t.puntoControl', 'pc')
            ->andWhere('t.activa = true')
            ->andWhere('p.activo = true')
            ->andWhere('e.activo = true')
            ->andWhere('f.activo = true')
            ->andWhere('p.establecimiento = e.id')
            ->andWhere('pc.id IS NULL OR pc.establecimiento = e.id')
            ->andWhere('pc.id IS NULL OR pc.activo = true')
            ->andWhere('t.frecuencia IN (:frecuencias)')
            ->setParameter('frecuencias', [
                FrecuenciaTarea::DIARIA,
                FrecuenciaTarea::SEMANAL,
                FrecuenciaTarea::MENSUAL,
            ])
            ->orderBy('t.id', 'ASC')
            ->getQuery()->getResult();
    }
}
