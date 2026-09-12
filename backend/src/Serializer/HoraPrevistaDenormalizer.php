<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[AutoconfigureTag('serializer.normalizer', ['priority' => 100])]
final class HoraPrevistaDenormalizer implements DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): \DateTimeImmutable
    {
        $hora = is_string($data) && preg_match('/^\d{2}:\d{2}:\d{2}$/D', $data) === 1 ? \DateTimeImmutable::createFromFormat('!H:i:s', $data) : false;
        if ($hora === false || $hora->format('H:i:s') !== $data) {
            throw new NotNormalizableValueException('La hora prevista debe usar HH:MM:SS entre 00:00:00 y 23:59:59.');
        }
        return $hora;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === \DateTimeImmutable::class && ($context['appcc_hora'] ?? false) === true;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [\DateTimeImmutable::class => false];
    }
}
