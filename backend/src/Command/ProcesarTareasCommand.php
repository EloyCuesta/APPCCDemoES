<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CicloOperativoTareasService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tareas:procesar', description: 'Generar tareas recurrentes y detectar vencimientos en todos los establecimientos activos.')]
final class ProcesarTareasCommand extends Command
{
    public function __construct(private readonly CicloOperativoTareasService $ciclo) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('horizonte-dias', null, InputOption::VALUE_REQUIRED, 'Días futuros (1–366); recupera también ayer y hoy.', '7')
            ->addOption('desde', null, InputOption::VALUE_REQUIRED, 'Inicio inclusivo: YYYY-MM-DD o instante ISO con zona.')
            ->addOption('hasta', null, InputOption::VALUE_REQUIRED, 'Fin inclusivo, con el mismo formato que --desde.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emitir el resumen estructurado para supervisión.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $horizonte = filter_var($input->getOption('horizonte-dias'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
            if ($horizonte === false) { throw new \InvalidArgumentException('--horizonte-dias debe ser un entero entre 1 y 366.'); }
            $r = $this->ciclo->procesar($horizonte, $input->getOption('desde') ?: null, $input->getOption('hasta') ?: null);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage()); return Command::INVALID;
        } catch (\Throwable $e) {
            $io->error($e->getMessage()); return Command::FAILURE;
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode($r, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        } else {
            $io->table(['Métrica', 'Total'], [
                ['Tareas analizadas', $r->tareasAnalizadas], ['Programaciones creadas', $r->creadas],
                ['Programaciones existentes', $r->existentes], ['Ignoradas', $r->ignoradas], ['Marcadas como vencidas', $r->vencidas], ['Errores', count($r->errores)],
            ]);
            foreach ($r->ignoradasDetalle as $detalle) { $io->warning($detalle); }
            foreach ($r->errores as $error) { $io->error(sprintf('%s, tarea %s, establecimiento %s: %s (%s)', $error['fase'], $error['tarea'] ?? '-', $error['establecimiento'] ?? '-', $error['mensaje'], $error['tipo'])); }
        }
        return $r->errores === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
