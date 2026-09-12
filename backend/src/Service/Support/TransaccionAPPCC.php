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

        return $this->em->wrapInTransaction($operation);
    }
}
