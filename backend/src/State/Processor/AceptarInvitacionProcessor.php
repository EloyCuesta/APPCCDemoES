<?php
declare(strict_types=1);
namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\AceptarInvitacionInput;
use App\Dto\AltaUsuarioOutput;
use App\Service\InvitacionUsuarioService;

/** @implements ProcessorInterface<AceptarInvitacionInput, AltaUsuarioOutput> */
final readonly class AceptarInvitacionProcessor implements ProcessorInterface
{
    public function __construct(private InvitacionUsuarioService $service) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AltaUsuarioOutput
    {
        if (!$data instanceof AceptarInvitacionInput) { throw new \InvalidArgumentException('Entrada no válida.'); }
        return $this->service->aceptar($data);
    }
}

