<?php
declare(strict_types=1);
namespace App\Security;

use App\Entity\{Establecimiento, Usuario, UsuarioEstablecimiento};
use App\Enum\RolEstablecimiento;
use App\Repository\{EstablecimientoRepository, UsuarioEstablecimientoRepository};
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, BadRequestHttpException, UnauthorizedHttpException};

final readonly class CurrentEstablecimientoContext
{
    public function __construct(private Security $security, private RequestStack $requests, private EstablecimientoRepository $locales, private UsuarioEstablecimientoRepository $membresias) {}

    public function isApiRequest(): bool
    {
        return str_starts_with($this->requests->getMainRequest()?->getPathInfo() ?? '', '/api');
    }

    public function usuario(): Usuario
    {
        $user = $this->security->getUser();
        if (!$user instanceof Usuario || !$user->isActivo()) { throw new UnauthorizedHttpException('Bearer', 'Se requiere un usuario activo autenticado.'); }
        return $user;
    }

    public function membresia(): UsuarioEstablecimiento
    {
        $user = $this->usuario();
        $request = $this->requests->getMainRequest();
        $cached = $request?->attributes->get('_appcc_membresia');
        if ($cached instanceof UsuarioEstablecimiento) { return $cached; }
        $raw = $request?->headers->get('X-Establecimiento-Id');
        $id = is_string($raw) && preg_match('/^[1-9][0-9]*$/D', $raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) { throw new BadRequestHttpException('X-Establecimiento-Id debe ser un entero positivo válido.'); }
        $local = $this->locales->find($id);
        $member = $local === null ? null : $this->membresias->findActiveMembership($user, $local);
        if ($local === null || !$local->isActivo() || !$local->getEntidadFiscal()?->isActivo() || $member === null) {
            throw new AccessDeniedHttpException('No hay una membresía activa para el establecimiento seleccionado.');
        }
        // Capturar el rol antes de deserializar PATCH (incluida la propia membresía).
        $snapshot = clone $member;
        $request->attributes->set('_appcc_membresia', $snapshot);
        return $snapshot;
    }

    public function establecimiento(): Establecimiento { return $this->membresia()->getEstablecimiento(); }
    public function rol(): RolEstablecimiento { return $this->membresia()->getRol(); }
}
