<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\OmitirProgramacionInput;
use App\Entity\TareaProgramada;
use App\Security\TenantAuthorization;
use App\Service\TareaProgramadaService;

/** @implements ProcessorInterface<OmitirProgramacionInput, TareaProgramada> */
final readonly class OmitirProgramacionProcessor implements ProcessorInterface
{
    public function __construct(private TareaProgramadaService $service, private TenantAuthorization $authorization) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TareaProgramada
    {
        if (!$data instanceof OmitirProgramacionInput) { throw new \InvalidArgumentException('Entrada no válida.'); }
        $this->authorization->assertGestionTareas();
        return $this->service->omitir($this->service->buscarEnEstablecimiento(ProgramacionInputResolver::identificador($uriVariables['id'])), $data->motivo);
    }
}
