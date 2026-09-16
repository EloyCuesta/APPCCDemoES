<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Storage\EvidenciaStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:evidencias:verificar', description: 'Detecta archivos ausentes, huérfanos y daños; no modifica históricos.')]
final class VerificarEvidenciasCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly EvidenciaStorageInterface $storage, private readonly LoggerInterface $logger) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->em->getConnection();
        $errores = 0;
        foreach ($db->iterateAssociative('SELECT id, storage_key, tamano_bytes, hash_sha256 FROM evidencia') as $fila) {
            try { $valido = $fila['hash_sha256'] !== null && $this->storage->verificar($fila['storage_key'], (int) $fila['tamano_bytes'], $fila['hash_sha256']); }
            catch (\App\Exception\EvidenciaStorageException) { $valido = false; }
            if (!$valido) {
                ++$errores;
                $output->writeln('Evidencia '.$fila['id'].': archivo ausente, no verificable o tamaño/hash incorrecto.');
                $this->logger->error('Inconsistencia de evidencia.', ['evidencia_id' => $fila['id']]);
            }
        }
        $huerfanos = 0;
        foreach ($this->storage->listar('definitivo') as $clave) {
            if (!$db->fetchOne('SELECT 1 FROM evidencia WHERE storage_key = ?', [$clave])) { ++$huerfanos; }
        }
        $output->writeln('Evidencias inconsistentes: '.$errores.'. Archivos definitivos sin fila: '.$huerfanos.'. No se ha borrado información.');
        return $errores + $huerfanos === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
