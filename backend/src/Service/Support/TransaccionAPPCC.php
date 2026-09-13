<?php

declare(strict_types=1);

namespace App\Service\Support;

use Doctrine\ORM\EntityManagerInterface;

final readonly class TransaccionAPPCC
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @template T @param callable(): T $operation @return T */
    public function ejecutar(callable $operation): mixed
    {
        // El caso de uso exterior es el único que hace flush/commit.
        if ($this->em->getConnection()->isTransactionActive()) {
            return $operation();
        }

        try {
            return $this->em->wrapInTransaction($operation);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Incluye el flush exterior de incidencias automáticas anidadas.
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('La operación entra en conflicto con otro registro existente o concurrente.', $e);
        } catch (\Doctrine\DBAL\Exception\RetryableException|\Doctrine\ORM\OptimisticLockException $e) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('La operación ha encontrado un conflicto concurrente. Recarga el recurso y vuelve a intentarlo.', $e);
        }
    }
}
