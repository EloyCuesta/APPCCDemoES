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
        $password = bin2hex(random_bytes(18));
        if (\PHP_OS_FAMILY === 'Windows') {
            // CommandTester no admite askHidden() en Windows. Probar la entrada segura real por stdin.
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'bin/console', 'app:usuario:administrador', 'NUEVO@example.com', (string) $this->local->getId(),
                '--nombre=Nuevo', '--apellidos=Administrador', '--password-stdin', '--no-interaction',
            ], dirname(__DIR__), ['APP_ENV' => 'test'], $password."\n");
            $code = $process->run();
            $display = $process->getOutput().$process->getErrorOutput();
        } else {
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
            $display = $command->getDisplay();
        }

        self::assertSame(
            0,
            $code,
            str_replace($password, '[redactado]', $display)
        );

        self::assertFalse(
            str_contains($display, $password)
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
