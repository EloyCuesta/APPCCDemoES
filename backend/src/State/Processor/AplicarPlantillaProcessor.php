<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\{AplicarPlantillaInput, AplicarPlantillaOutput};
use App\Repository\PlantillaAPPCCRepository;
use App\Security\{CurrentEstablecimientoContext, TenantAuthorization};
use App\Service\PlantillaAPPCCService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<AplicarPlantillaInput, AplicarPlantillaOutput> */
final readonly class AplicarPlantillaProcessor implements ProcessorInterface
{
    public function __construct(private CurrentEstablecimientoContext $current, private TenantAuthorization $authorization,
        private PlantillaAPPCCRepository $plantillas, private PlantillaAPPCCService $service) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AplicarPlantillaOutput
    {
        $local = $this->current->establecimiento();
        $this->authorization->assertGestionTareas();
        $plantilla = $this->plantillas->find(ProgramacionInputResolver::identificador($uriVariables['id']))
            ?? throw new NotFoundHttpException('Plantilla no encontrada.');
        $resultado = $this->service->aplicar($plantilla, $local);
        $aplicacion = $resultado['aplicacion'];

        return new AplicarPlantillaOutput($aplicacion->getId(), $plantilla->getId(), $local->getId(), $resultado['yaAplicada'],
            $aplicacion->getCreatedAt(), array_map(count(...), array_intersect_key($resultado, array_flip(['planes', 'puntos', 'tareas']))),
            $aplicacion->getResultado());
    }
}
