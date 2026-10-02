<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\LocationView;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * location.schema.json.
 */
final class LocationNormalizer implements NormalizerInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof LocationView);
        $location = $data->location;
        $range = $location->range;

        return [
            'target' => $data->target->value,
            'targetSha' => $location->targetSha->toString(),
            'status' => $location->status->value,
            'path' => $location->path?->toString(),
            'range' => null === $range ? null : ['startLine' => $range->startLine, 'endLine' => $range->endLine],
            'confidence' => $location->confidence,
            'reason' => $location->reason?->value,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof LocationView;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [LocationView::class => true];
    }
}
