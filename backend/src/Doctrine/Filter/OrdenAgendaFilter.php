<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\{FilterInterface, SortFilter};
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/** Extiende SortFilter únicamente con el desempate estable necesario para paginar. */
final class OrdenAgendaFilter implements FilterInterface
{
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        (new SortFilter())->apply($queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);
        $queryBuilder->addOrderBy($queryBuilder->getRootAliases()[0].'.id', 'ASC');
    }

    public function getDescription(string $resourceClass): array { return []; }
}
