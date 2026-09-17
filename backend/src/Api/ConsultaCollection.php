<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Metadata\{CollectionOperationInterface, HeaderParameter, HttpOperation, QueryParameter};
use ApiPlatform\OpenApi\Model\{Operation as OpenApiOperation, Parameter as OpenApiParameter};
use ApiPlatform\Validator\Exception\ValidationException;
use App\State\Provider\ConsultaCollectionProvider;
use Symfony\Component\Validator\Constraints as Assert;

/** Contrato común de las colecciones de consulta del MVP. */
final class ConsultaCollection extends HttpOperation implements CollectionOperationInterface
{
    public function __construct(string $uriTemplate, array $parameters, array $order = ['id' => 'ASC'], ?array $uriVariables = null)
    {
        $paginacion = [
            'page' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'default' => 1],
                description: 'Página, empezando por 1.', castToArray: false, castToNativeType: false,
                constraints: [new Assert\Regex('/^[1-9][0-9]*$/D'), new Assert\Range(min: 1, max: 2147483647)]),
            'itemsPerPage' => new QueryParameter(schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30],
                description: 'Elementos por página (1–100).', castToArray: false, castToNativeType: false,
                constraints: [new Assert\Regex('/^[1-9][0-9]*$/D'), new Assert\Range(min: 1, max: 100)]),
        ];
        parent::__construct(
            method: 'GET',
            uriTemplate: $uriTemplate,
            uriVariables: $uriVariables,
            requirements: ['id' => '[1-9][0-9]*'],
            paginationEnabled: true,
            paginationClientEnabled: false,
            paginationItemsPerPage: 30,
            paginationClientItemsPerPage: true,
            paginationMaximumItemsPerPage: 100,
            paginationPartial: false,
            paginationClientPartial: false,
            order: $order,
            provider: ConsultaCollectionProvider::class,
            exceptionToStatus: [ValidationException::class => 400],
            // Evita que los parámetros automáticos de paginación documenten minimum: 0.
            openapi: new OpenApiOperation(parameters: array_map(
                static fn ($key, $p) => new OpenApiParameter($key, 'query', $p->getDescription(), schema: $p->getSchema()),
                array_keys($paginacion), array_values($paginacion),
            )),
            parameters: $parameters + $paginacion + [
                'X-Establecimiento-Id' => new HeaderParameter(schema: ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
                    description: 'Establecimiento seleccionado; requiere membresía activa y JWT.', required: true, castToArray: false),
            ],
        );
    }
}
