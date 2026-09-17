<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\{Operation, QueryParameterInterface};
use ApiPlatform\State\ProviderInterface;
use App\Entity\{Incidencia, RegistroAPPCC};
use App\Security\IriTenantResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Valida la consulta y su padre; el provider ORM conserva filtros, TenantExtension y paginador SQL. */
final readonly class ConsultaCollectionProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
        private IriTenantResolver $iris,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $permitidos = [];
        foreach ($operation->getParameters() ?? [] as $parameter) {
            if ($parameter instanceof QueryParameterInterface) { $permitidos[] = $parameter->getKey(); }
        }
        // La validación nativa estricta de Platform 4.3 compara claves raíz y no distingue
        // order[campo] o fecha[operador]. Aquí se verifica la clave completa, incluso repetida.
        $vistos = [];
        foreach (explode('&', ($context['request'] ?? null)?->server->get('QUERY_STRING', '') ?? '') as $par) {
            if ($par === '') { continue; }
            $key = urldecode(explode('=', $par, 2)[0]);
            if (!in_array($key, $permitidos, true) || isset($vistos[$key])) {
                throw new BadRequestHttpException('Parámetro desconocido, repetido o con estructura no admitida: '.$key);
            }
            $vistos[$key] = true;
        }
        foreach ($operation->getUriVariables() ?? [] as $key => $link) {
            $class = $link->getFromClass();
            $ruta = match ($class) {
                Incidencia::class => '/api/incidencias',
                RegistroAPPCC::class => '/api/registros',
                default => throw new \LogicException('Subrecurso de consulta no configurado.'),
            };
            $this->iris->resolver($ruta.'/'.($uriVariables[$key] ?? ''), $class);
        }

        return $this->collectionProvider->provide($operation, $uriVariables, $context);
    }
}
