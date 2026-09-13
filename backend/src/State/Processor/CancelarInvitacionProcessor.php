<?php
declare(strict_types=1);
namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\InvitacionUsuarioService;

/** @implements ProcessorInterface<mixed, \App\Entity\InvitacionUsuario> */
final readonly class CancelarInvitacionProcessor implements ProcessorInterface
{
    public function __construct(private InvitacionUsuarioService $service, private \App\Security\CurrentEstablecimientoContext $current) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): \App\Entity\InvitacionUsuario
    {
        return $this->service->cancelar(ProgramacionInputResolver::identificador($uriVariables['id']), $this->current->establecimiento(), $this->current->usuario());
    }
}

