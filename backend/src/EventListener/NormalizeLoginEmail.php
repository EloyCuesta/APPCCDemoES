<?php
declare(strict_types=1);
namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

#[AsEventListener(event: 'kernel.request', priority: 16)]
final class NormalizeLoginEmail
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->getPathInfo() !== '/api/login_check' || !$request->isMethod('POST')) { return; }
        $data = $request->toArray();
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = \App\Service\Support\EmailUsuario::normalizar($data['email']);
            $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(), $request->cookies->all(), $request->files->all(), $request->server->all(), json_encode($data, JSON_THROW_ON_ERROR));
        }
    }
}
