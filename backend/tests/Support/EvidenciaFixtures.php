<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class EvidenciaFixtures
{
    public static function archivo(string $extension = 'png', ?string $mime = null, ?string $nombre = null): UploadedFile
    {
        return new UploadedFile(dirname(__DIR__).'/Fixtures/evidencia.'.$extension, $nombre ?? 'control.'.$extension,
            $mime ?? match ($extension) { 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' }, test: true);
    }
}
