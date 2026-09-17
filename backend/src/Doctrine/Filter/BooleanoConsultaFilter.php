<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BooleanoConsultaFilter implements FilterInterface
{
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $parameter = $context['parameter'];
        $value = match ($parameter->getValue()) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new BadRequestHttpException('El booleano debe ser true, false, 1 o 0.'),
        };
        $field = $parameter->getProperty();
        $name = $queryNameGenerator->generateParameterName($field);
        $queryBuilder->andWhere($queryBuilder->getRootAliases()[0].'.'.$field.' = :'.$name)->setParameter($name, $value, Types::BOOLEAN);
    }

    public function getDescription(string $resourceClass): array { return []; }
}
