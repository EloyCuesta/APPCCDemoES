<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Service\Support\{CalendarioAPPCC, FechaAPPCC};
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Parámetros Platform 4.3; convertir offsets antes de comparar TIMESTAMP WITHOUT TIME ZONE. */
final readonly class FechaAgendaFilter implements FilterInterface
{
    public function __construct(private CalendarioAPPCC $calendario) {}

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $parameter = $context['parameter'];
        $valor = $parameter->getValue();
        if (!is_string($valor)) { throw new BadRequestHttpException('El filtro de fecha debe ser un instante ISO o un día YYYY-MM-DD.'); }
        try { $fecha = FechaAPPCC::parsear($valor); }
        catch (\InvalidArgumentException $e) { throw new BadRequestHttpException($e->getMessage(), $e); }
        $campo = $parameter->getProperty();
        $nombre = $queryNameGenerator->generateParameterName($campo);
        $operador = str_ends_with($parameter->getKey(), '[after]') ? '>=' : '<=';
        $queryBuilder->andWhere(sprintf('%s.%s %s :%s', $queryBuilder->getRootAliases()[0], $campo, $operador, $nombre))
            ->setParameter($nombre, $this->calendario->paraPersistir($fecha), 'datetime_immutable');
    }

    public function getDescription(string $resourceClass): array { return []; }
}
