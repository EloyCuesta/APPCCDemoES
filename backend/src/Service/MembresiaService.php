<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Establecimiento, TareaProgramada, Usuario, UsuarioEstablecimiento};
use App\Enum\RolEstablecimiento;
use App\Exception\BusinessRuleException;
use App\Security\TenantAuthorization;
use App\Service\Support\TransaccionAPPCC;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\{ConflictHttpException, NotFoundHttpException};

final readonly class MembresiaService
{
    public function __construct(private EntityManagerInterface $em, private TransaccionAPPCC $transaccion,
        private TenantAuthorization $authorization, private ClockInterface $clock) {}

    public function baja(int $id, Establecimiento $local, Usuario $autor): UsuarioEstablecimiento
    { return $this->cambiar($id, $local, $autor, 'baja'); }

    public function reactivar(int $id, Establecimiento $local, Usuario $autor): UsuarioEstablecimiento
    { return $this->cambiar($id, $local, $autor, 'reactivar'); }

    public function cambiarRol(int $id, Establecimiento $local, Usuario $autor, RolEstablecimiento $rol): UsuarioEstablecimiento
    { return $this->cambiar($id, $local, $autor, 'rol', $rol); }

    private function cambiar(int $id, Establecimiento $local, Usuario $autor, string $accion, ?RolEstablecimiento $rol = null): UsuarioEstablecimiento
    {
        $this->authorization->assertGestionUsuarios($local, $autor);
        return $this->transaccion->ejecutar(function () use ($id, $local, $autor, $accion, $rol): UsuarioEstablecimiento {
            $db = $this->em->getConnection();
            // Serializa decisiones sobre ADMIN y revalida también al autor tras esperar.
            $db->fetchOne('SELECT id FROM establecimiento WHERE id = ? FOR NO KEY UPDATE', [$local->getId()]);
            $this->authorization->assertGestionUsuarios($local, $autor);
            $usuarioId = $db->fetchOne('SELECT usuario_id FROM usuario_establecimiento WHERE id = ? AND establecimiento_id = ?', [$id, $local->getId()]);
            if ($usuarioId === false) { throw new NotFoundHttpException('Membresía no encontrada.'); }
            if ($accion === 'baja') {
                // Asignar/omitir ya bloquean ejecución antes de membresía. Mantener ese orden para las asignaciones existentes.
                $db->executeQuery("SELECT id FROM tarea_programada WHERE establecimiento_id = ? AND asignado_a_id = ? AND estado IN ('pendiente', 'vencida') ORDER BY id FOR UPDATE", [$local->getId(), $usuarioId])->fetchFirstColumn();
            }
            $db->fetchOne('SELECT id FROM usuario WHERE id = ? FOR SHARE', [$usuarioId]);
            $db->fetchOne('SELECT id FROM usuario_establecimiento WHERE id = ? AND establecimiento_id = ? FOR UPDATE', [$id, $local->getId()]);
            $member = $this->em->find(UsuarioEstablecimiento::class, $id);
            $this->em->refresh($member);
            $this->em->refresh($member->getUsuario());
            if ($accion === 'baja' && !$member->isActivo()) { throw new ConflictHttpException('La membresía ya está inactiva.'); }
            if ($accion === 'reactivar') {
                if ($member->isActivo()) { throw new ConflictHttpException('La membresía ya está activa.'); }
                if (!$member->getUsuario()->isActivo()) { throw new BusinessRuleException('El usuario global está inactivo.'); }
            }
            if ($member->isActivo() && $member->getRol() === RolEstablecimiento::ADMIN
                && ($accion === 'baja' || ($accion === 'rol' && $rol !== RolEstablecimiento::ADMIN))) {
                $admins = (int) $db->fetchOne("SELECT count(*) FROM usuario_establecimiento m JOIN usuario u ON u.id = m.usuario_id WHERE m.establecimiento_id = ? AND m.activo AND m.rol = 'admin' AND u.activo", [$local->getId()]);
                if ($admins <= 1) { throw new BusinessRuleException('El establecimiento debe conservar al menos un ADMIN activo.'); }
            }
            if ($accion === 'rol') { $member->setRol($rol); }
            else { $member->setActivo($accion === 'reactivar'); }
            if ($accion === 'baja') {
                // Una sola actualización condicional: preserva COMPLETADA/OMITIDA y sus referencias.
                $db->executeStatement("UPDATE tarea_programada SET asignado_a_id = NULL, updated_at = ? WHERE establecimiento_id = ? AND asignado_a_id = ? AND estado IN ('pendiente', 'vencida')", [$this->clock->now()->format('Y-m-d H:i:s'), $local->getId(), $usuarioId]);
                // Sin hidratar la agenda: sincronizar únicamente entidades que el llamador ya tenga cargadas.
                foreach ($this->em->getUnitOfWork()->getIdentityMap()[TareaProgramada::class] ?? [] as $programada) {
                    if ($programada->getEstablecimiento()?->getId() === $local->getId() && $programada->getAsignadoA()?->getId() === (int) $usuarioId) {
                        $this->em->refresh($programada);
                    }
                }
            }
            return $member;
        });
    }
}
