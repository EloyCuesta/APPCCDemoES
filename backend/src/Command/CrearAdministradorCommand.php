<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\{Usuario, UsuarioEstablecimiento};
use App\Enum\RolEstablecimiento;
use App\Repository\{EstablecimientoRepository, UsuarioRepository, UsuarioEstablecimientoRepository};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:usuario:administrador', description: 'Crear o habilitar credenciales y membresía ADMIN en un establecimiento existente.')]
final class CrearAdministradorCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly EstablecimientoRepository $locales, private readonly UsuarioRepository $usuarios, private readonly UsuarioEstablecimientoRepository $membresias, private readonly UserPasswordHasherInterface $hasher, private readonly ValidatorInterface $validator) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('establecimiento', InputArgument::REQUIRED)
            ->addOption('nombre', null, InputOption::VALUE_REQUIRED)->addOption('apellidos', null, InputOption::VALUE_REQUIRED)
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Leer la contraseña desde stdin; nunca como argumento.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = filter_var($input->getArgument('establecimiento'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $local = $id === false ? null : $this->locales->find($id);
        if ($local === null || !$local->isActivo() || !$local->getEntidadFiscal()?->isActivo()) { $io->error('Establecimiento o entidad fiscal no disponibles.'); return Command::FAILURE; }
        $email = strtolower(trim($input->getArgument('email')));
        $user = $this->usuarios->findOneBy(['email' => $email]);
        if ($user === null) {
            $nombre = $input->getOption('nombre') ?? ($input->isInteractive() ? $io->ask('Nombre') : '');
            $apellidos = $input->getOption('apellidos') ?? ($input->isInteractive() ? $io->ask('Apellidos') : '');
            $user = (new Usuario())->setEmail($email)->setNombre($nombre ?? '')->setApellidos($apellidos ?? '');
        }
        if (!$input->getOption('password-stdin') && !$input->isInteractive()) { $io->error('Se requiere entrada oculta interactiva o --password-stdin.'); return Command::INVALID; }
        $password = $input->getOption('password-stdin') ? rtrim(stream_get_contents(STDIN), "\r\n") : $io->askHidden('Contraseña (mínimo 12 caracteres)');
        if (!is_string($password) || strlen($password) < 12) { $io->error('La contraseña debe contener al menos 12 caracteres.'); return Command::INVALID; }
        $user->setPassword($this->hasher->hashPassword($user, $password));
        unset($password);
        if (count($this->validator->validate($user)) > 0) { $io->error('Revise email, nombre y apellidos.'); return Command::INVALID; }
        $this->em->wrapInTransaction(function () use ($user, $local): void {
            $this->em->persist($user);
            $member = $user->getId() === null ? null : $this->membresias->findOneBy(['usuario' => $user, 'establecimiento' => $local]);
            $member ??= (new UsuarioEstablecimiento())->setUsuario($user)->setEstablecimiento($local);
            $member->setActivo(true)->setRol(RolEstablecimiento::ADMIN);
            $this->em->persist($member);
        });
        $io->success('Credenciales guardadas y membresía ADMIN asignada. Se conserva el estado activo/inactivo de la cuenta.');
        return Command::SUCCESS;
    }
}
