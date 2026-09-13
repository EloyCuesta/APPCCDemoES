<?php
declare(strict_types=1);
namespace App\Service;

use App\Dto\{AceptarInvitacionInput, AltaUsuarioOutput, CrearInvitacionInput, InvitacionCreadaOutput};
use App\Entity\{Establecimiento, InvitacionUsuario, Usuario, UsuarioEstablecimiento};
use App\Enum\EstadoInvitacion;
use App\Exception\BusinessRuleException;
use App\Security\TenantAuthorization;
use App\Service\Support\{EmailUsuario, TransaccionAPPCC, ValidacionDominio};
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\{ConflictHttpException, NotFoundHttpException};

final readonly class InvitacionUsuarioService
{
    public function __construct(private EntityManagerInterface $em, private TransaccionAPPCC $transaccion,
        private TenantAuthorization $authorization, private ValidacionDominio $validacion,
        private PasswordInicialService $passwords, private ClockInterface $clock) {}

    public function crear(Establecimiento $local, Usuario $autor, CrearInvitacionInput $input): InvitacionCreadaOutput
    {
        $this->authorization->assertGestionUsuarios($local, $autor);
        $input->email = EmailUsuario::normalizar($input->email);
        $this->validacion->validar($input);
        [$invitacion, $token] = $this->transaccion->ejecutar(function () use ($local, $autor, $input): array {
            $db = $this->em->getConnection();
            $db->fetchOne('SELECT id FROM establecimiento WHERE id = ? FOR NO KEY UPDATE', [$local->getId()]);
            $this->authorization->assertGestionUsuarios($local, $autor);
            if ($db->fetchOne('SELECT m.id FROM usuario_establecimiento m JOIN usuario u ON u.id = m.usuario_id WHERE m.establecimiento_id = ? AND u.email = ? AND m.activo', [$local->getId(), $input->email]) !== false) {
                throw new ConflictHttpException('Ya existe una membresía activa.');
            }
            // Expirar antes de INSERT para liberar el índice parcial, incluso en el mismo flush.
            $db->executeStatement("UPDATE invitacion_usuario SET estado = 'expirada' WHERE establecimiento_id = ? AND email = ? AND estado = 'pendiente' AND expires_at <= ?", [$local->getId(), $input->email, $this->clock->now()->format('Y-m-d H:i:s')]);
            if ($db->fetchOne("SELECT id FROM invitacion_usuario WHERE establecimiento_id = ? AND email = ? AND estado = 'pendiente'", [$local->getId(), $input->email]) !== false) {
                throw new ConflictHttpException('Ya existe una invitación pendiente.');
            }
            $token = bin2hex(random_bytes(32));
            $invitacion = new InvitacionUsuario($local, $input->email, $input->rol, hash('sha256', $token), $autor, $this->clock->now());
            $this->em->persist($invitacion);
            return [$invitacion, $token];
        });
        return new InvitacionCreadaOutput($invitacion->getId(), $invitacion->getEmail(), $invitacion->getRol()->value, $token, $invitacion->getExpiresAt());
    }

    public function aceptar(#[\SensitiveParameter] AceptarInvitacionInput $input): AltaUsuarioOutput
    {
        $this->validacion->validar($input);
        [$usuario, $membresia, $token, $expires] = $this->transaccion->ejecutar(function () use ($input): array {
            $db = $this->em->getConnection();
            // Lectura solo para determinar el orden de bloqueos: local -> invitación -> identidad -> membresía.
            $fila = $db->fetchAssociative('SELECT id, establecimiento_id FROM invitacion_usuario WHERE token_hash = ?', [hash('sha256', $input->token)]);
            if ($fila === false) { throw new BusinessRuleException('Invitación no válida o caducada.'); }
            $db->fetchOne('SELECT id FROM establecimiento WHERE id = ? FOR NO KEY UPDATE', [$fila['establecimiento_id']]);
            $db->fetchOne('SELECT id FROM invitacion_usuario WHERE id = ? FOR UPDATE', [$fila['id']]);
            $invitacion = $this->em->find(InvitacionUsuario::class, $fila['id']);
            $this->em->refresh($invitacion);
            $this->assertPendiente($invitacion);
            $local = $invitacion->getEstablecimiento();
            $this->em->refresh($local);
            $fiscal = $local->getEntidadFiscal();
            $db->fetchOne('SELECT id FROM entidad_fiscal WHERE id = ? FOR SHARE', [$fiscal->getId()]);
            $this->em->refresh($fiscal);
            if (!$local->isActivo() || !$fiscal->isActivo()) { throw new BusinessRuleException('El establecimiento no admite altas.'); }
            $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['identidad:'.$invitacion->getEmail()]);
            $usuario = $this->em->getRepository(Usuario::class)->findOneBy(['email' => $invitacion->getEmail()]);
            if ($usuario === null) {
                $usuario = (new Usuario())->setEmail($invitacion->getEmail())->setNombre($input->nombre)->setApellidos($input->apellidos)->setCreatedAt($this->clock->now());
                $this->validacion->validar($usuario);
                $this->em->persist($usuario);
            } else {
                $db->fetchOne('SELECT id FROM usuario WHERE id = ? FOR UPDATE', [$usuario->getId()]);
                $this->em->refresh($usuario);
                if (!$usuario->isActivo()) { throw new BusinessRuleException('La cuenta no admite esta operación.'); }
            }
            $membresia = null;
            if ($usuario->getId() !== null) {
                $id = $db->fetchOne('SELECT id FROM usuario_establecimiento WHERE usuario_id = ? AND establecimiento_id = ? FOR UPDATE', [$usuario->getId(), $local->getId()]);
                if ($id !== false) { $membresia = $this->em->find(UsuarioEstablecimiento::class, $id); $this->em->refresh($membresia); }
            }
            if ($membresia?->isActivo()) { throw new ConflictHttpException('Ya existe una membresía activa.'); }
            $membresia ??= (new UsuarioEstablecimiento())->setUsuario($usuario)->setEstablecimiento($local);
            $membresia->setActivo(true)->setRol($invitacion->getRol());
            $this->em->persist($membresia);
            $invitacion->aceptar($this->clock->now());
            [$token, $expires] = $usuario->getPassword() === null ? $this->passwords->emitir($usuario) : [null, null];
            return [$usuario, $membresia, $token, $expires];
        });
        return new AltaUsuarioOutput($usuario->getId(), $membresia->getEstablecimiento()->getId(), $membresia->getId(), $token, $expires);
    }

    public function cancelar(int $id, Establecimiento $local, Usuario $autor): InvitacionUsuario
    {
        $this->authorization->assertGestionUsuarios($local, $autor);
        return $this->transaccion->ejecutar(function () use ($id, $local, $autor): InvitacionUsuario {
            $db = $this->em->getConnection();
            $db->fetchOne('SELECT id FROM establecimiento WHERE id = ? FOR NO KEY UPDATE', [$local->getId()]);
            $this->authorization->assertGestionUsuarios($local, $autor);
            if ($db->fetchOne('SELECT id FROM invitacion_usuario WHERE id = ? AND establecimiento_id = ? FOR UPDATE', [$id, $local->getId()]) === false) {
                throw new NotFoundHttpException('Invitación no encontrada.');
            }
            $invitacion = $this->em->find(InvitacionUsuario::class, $id);
            $this->em->refresh($invitacion);
            $this->assertPendiente($invitacion);
            $invitacion->cancelar($this->clock->now());
            return $invitacion;
        });
    }

    private function assertPendiente(InvitacionUsuario $invitacion): void
    {
        if ($invitacion->getEstado() === EstadoInvitacion::EXPIRADA) { throw new BusinessRuleException('Invitación no válida o caducada.'); }
        if ($invitacion->getEstado() !== EstadoInvitacion::PENDIENTE) { throw new ConflictHttpException('La invitación ya no está pendiente.'); }
        if ($invitacion->getExpiresAt() <= $this->clock->now()) { throw new BusinessRuleException('Invitación no válida o caducada.'); }
    }
}
