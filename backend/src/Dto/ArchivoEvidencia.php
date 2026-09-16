<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ArchivoEvidencia
{
    public function __construct(
        public string $storageKey,
        public string $nombreOriginal,
        public string $mimeType,
        public int $tamanoBytes,
        public string $hashSha256,
    ) {}
}
