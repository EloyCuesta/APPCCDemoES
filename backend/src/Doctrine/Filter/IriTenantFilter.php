<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\{FilterInterface, IriFilter};
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Security\IriTenantResolver;
use Doctrine\ORM\QueryBuilder;

final readonly class IriTenantFilter implements FilterInterface
{
    public function __construct(private IriTenantResolver $iris) {}

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $parameter = $context['parameter'];
        $class = $resourceClass;
        foreach (explode('.', $parameter->getProperty()) as $association) {
            $class = $queryBuilder->getEntityManager()->getClassMetadata($class)->getAssociationTargetClass($association);
        }
        $resolved = clone $parameter;
        $resolved->setValue($this->iris->resolver($parameter->getValue(), $class));
        $context['parameter'] = $resolved;
        (new IriFilter())->apply($queryBuilder, $queryNameGenerator, $resourceClass, $operation, $context);
    }

    public function getDescription(string $resourceClass): array { return []; }
}
