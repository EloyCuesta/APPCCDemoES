<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SubidaEvidenciaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:evidencias:limpiar-temporales', description: 'Elimina subidas caducadas sin borrar evidencias históricas.')]
final class LimpiarTemporalesEvidenciaCommand extends Command
{
    public function __construct(private readonly SubidaEvidenciaService $subidas) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Subidas temporales eliminadas: '.$this->subidas->limpiarCaducadas());
        return Command::SUCCESS;
    }
}
