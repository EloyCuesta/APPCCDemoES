<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CrearProgramacionInput;
use App\Entity\TareaProgramada;
use App\Security\TenantAuthorization;
use App\Service\TareaProgramadaService;

/** @implements ProcessorInterface<CrearProgramacionInput, TareaProgramada> */
final readonly class CrearProgramacionProcessor implements ProcessorInterface
{
    public function __construct(private TareaProgramadaService $service, private ProgramacionInputResolver $input, private TenantAuthorization $authorization) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TareaProgramada
    {
        if (!$data instanceof CrearProgramacionInput) { throw new \InvalidArgumentException('Entrada no válida.'); }
        $this->authorization->assertGestionTareas();
        return $this->service->programarManual(ProgramacionInputResolver::identificador($uriVariables['id']), $this->input->fecha($data->fechaProgramada),
            $this->input->usuario($data->asignadoA), $data->fechaLimite === null ? null : $this->input->fecha($data->fechaLimite));
    }
}
