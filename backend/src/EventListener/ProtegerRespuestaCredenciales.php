<?php
declare(strict_types=1);
namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/** Las respuestas iniciales con secretos no deben almacenarse en cachés de clientes o proxies. */
#[AsEventListener(event: 'kernel.response', priority: -32)]
final class ProtegerRespuestaCredenciales
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !in_array($request->getPathInfo(), [
            '/api/onboarding', '/api/invitaciones', '/api/invitaciones/aceptar', '/api/auth/configurar-password',
        ], true)) { return; }
        $response = $event->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
    }
}
