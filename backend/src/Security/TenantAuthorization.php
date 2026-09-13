<?php
declare(strict_types=1);
namespace App\Security;

use App\Entity\{AccionCorrectiva, ConfiguracionEntidadFiscal, ConfiguracionEstablecimiento, EntidadFiscal, Establecimiento, Evidencia, HistorialIncidencia, Incidencia, PlanControl, PlantillaAPPCC, PuntoControl, RegistroAPPCC, TareaAPPCC, TareaProgramada, Usuario, UsuarioEstablecimiento};
use App\Enum\RolEstablecimiento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, NotFoundHttpException};

/** Política única para API, processors y servicios invocados desde una petición API. */
final readonly class TenantAuthorization
{
    public function __construct(private CurrentEstablecimientoContext $context, private EntityManagerInterface $em) {}

    public function assertGestionTareas(): void
    {
        if (!in_array($this->context->rol(), [RolEstablecimiento::ADMIN, RolEstablecimiento::RESPONSABLE], true)) {
            throw new AccessDeniedHttpException('El rol actual no permite gestionar ejecuciones.');
        }
    }

    /** Operaciones con DTO: el dominio valida solo las relaciones que la operación cambia. */
    public function assertGestionEjecucion(TareaProgramada $programada): void
    {
        if (!$this->context->isApiRequest()) { return; }
        $this->assertGestionTareas();
        $this->assertResource($programada);
        if ($programada->getTarea() !== null) { $this->assertResource($programada->getTarea()); }
    }

    public function assertReadClass(string $class): void
    {
        if ($class === PlantillaAPPCC::class) { $this->context->usuario(); return; }
        if ($this->context->rol() === RolEstablecimiento::TRABAJADOR
            && in_array($class, [EntidadFiscal::class, ConfiguracionEntidadFiscal::class, UsuarioEstablecimiento::class], true)) {
            throw new AccessDeniedHttpException('Este rol solo puede consultar la configuración operativa del establecimiento.');
        }
    }

    public function assertLocal(?Establecimiento $local): void
    {
        if (!$this->context->isApiRequest()) { return; }
        if ($local?->getId() !== $this->context->establecimiento()->getId()) { throw new NotFoundHttpException('Recurso no encontrado.'); }
    }

    public function assertResource(object $data): void
    {
        $local = $this->context->establecimiento();
        if ($data instanceof PlantillaAPPCC) { return; }
        if ($data instanceof Usuario) {
            $ok = $this->em->getRepository(UsuarioEstablecimiento::class)->count(['usuario' => $data, 'establecimiento' => $local, 'activo' => true]) > 0;
        } elseif ($data instanceof EntidadFiscal) { $ok = $data->getId() === $local->getEntidadFiscal()->getId(); }
        elseif ($data instanceof ConfiguracionEntidadFiscal) { $ok = $data->getEntidadFiscal()?->getId() === $local->getEntidadFiscal()->getId(); }
        elseif ($data instanceof Establecimiento) { $ok = $data->getId() === $local->getId(); }
        elseif ($data instanceof Evidencia) {
            $ok = ($data->getRegistro() !== null) !== ($data->getIncidencia() !== null);
            if ($ok) { $this->assertResource($data->getRegistro() ?? $data->getIncidencia()); }
        } elseif ($data instanceof AccionCorrectiva || $data instanceof HistorialIncidencia) {
            $ok = $data->getIncidencia() !== null;
            if ($ok) { $this->assertResource($data->getIncidencia()); }
        } elseif (method_exists($data, 'getEstablecimiento')) { $ok = $data->getEstablecimiento()?->getId() === $local->getId(); }
        else { $ok = false; }
        if (!$ok) { throw new NotFoundHttpException('Recurso no encontrado en el establecimiento seleccionado.'); }
    }

    public function assertWrite(object $data): void
    {
        if (!$this->context->isApiRequest()) { return; }
        $role = $this->context->rol();
        $managers = [RolEstablecimiento::ADMIN, RolEstablecimiento::RESPONSABLE];
        $operators = [...$managers, RolEstablecimiento::TRABAJADOR];
        $allowed = match (true) {
            $data instanceof UsuarioEstablecimiento => $role === RolEstablecimiento::ADMIN,
            $data instanceof RegistroAPPCC, $data instanceof AccionCorrectiva, $data instanceof Evidencia => in_array($role, $operators, true),
            $data instanceof Incidencia => in_array($role, $data->getId() === null ? $operators : $managers, true),
            $data instanceof HistorialIncidencia, $data instanceof Usuario, $data instanceof PlantillaAPPCC => false,
            $data instanceof ConfiguracionEntidadFiscal, $data instanceof ConfiguracionEstablecimiento,
            $data instanceof EntidadFiscal, $data instanceof Establecimiento, $data instanceof PlanControl,
            $data instanceof PuntoControl, $data instanceof TareaAPPCC, $data instanceof TareaProgramada => in_array($role, $managers, true),
            default => false,
        };
        if (!$allowed) { throw new AccessDeniedHttpException('El rol actual no permite esta operación.'); }
        $this->assertResource($data);
        $meta = $this->em->getClassMetadata($data::class);
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($data);
        foreach ($meta->associationMappings as $field => $mapping) {
            if (!$mapping->isToOneOwningSide()) { continue; }
            $related = $meta->getFieldValue($data, $field);
            if ($related !== null) { $this->assertResource($related); }
            if (array_key_exists($field, $original) && in_array($field, ['establecimiento', 'entidadFiscal', 'registro', 'incidencia', 'tareaProgramada'], true)
                && $original[$field] !== $related) {
                throw new AccessDeniedHttpException('No se puede cambiar el origen de un recurso existente.');
            }
        }
    }
}
