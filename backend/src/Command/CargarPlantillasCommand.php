<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PlantillaAPPCCService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:plantillas:cargar', description: 'Carga el catálogo inicial versionado sin duplicar ni sobrescribir plantillas.')]
final class CargarPlantillasCommand extends Command
{
    public function __construct(private readonly PlantillaAPPCCService $service) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->service->cargarIniciales() as $plantilla) {
            $output->writeln(sprintf('%s: /api/plantillas-appcc/%d', $plantilla->getCodigo(), $plantilla->getId()));
        }
        return Command::SUCCESS;
    }
}
