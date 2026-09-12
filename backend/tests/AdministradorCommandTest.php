<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\{Usuario, UsuarioEstablecimiento};
use App\Enum\RolEstablecimiento;
use App\Tests\Support\PostgresTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdministradorCommandTest extends PostgresTestCase
{
    public function testCreaUsuarioConHashYMembresiaSinImprimirPassword(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped(
                'Symfony CommandTester no permite probar preguntas ocultas con askHidden() en Windows.'
            );
        }

        $password = bin2hex(random_bytes(18));

        $application = new Application(self::$kernel);

        $command = new CommandTester(
            $application->find('app:usuario:administrador')
        );

        $command->setInputs([$password]);

        $code = $command->execute(
            [
                'email' => 'NUEVO@example.com',
                'establecimiento' => (string) $this->local->getId(),
                '--nombre' => 'Nuevo',
                '--apellidos' => 'Administrador',
            ],
            [
                'interactive' => true,
            ]
        );

        self::assertSame(
            0,
            $code,
            str_replace($password, '[redactado]', $command->getDisplay())
        );

        self::assertFalse(
            str_contains($command->getDisplay(), $password)
        );

        $user = $this->em
            ->getRepository(Usuario::class)
            ->findOneBy(['email' => 'nuevo@example.com']);

        self::assertNotNull($user);

        self::assertTrue(
            self::getContainer()
                ->get(UserPasswordHasherInterface::class)
                ->isPasswordValid($user, $password)
        );

        self::assertSame(
            ['ROLE_USER'],
            $user->getRoles()
        );

        $membresia = $this->em
            ->getRepository(UsuarioEstablecimiento::class)
            ->findOneBy([
                'usuario' => $user,
                'establecimiento' => $this->local,
            ]);

        self::assertNotNull($membresia);

        self::assertSame(
            RolEstablecimiento::ADMIN,
            $membresia->getRol()
        );
    }
}
