<?php

declare(strict_types=1);

namespace App\Serializer\Normalizer;

use App\Application\Query\CommitDiffFileView;
use App\Application\Query\CommitDiffView;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * commit-diff.schema.json.
 */
final class CommitDiffNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof CommitDiffView);
        $commit = $data->commit;
        $normalized = $this->normalizer->normalize([
            'requestId' => $data->requestId,
            'commitSha' => $commit->sha,
            'parentSha' => $commit->parentSha,
            'subject' => $commit->subject,
            'message' => $commit->message,
            'authorName' => $commit->authorName,
            'authorEmail' => $commit->authorEmail,
            'committedAt' => $commit->committedAt,
            'location' => $data->location,
            'files' => array_map(self::file(...), $data->files),
            'truncated' => $data->truncated,
        ], $format, $context);
        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CommitDiffView;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [CommitDiffView::class => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function file(CommitDiffFileView $file): array
    {
        $change = $file->change;

        return [
            'status' => $change->status,
            'path' => $file->path,
            'oldPath' => $file->oldPath,
            'oldBlobSha' => $change->oldObjectId,
            'newBlobSha' => $change->newObjectId,
            'oldMode' => $change->oldMode,
            'newMode' => $change->newMode,
            'additions' => $change->additions,
            'deletions' => $change->deletions,
            'binary' => $change->isBinary(),
            'tooLarge' => $file->tooLarge,
            'touchesAnchor' => $file->touchesAnchor,
        ];
    }
}
