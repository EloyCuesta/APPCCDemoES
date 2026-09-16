<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\{ContextoSesionOutput, EntidadFiscalSesionOutput, EstablecimientoSesionOutput, MembresiaSesionOutput};
use App\Repository\UsuarioEstablecimientoRepository;
use App\Security\CurrentEstablecimientoContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class MeController
{
    public function __construct(private CurrentEstablecimientoContext $current, private UsuarioEstablecimientoRepository $membresias) {}

    #[Route('/api/me', name: 'appcc_me', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        // Solo identidad JWT: no resolver establecimiento(), rol() ni membresia().
        $usuario = $this->current->usuario();
        $membresias = [];
        foreach ($this->membresias->findValidForSession($usuario) as $membresia) {
            $local = $membresia->getEstablecimiento();
            $fiscal = $local->getEntidadFiscal();
            $nombreFiscal = trim($fiscal->getNombreComercial() ?? '');
            if ($nombreFiscal === '') { $nombreFiscal = trim($fiscal->getRazonSocial() ?? ''); }
            // Un autónomo puede no tener nombre comercial ni razón social.
            if ($nombreFiscal === '') { $nombreFiscal = trim($fiscal->getNombre().' '.$fiscal->getApellidos()); }
            $membresias[] = new MembresiaSesionOutput(
                $membresia->getId(), '/api/usuarios-establecimientos/'.$membresia->getId(), $membresia->getRol()->value,
                new EstablecimientoSesionOutput(
                    $local->getId(), '/api/establecimientos/'.$local->getId(), $local->getNombre(), $local->getTipoActividad()->value,
                    new EntidadFiscalSesionOutput($fiscal->getId(), $nombreFiscal),
                ),
            );
        }
        $contexto = new ContextoSesionOutput(
            $usuario->getId(), $usuario->getNombre(), $usuario->getApellidos(), $usuario->getEmail(), $membresias,
            count($membresias) === 1 ? $membresias[0]->establecimiento->id : null,
        );
        // JSON con lista explícita de campos, sin normalizar entidades ni resolver sus IRI.
        $response = new JsonResponse($contexto, 200, ['Cache-Control' => 'private, no-store']);
        $response->setVary('Authorization');
        return $response;
    }
}
