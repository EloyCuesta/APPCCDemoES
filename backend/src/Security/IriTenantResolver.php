<?php

declare(strict_types=1);

namespace App\Security;

use ApiPlatform\Metadata\Exception\{InvalidArgumentException, ItemNotFoundException};
use ApiPlatform\Metadata\{Get, IriConverterInterface};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, NotFoundHttpException};

final readonly class IriTenantResolver
{
    public function __construct(private IriConverterInterface $iris) {}

    public function resolver(mixed $iri, string $class): object
    {
        if (!is_string($iri)) { throw new BadRequestHttpException('El filtro debe contener una única IRI.'); }
        if (!preg_match('~^/api/[a-z-]+/([1-9][0-9]*)$~D', $iri, $parts)
            || filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['max_range' => 2147483647]]) === false) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        }
        try {
            // fetch_data evita referencias ORM sin consulta: TenantExtension valida el elemento.
            return $this->iris->getResourceFromIri($iri, ['fetch_data' => true], new Get(class: $class));
        } catch (InvalidArgumentException|ItemNotFoundException $e) {
            throw new NotFoundHttpException('Recurso no encontrado en el establecimiento seleccionado.', $e);
        }
    }
}
