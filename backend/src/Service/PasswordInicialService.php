<?php
declare(strict_types=1);
namespace App\Service;

use App\Dto\ConfigurarPasswordInput;
use App\Entity\{TokenConfiguracionPassword, Usuario};
use App\Exception\BusinessRuleException;
use App\Service\Support\{TransaccionAPPCC, ValidacionDominio};
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordInicialService
{
    public function __construct(private EntityManagerInterface $em, private TransaccionAPPCC $transaccion,
        private ValidacionDominio $validacion, private ClockInterface $clock, private UserPasswordHasherInterface $hasher) {}

    /** Se invoca dentro de la transacción de alta. El secreto solo vive en la respuesta inicial. @return array{string, \DateTimeImmutable} */
    public function emitir(Usuario $usuario): array
    {
        if (!$this->em->getConnection()->isTransactionActive()) { throw new \LogicException('La emisión requiere la transacción de alta.'); }
        if (!$usuario->isActivo() || $usuario->getPassword() !== null) { throw new BusinessRuleException('La cuenta no admite configuración inicial.'); }
        $token = bin2hex(random_bytes(32));
        $credencial = new TokenConfiguracionPassword($usuario, hash('sha256', $token), $this->clock->now());
        $this->em->persist($credencial);
        return [$token, $credencial->getExpiresAt()];
    }

    public function configurar(#[\SensitiveParameter] ConfigurarPasswordInput $input): void
    {
        $this->transaccion->ejecutar(function () use ($input): void {
            $db = $this->em->getConnection();
            $id = $db->fetchOne('SELECT id FROM token_configuracion_password WHERE token_hash = ? FOR UPDATE', [hash('sha256', $input->token)]);
            if ($id === false) { throw new BusinessRuleException('Token no válido o caducado.'); }
            $token = $this->em->find(TokenConfiguracionPassword::class, $id);
            $this->em->refresh($token);
            if ($token->getConsumedAt() !== null) { throw new ConflictHttpException('El token ya se ha utilizado.'); }
            if ($token->getExpiresAt() <= $this->clock->now()) { throw new BusinessRuleException('Token no válido o caducado.'); }
            $usuario = $token->getUsuario();
            $db->fetchOne('SELECT id FROM usuario WHERE id = ? FOR UPDATE', [$usuario->getId()]);
            $this->em->refresh($usuario);
            if (!$usuario->isActivo()) { throw new BusinessRuleException('La cuenta no admite esta operación.'); }
            // También impide sustituir la contraseña usando otro token de alta del mismo usuario.
            if ($usuario->getPassword() !== null) { throw new ConflictHttpException('La configuración inicial ya se ha completado.'); }
            $this->validacion->validar($input);
            $usuario->setPassword($this->hasher->hashPassword($usuario, $input->password));
            $token->consumir($this->clock->now());
        });
    }
}
