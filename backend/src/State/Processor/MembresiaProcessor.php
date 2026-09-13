<?php
declare(strict_types=1);
namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CambiarRolInput;
use App\Entity\UsuarioEstablecimiento;
use App\Security\CurrentEstablecimientoContext;
use App\Service\MembresiaService;

/** @implements ProcessorInterface<CambiarRolInput, UsuarioEstablecimiento> */
final readonly class MembresiaProcessor implements ProcessorInterface
{
    public function __construct(private MembresiaService $service, private CurrentEstablecimientoContext $current) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UsuarioEstablecimiento
    {
        $local = $this->current->establecimiento();
        $autor = $this->current->usuario();
        $id = ProgramacionInputResolver::identificador($uriVariables['id']);
        return match ($operation->getName()) {
            'baja_membresia' => $this->service->baja($id, $local, $autor),
            'reactivar_membresia' => $this->service->reactivar($id, $local, $autor),
            default => $this->service->cambiarRol($id, $local, $autor, $data->rol),
        };
    }
}
