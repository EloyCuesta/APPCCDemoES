<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Service\Support\{CalendarioAPPCC, FechaAPPCC};
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class FechaConsultaFilter implements FilterInterface
{
    public function __construct(private CalendarioAPPCC $calendario) {}

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $parameter = $context['parameter'];
        if (!is_string($valor = $parameter->getValue())) { throw new BadRequestHttpException('La fecha debe ser un instante ISO 8601.'); }
        try { $fecha = FechaAPPCC::parsear($valor, false); }
        catch (\InvalidArgumentException $e) { throw new BadRequestHttpException($e->getMessage(), $e); }
        $campo = $parameter->getProperty();
        $nombre = $queryNameGenerator->generateParameterName($campo);
        $operador = str_ends_with($parameter->getKey(), '[after]') ? '>=' : '<=';
        $queryBuilder->andWhere(sprintf('%s.%s %s :%s', $queryBuilder->getRootAliases()[0], $campo, $operador, $nombre))
            ->setParameter($nombre, $this->calendario->paraPersistir($fecha), 'datetime_immutable');
    }

    public function getDescription(string $resourceClass): array { return []; }
}
