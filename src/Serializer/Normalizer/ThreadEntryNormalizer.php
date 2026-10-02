<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\EventView;
use App\Application\Query\ThreadEntry;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * thread-entry.schema.json: the Event without its review and request fields, with the status of the thread's request.
 */
final class ThreadEntryNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const array EVENT_ONLY_FIELDS = ['reviewId' => true, 'requestId' => true, 'requestIds' => true];

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof ThreadEntry);
        $event = $this->normalizer->normalize(new EventView($data->event, $data->statusAfter), $format, $context);
        \assert(\is_array($event));

        return array_diff_key($event, self::EVENT_ONLY_FIELDS);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof ThreadEntry;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [ThreadEntry::class => true];
    }
}
