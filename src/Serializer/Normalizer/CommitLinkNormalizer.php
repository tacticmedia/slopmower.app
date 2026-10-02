<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\CommitLinkView;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * commit-link.schema.json.
 */
final class CommitLinkNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof CommitLinkView);
        $link = $data->link;

        $normalized = $this->normalizer->normalize([
            'commitSha' => $link->getCommitSha(),
            'state' => $link->getState(),
            'linkedBy' => $link->getLinkedBy(),
            'linkedAt' => $link->getLinkedAt(),
            'note' => $link->getNote(),
            'foundAt' => $link->getFoundAt(),
            'subject' => $link->getSubject(),
            'committedAt' => $link->getCommittedAt(),
            'otherRequestIds' => $data->otherRequestIds,
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CommitLinkView;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [CommitLinkView::class => true];
    }
}
