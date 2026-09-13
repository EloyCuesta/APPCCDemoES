<?php
declare(strict_types=1);
namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CrearInvitacionInput;
use App\Dto\InvitacionCreadaOutput;
use App\Service\InvitacionUsuarioService;

/** @implements ProcessorInterface<CrearInvitacionInput, InvitacionCreadaOutput> */
final readonly class CrearInvitacionProcessor implements ProcessorInterface
{
    public function __construct(private InvitacionUsuarioService $service, private \App\Security\CurrentEstablecimientoContext $current) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvitacionCreadaOutput
    {
        if (!$data instanceof CrearInvitacionInput) { throw new \InvalidArgumentException('Entrada no válida.'); }
        return $this->service->crear($this->current->establecimiento(), $this->current->usuario(), $data);
    }
}

