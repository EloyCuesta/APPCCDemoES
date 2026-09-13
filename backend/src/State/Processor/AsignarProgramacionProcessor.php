<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\AsignarProgramacionInput;
use App\Entity\TareaProgramada;
use App\Exception\BusinessRuleException;
use App\Security\TenantAuthorization;
use App\Service\TareaProgramadaService;

/** @implements ProcessorInterface<AsignarProgramacionInput, TareaProgramada> */
final readonly class AsignarProgramacionProcessor implements ProcessorInterface
{
    public function __construct(private TareaProgramadaService $service, private ProgramacionInputResolver $input, private TenantAuthorization $authorization) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TareaProgramada
    {
        if (!$data instanceof AsignarProgramacionInput) { throw new \InvalidArgumentException('Entrada no válida.'); }
        $this->authorization->assertGestionTareas();
        $programada = $this->service->buscarEnEstablecimiento(ProgramacionInputResolver::identificador($uriVariables['id']));
        if (!(new \ReflectionProperty($data, 'usuario'))->isInitialized($data)) {
            throw new BusinessRuleException('Indica usuario; utiliza null para desasignar.');
        }
        return $this->service->asignar($programada, $this->input->usuario($data->usuario));
    }
}
