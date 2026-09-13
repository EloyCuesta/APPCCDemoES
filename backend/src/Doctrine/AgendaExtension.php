<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\TareaProgramada;
use Doctrine\ORM\QueryBuilder;

/** Restricción adicional; TenantExtension sigue aplicando el establecimiento en SQL. */
final class AgendaExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if ($resourceClass !== TareaProgramada::class || $operation?->getName() !== 'agenda_programaciones') { return; }
        $param = $queryNameGenerator->generateParameterName('abiertas');
        $queryBuilder->andWhere($queryBuilder->getRootAliases()[0].'.estado IN (:'.$param.')')
            ->setParameter($param, ['pendiente', 'vencida']);
    }
}
